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
				'pending' => is_array($pending) ? $pending : [],
			];
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: agent store load failed', ['exception' => $e]);
			return ['history' => [], 'pending' => []];
		}
	}

	public function save(string $userId, string $token, array $history, array $pending): void {
		if ($token === '' || $token === '{}' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $token)) {
			return;
		}
		$history = array_slice(array_values($history), -24);
		try {
			$now = time();
			$historyStr = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$pendingStr = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($historyStr === false) {
				$historyStr = '[]';
			}
			if ($pendingStr === false) {
				$pendingStr = '[]';
			}

			// Existiert der Eintrag? (prepared, CLI-sicher)
			$exists = false;
			try {
				$stmt = $this->db->prepare('SELECT id FROM *PREFIX*eva_ai_agent_state WHERE user_id = ? AND token = ? LIMIT 1');
				$stmt->execute([$userId, $token]);
				$row = $stmt->fetch();
				$exists = $row !== false && $row !== null;
			} catch (\Throwable $e) {
				// Fallback: direkter Insert-Versuch unten
			}

			if (!$exists) {
				$stmt = $this->db->prepare('INSERT INTO *PREFIX*eva_ai_agent_state (user_id, token, history, pending, updated_at) VALUES (?, ?, ?, ?, ?)');
				$stmt->execute([$userId, $token, $historyStr, $pendingStr, $now]);
				return;
			}

			$stmt = $this->db->prepare('UPDATE *PREFIX*eva_ai_agent_state SET history = ?, pending = ?, updated_at = ? WHERE user_id = ? AND token = ?');
			$stmt->execute([$historyStr, $pendingStr, $now, $userId, $token]);
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: agent store save failed', ['exception' => $e]);
		}
	}
}