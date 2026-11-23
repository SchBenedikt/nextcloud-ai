<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IDBConnection;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Persists per-conversation agent state (LLM history + pending actions)
 * encrypted in a dedicated DB table, keyed by the conversation token.
 *
 * IMPORTANT: In the CLI (taskprocessing:worker, occ) the QueryBuilder's
 * executeQuery()/executeStatement() with named parameters can block
 * indefinitely (known Nextcloud + MySQL issue). We therefore use plain
 * PDO prepared statements here — they are the only reliable path in
 * both web and CLI context.
 */
class AgentStore {
	private const PREFIX = "EVA-AGENT-1\n";

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @return array{history: array<int,array{role:string,content:string,attachments?:list<int>}>, pending: array<int,array{name:string,arguments:array}>}
	 */
	public function load(string $userId, string $token): array {
		if ($token === '' || $token === '{}' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $token)) {
			return ['history' => [], 'pending' => []];
		}
		try {
			$stmt = $this->db->prepare('SELECT history, pending FROM *PREFIX*eva_ai_agent_state WHERE user_id = ? AND token = ? LIMIT 1');
			$stmt->execute([$userId, $token]);
			$row = $stmt->fetch();
			if ($row === false || $row === null) {
				return ['history' => [], 'pending' => []];
			}
			$storedHistory = (string)($row['history'] ?? '');
			$storedPending = (string)($row['pending'] ?? '');
			$history = $this->decode($storedHistory);
			$pending = $this->decode($storedPending);
			// Upgrade legacy plaintext rows on read without racing a concurrent
			// proposal or claim. Do not pass stored plaintext back into the model
			// unless this migration succeeds or another worker already sealed it.
			if (!$this->isSealed($storedHistory) || !$this->isSealed($storedPending)) {
				if (!$this->compareAndSwap($userId, $token, $storedPending, $storedHistory, $history, $pending)) {
					$latest = $this->row($userId, $token);
					if ($latest !== null && (!$this->isSealed((string)$latest['history']) || !$this->isSealed((string)$latest['pending']))) {
						throw new \RuntimeException('Legacy agent state could not be encrypted safely.');
					}
				}
			}
			return [
				'history' => is_array($history) ? $history : [],
				'pending' => is_array($pending) && array_is_list($pending) ? $pending : [],
				'execution' => is_array($pending) && isset($pending['claim']) ? $pending : null,
			];
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: agent store load failed', ['exception' => $e]);
			throw new \RuntimeException('Unable to load agent state', 0, $e);
		}
	}

	/**
	 * Delete conversation states that have not been touched for the given
	 * number of seconds. Agent state contains prompts and pending actions, so
	 * retaining abandoned tokens forever is both a privacy and storage issue.
	 *
	 * @return int Number of deleted rows (best effort).
	 */
	public function purgeOlderThan(int $maxAgeSeconds = 2592000): int {
		$cutoff = time() - max(3600, $maxAgeSeconds);
		try {
			$stmt = $this->db->prepare('DELETE FROM *PREFIX*eva_ai_agent_state WHERE updated_at < ?');
			$stmt->execute([$cutoff]);
			return max(0, (int)$stmt->rowCount());
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: agent store purge failed', ['exception' => $e]);
			return 0;
		}
	}

	/** Remove every persisted conversation state belonging to a deleted user. */
	public function deleteUserData(string $userId): int {
		if ($userId === '') return 0;
		try {
			$stmt = $this->db->prepare('DELETE FROM *PREFIX*eva_ai_agent_state WHERE user_id = ?');
			$stmt->execute([$userId]);
			return max(0, (int)$stmt->rowCount());
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: agent state cleanup failed on account deletion', ['user' => $userId]);
			throw new \RuntimeException('Unable to erase account agent state.', 0, $e);
		}
	}

	/** Atomically replace exactly the pending proposal the user approved. */
	public function claim(string $userId, string $token, array $pending): ?array {
		if ($pending === []) {
			return null;
		}
		$row = $this->row($userId, $token);
		if ($row === null || $this->decode((string)$row['pending']) !== $pending) {
			return null;
		}
		$history = $this->decode((string)$row['history']);
		$receipt = [
			'claim' => bin2hex(random_bytes(16)), 'status' => 'in_progress',
			'started_at' => time(), 'results' => [],
			'output' => 'Execution was claimed. Its outcome has not been recorded; do not retry these actions automatically.',
		];
		return $this->compareAndSwap($userId, $token, (string)$row['pending'], (string)$row['history'], $history, $receipt)
			? $receipt : null;
	}

	/** Record a receipt without overwriting a newer proposal or another claim. */
	public function complete(string $userId, string $token, string $claim, array $history, array $results, string $output): void {
		$row = $this->row($userId, $token);
		$current = $row === null ? null : $this->decode((string)$row['pending']);
		if (!is_array($current) || ($current['claim'] ?? '') !== $claim) {
			throw new \RuntimeException('Agent execution state changed; receipt was not overwritten');
		}
		$current['status'] = 'completed';
		$current['completed_at'] = time();
		$current['results'] = $results;
		$current['output'] = $output;
		if (!$this->compareAndSwap($userId, $token, (string)$row['pending'], (string)$row['history'], array_slice($history, -24), $current)) {
			throw new \RuntimeException('Agent execution receipt could not be saved');
		}
	}

	public function save(string $userId, string $token, array $history, array $pending): void {
		if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $token)) {
			throw new \InvalidArgumentException('Invalid agent conversation token');
		}
		$history = array_slice(array_values($history), -24);
		$row = $this->row($userId, $token);
		if ($row === null) {
			$stmt = $this->db->prepare('INSERT INTO *PREFIX*eva_ai_agent_state (user_id, token, history, pending, updated_at) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute([$userId, $token, $this->seal($history), $this->seal($pending), time()]);
			return;
		}
		$current = $this->decode((string)$row['pending']);
		if (is_array($current) && isset($current['claim']) && ($current['status'] ?? '') !== 'completed') {
			throw new \RuntimeException('Previous actions have an unrecorded outcome; use a new conversation after checking their effects');
		}
		if (!$this->compareAndSwap($userId, $token, (string)$row['pending'], (string)$row['history'], $history, $pending)) {
			throw new \RuntimeException('Agent conversation changed concurrently; proposal was not saved');
		}
	}

	private function row(string $userId, string $token): ?array {
		$stmt = $this->db->prepare('SELECT history, pending FROM *PREFIX*eva_ai_agent_state WHERE user_id = ? AND token = ? LIMIT 1');
		$stmt->execute([$userId, $token]);
		$row = $stmt->fetch();
		return is_array($row) ? $row : null;
	}

	private function compareAndSwap(string $userId, string $token, string $previousPending, string $previousHistory, array $history, array $pending): bool {
		$stmt = $this->db->prepare('UPDATE *PREFIX*eva_ai_agent_state SET history = ?, pending = ?, updated_at = ? WHERE user_id = ? AND token = ? AND pending = ? AND history = ?');
		$stmt->execute([$this->seal($history), $this->seal($pending), time(), $userId, $token, $previousPending, $previousHistory]);
		return $stmt->rowCount() === 1;
	}

	private function seal(array $value): string {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		return self::PREFIX . $this->crypto->encrypt($json);
	}

	private function decode(string $value): array {
		$json = $this->isSealed($value)
			? $this->crypto->decrypt(substr($value, strlen(self::PREFIX)))
			: $value; // Read legacy plaintext rows once, then rewrite them encrypted.
		$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new \UnexpectedValueException('Stored agent state must be a JSON array or object.');
		}
		return $decoded;
	}

	private function isSealed(string $value): bool {
		return str_starts_with($value, self::PREFIX);
	}
}
