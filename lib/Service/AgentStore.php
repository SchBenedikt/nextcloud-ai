<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Persists per-conversation agent state (LLM history + pending actions)
 * in a dedicated DB table, keyed by the conversation token.
 *
 * IMPORTANT: In the CLI (taskprocessing:worker, occ) the QueryBuilder's
 * executeQuery()/executeStatement() with named parameters can block
 * indefinitely (known Nextcloud + MySQL issue). We therefore use plain
 * PDO prepared statements here — they are the only reliable path in
 * both web and CLI context.
 */
class AgentStore {

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{history: array<int,array{role:string,content:string}>, pending: array<int,array{name:string,arguments:array}>}
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
			$history = json_decode((string)($row['history'] ?? ''), true);
			$pending = json_decode((string)($row['pending'] ?? ''), true);
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

	/** Atomically replace exactly the pending proposal the user approved. */
	public function claim(string $userId, string $token, array $pending): ?array {
		if ($pending === []) {
			return null;
		}
		$row = $this->row($userId, $token);
		if ($row === null || json_decode((string)$row['pending'], true, 512, JSON_THROW_ON_ERROR) !== $pending) {
			return null;
		}
		$receipt = [
			'claim' => bin2hex(random_bytes(16)), 'status' => 'in_progress',
			'started_at' => time(), 'results' => [],
			'output' => 'Execution was claimed. Its outcome has not been recorded; do not retry these actions automatically.',
		];
		return $this->compareAndSwap($userId, $token, (string)$row['pending'], (string)$row['history'], $receipt)
			? $receipt : null;
	}

	/** Record a receipt without overwriting a newer proposal or another claim. */
	public function complete(string $userId, string $token, string $claim, array $history, array $results, string $output): void {
		$row = $this->row($userId, $token);
		$current = $row === null ? null : json_decode((string)$row['pending'], true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($current) || ($current['claim'] ?? '') !== $claim) {
			throw new \RuntimeException('Agent execution state changed; receipt was not overwritten');
		}
		$current['status'] = 'completed';
		$current['completed_at'] = time();
		$current['results'] = $results;
		$current['output'] = $output;
		if (!$this->compareAndSwap($userId, $token, (string)$row['pending'], $this->encode(array_slice($history, -24)), $current)) {
			throw new \RuntimeException('Agent execution receipt could not be saved');
		}
	}

	public function save(string $userId, string $token, array $history, array $pending): void {
		if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $token)) {
			throw new \InvalidArgumentException('Invalid agent conversation token');
		}
		$historyStr = $this->encode(array_slice(array_values($history), -24));
		$row = $this->row($userId, $token);
		if ($row === null) {
			$stmt = $this->db->prepare('INSERT INTO *PREFIX*eva_ai_agent_state (user_id, token, history, pending, updated_at) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute([$userId, $token, $historyStr, $this->encode($pending), time()]);
			return;
		}
		$current = json_decode((string)$row['pending'], true, 512, JSON_THROW_ON_ERROR);
		if (is_array($current) && isset($current['claim']) && ($current['status'] ?? '') !== 'completed') {
			throw new \RuntimeException('Previous actions have an unrecorded outcome; use a new conversation after checking their effects');
		}
		if (!$this->compareAndSwap($userId, $token, (string)$row['pending'], $historyStr, $pending)) {
			throw new \RuntimeException('Agent conversation changed concurrently; proposal was not saved');
		}
	}

	private function row(string $userId, string $token): ?array {
		$stmt = $this->db->prepare('SELECT history, pending FROM *PREFIX*eva_ai_agent_state WHERE user_id = ? AND token = ? LIMIT 1');
		$stmt->execute([$userId, $token]);
		$row = $stmt->fetch();
		return is_array($row) ? $row : null;
	}

	private function compareAndSwap(string $userId, string $token, string $previous, string $history, array $pending): bool {
		$stmt = $this->db->prepare('UPDATE *PREFIX*eva_ai_agent_state SET history = ?, pending = ?, updated_at = ? WHERE user_id = ? AND token = ? AND pending = ?');
		$stmt->execute([$history, $this->encode($pending), time(), $userId, $token, $previous]);
		return $stmt->rowCount() === 1;
	}

	private function encode(array $value): string {
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
