<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Persists per-conversation agent state (LLM history + pending actions)
 * in a dedicated DB table, keyed by the conversation token.
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
			$qb = $this->db->getQueryBuilder();
			$qb->select('history', 'pending')
				->from('eva_ai_agent_state')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)))
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return ['history' => [], 'pending' => []];
			}
			$history = json_decode((string)($row['history'] ?? ''), true);
			$pending = json_decode((string)($row['pending'] ?? ''), true);
			return [
				'history' => is_array($history) ? $history : [],
				'pending' => is_array($pending) ? $pending : [],
			];
		} catch (\Throwable $e) {
			$this->logger->warning('eva-ai: agent store load failed', ['exception' => $e]);
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
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('eva_ai_agent_state')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)))
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();

			$historyStr = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$pendingStr = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($row === false) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('eva_ai_agent_state')
					->values([
						'user_id' => $qb->createNamedParameter($userId),
						'token' => $qb->createNamedParameter($token),
						'history' => $qb->createNamedParameter($historyStr === false ? '[]' : $historyStr, IQueryBuilder::PARAM_STR),
						'pending' => $qb->createNamedParameter($pendingStr === false ? '[]' : $pendingStr, IQueryBuilder::PARAM_STR),
						'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					]);
				$qb->executeStatement();
				return;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->update('eva_ai_agent_state')
				->set('history', $qb->createNamedParameter($historyStr === false ? '[]' : $historyStr))
				->set('pending', $qb->createNamedParameter($pendingStr === false ? '[]' : $pendingStr))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->warning('eva-ai: agent store save failed', ['exception' => $e]);
		}
	}
}