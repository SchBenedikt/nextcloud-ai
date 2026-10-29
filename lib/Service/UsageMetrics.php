<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Records model usage without storing prompts, answers or tool arguments.
 * Provider token counts are used when available; otherwise the UI labels the
 * conservative character based estimate clearly.
 */
class UsageMetrics {
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ?TraceContext $traceContext = null,
	) {
	}

	public static function estimateTokens(string $text): int {
		return $text === '' ? 0 : max(1, (int)ceil(mb_strlen($text) / 4));
	}

	/** Remove metrics immediately when their owning account is erased. */
	public function deleteUserData(string $userId): void {
		if ($userId === '') return;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('eva_ai_usage')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
	}

	/** Delete expired usage rows in small, portable batches; return rows removed. */
	public function deleteExpired(int $before, int $batchSize = 500, int $maxBatches = 20): int {
		$batchSize = max(1, min(1000, $batchSize));
		$maxBatches = max(1, min(100, $maxBatches));
		$deleted = 0;
		for ($batch = 0; $batch < $maxBatches; $batch++) {
			$select = $this->db->getQueryBuilder();
			$select->select('id')->from('eva_ai_usage')
				->where($select->expr()->lt('created_at', $select->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC')->setMaxResults($batchSize);
			$result = $select->executeQuery();
			try { $ids = array_map(static fn(array $row): int => (int)$row['id'], $result->fetchAll()); }
			finally { $result->closeCursor(); }
			if ($ids === []) break;

			$remove = $this->db->getQueryBuilder();
			$deleted += $remove->delete('eva_ai_usage')
				->where($remove->expr()->in('id', $remove->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			if (count($ids) < $batchSize) break;
		}
		return $deleted;
	}

	/** @return list<string> User IDs with recorded metrics; caller must enforce admin access. */
	public function userIdsWithUsage(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('eva_ai_usage')->orderBy('user_id', 'ASC');
		$result = $qb->executeQuery();
		$users = [];
		while ($row = $result->fetch()) {
			$userId = trim((string)($row['user_id'] ?? ''));
			if ($userId !== '') $users[] = $userId;
		}
		$result->closeCursor();
		return $users;
	}

	/** @param array<int,array{role?:string,content?:string}> $messages */
	public function recordChat(
		?string $userId,
		string $provider,
		string $model,
		array $messages,
		string $answer,
		?int $inputTokens = null,
		?int $outputTokens = null,
		int $durationMs = 0,
	): void {
		if ($userId === null || $userId === '') {
			return;
		}
		$retrieval = $this->traceContext?->consumeRetrievalMeasurements();
		$estimated = $inputTokens === null || $outputTokens === null;
		$inputTokens ??= self::estimateTokens(implode("\n", array_map(static fn(array $message): string => (string)($message['content'] ?? ''), $messages)));
		$outputTokens ??= self::estimateTokens($answer);
		$inputTokens = max(0, min($inputTokens, 2147483647));
		$outputTokens = max(0, min($outputTokens, 2147483647));
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
					'provider' => $qb->createNamedParameter(substr($provider, 0, 32)),
					'model' => $qb->createNamedParameter(substr($model, 0, 128)),
					'operation' => $qb->createNamedParameter('chat'),
					'input_tokens' => $qb->createNamedParameter($inputTokens, IQueryBuilder::PARAM_INT),
					'output_tokens' => $qb->createNamedParameter($outputTokens, IQueryBuilder::PARAM_INT),
					'total_tokens' => $qb->createNamedParameter($inputTokens + $outputTokens, IQueryBuilder::PARAM_INT),
					'estimated' => $qb->createNamedParameter($estimated ? 1 : 0, IQueryBuilder::PARAM_INT),
					'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
					'retrieval_backend' => $qb->createNamedParameter($retrieval['backend'] ?? 'none'),
					'retrieval_stage_mask' => $qb->createNamedParameter($retrieval['stage_mask'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_count_ms' => $qb->createNamedParameter($retrieval['count_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_embedding_ms' => $qb->createNamedParameter($retrieval['embedding_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_candidates_ms' => $qb->createNamedParameter($retrieval['candidates_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_hydrate_ms' => $qb->createNamedParameter($retrieval['hydrate_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_scope_ms' => $qb->createNamedParameter($retrieval['scope_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_ranking_ms' => $qb->createNamedParameter($retrieval['ranking_ms'] ?? 0, IQueryBuilder::PARAM_INT),
					'retrieval_total_ms' => $qb->createNamedParameter($retrieval['total_ms'] ?? 0, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
		} catch (\Throwable $e) {
			// Metrics must never make a successful answer fail, including while an
			// older installation is still running its database migration.
			$this->logger->debug('eva_ai usage metric could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/** Record one whole EVA request without retaining its prompt or response. */
	public function recordRagRequest(string $userId, int $durationMs, string $outcome): void {
		if ($userId === '' || !in_array($outcome, ['complete', 'error', 'incomplete'], true)) return;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter('internal'),
				'model' => $qb->createNamedParameter($outcome),
				'operation' => $qb->createNamedParameter('rag_request'),
				'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			// Optional telemetry must never turn an answer or stream into an error.
			$this->logger->debug('eva_ai request duration could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/** Record only slow tool calls; never persist arguments or returned data. */
	public function recordTool(?string $userId, string $tool, int $durationMs, bool $ok): void {
		if ($userId === null || $userId === '' || $durationMs < 100) return;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId), 'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter('internal'), 'model' => $qb->createNamedParameter(substr($tool, 0, 128)),
				'operation' => $qb->createNamedParameter('tool'), 'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT), 'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter($ok ? 0 : 1, IQueryBuilder::PARAM_INT), 'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->debug('eva_ai tool metric could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/**
	 * Record one embedding batch without storing its text, model name or profile
	 * ID. Only the two supported provider classes are persisted for global stats.
	 */
	public function recordEmbeddingBatch(
		?string $userId,
		string $provider,
		int $durationMs,
		int $cacheHits,
		int $cacheMisses,
		bool $ok,
	): void {
		if ($userId === null || $userId === '') return;
		if (!in_array($provider, ['ollama', 'openai_compatible'], true)) $provider = 'openai_compatible';
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter($provider),
				'model' => $qb->createNamedParameter('embedding'),
				'operation' => $qb->createNamedParameter($ok ? 'embedding' : 'embedding_error'),
				'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
				'embedding_cache_hits' => $qb->createNamedParameter(max(0, $cacheHits), IQueryBuilder::PARAM_INT),
				'embedding_cache_misses' => $qb->createNamedParameter(max(0, $cacheMisses), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			// Observability must never fail indexing or a chat request.
			$this->logger->debug('eva_ai embedding metric could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/** @return array{totals:array<string,int>,by_model:list<array<string,mixed>>,daily:list<array<string,mixed>>} */
	public function summaryForUser(string $userId, int $days = 30, array $pricing = []): array {
		$since = time() - max(1, min(365, $days)) * 86400;
		$totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'estimated_requests' => 0];
		$byModel = [];
		$daily = [];
		$slowTools = [];
		$ragRequestDuration = ['samples' => 0, 'outcomes' => []];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('provider', 'model')
				->selectAlias($qb->createFunction('COUNT(*)'), 'requests')
				->selectAlias($qb->createFunction('SUM(input_tokens)'), 'input_tokens')
				->selectAlias($qb->createFunction('SUM(output_tokens)'), 'output_tokens')
				->selectAlias($qb->createFunction('SUM(total_tokens)'), 'total_tokens')
				->selectAlias($qb->createFunction('SUM(estimated)'), 'estimated_requests')
				->selectAlias($qb->createFunction('AVG(duration_ms)'), 'average_duration_ms')
				->selectAlias($qb->createFunction('MAX(duration_ms)'), 'max_duration_ms')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->groupBy('provider', 'model')
				->orderBy('total_tokens', 'DESC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$item = $this->numbers($row) + [
					'provider' => (string)$row['provider'],
					'model' => (string)$row['model'],
					'average_duration_ms' => (int)round((float)($row['average_duration_ms'] ?? 0)),
					'max_duration_ms' => (int)($row['max_duration_ms'] ?? 0),
				];
				$price = ModelPricing::find($pricing, $item['provider'], $item['model']);
				$item['estimated_cost_usd'] = $price === null ? null : ModelPricing::estimate($item, $price);
				$byModel[] = $item;
				foreach (array_keys($totals) as $key) $totals[$key] += $item[$key];
			}
			$result->closeCursor();

			$qb = $this->db->getQueryBuilder();
			$qb->select('created_at', 'input_tokens', 'output_tokens', 'total_tokens')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'ASC');
			$result = $qb->executeQuery();
			$dailyMap = [];
			while ($row = $result->fetch()) {
				$day = gmdate('Y-m-d', (int)$row['created_at']);
				$dailyMap[$day] ??= ['day' => $day, 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'estimated_requests' => 0];
				$dailyMap[$day]['requests']++;
				$dailyMap[$day]['input_tokens'] += (int)($row['input_tokens'] ?? 0);
				$dailyMap[$day]['output_tokens'] += (int)($row['output_tokens'] ?? 0);
				$dailyMap[$day]['total_tokens'] += (int)($row['total_tokens'] ?? 0);
			}
			$result->closeCursor();
			$daily = array_values($dailyMap);

			$qb = $this->db->getQueryBuilder();
			$qb->select('model')
				->selectAlias($qb->createFunction('COUNT(*)'), 'calls')
				->selectAlias($qb->createFunction('AVG(duration_ms)'), 'avg_duration_ms')
				->selectAlias($qb->createFunction('MAX(duration_ms)'), 'max_duration_ms')
				->selectAlias($qb->createFunction('SUM(estimated)'), 'errors')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('tool')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->groupBy('model')->orderBy('avg_duration_ms', 'DESC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$slowTools[] = ['tool' => (string)$row['model'], 'calls' => (int)$row['calls'], 'avg_duration_ms' => (int)round((float)$row['avg_duration_ms']), 'max_duration_ms' => (int)$row['max_duration_ms'], 'errors' => (int)$row['errors']];
			}
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->debug('eva_ai usage metrics unavailable', ['exception' => $e->getMessage()]);
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('model', 'duration_ms')->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('rag_request')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('duration_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(5000);
			$result = $qb->executeQuery();
			$samples = ['complete' => [], 'error' => [], 'incomplete' => []];
			try {
				while (($row = $result->fetch()) !== false) {
					$outcome = (string)$row['model'];
					if (isset($samples[$outcome])) $samples[$outcome][] = max(0, (int)$row['duration_ms']);
				}
			} finally { $result->closeCursor(); }
			foreach ($samples as $outcome => $values) {
				if ($values === []) continue;
				sort($values, SORT_NUMERIC);
				$count = count($values);
				$quantiles = [];
				foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $quantile) {
					$quantiles[$name . '_ms'] = $values[max(0, (int)ceil($quantile * $count) - 1)];
				}
				$ragRequestDuration['samples'] += $count;
				$ragRequestDuration['outcomes'][$outcome] = ['count' => $count] + $quantiles;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('eva_ai request duration metrics unavailable', ['exception' => $e->getMessage()]);
		}
		$ragRequestDuration['outcomes'] = (object)$ragRequestDuration['outcomes'];
		return ['days' => $days, 'totals' => $totals, 'by_model' => $byModel, 'daily' => $daily, 'slow_tools' => $slowTools, 'rag_request_duration' => $ragRequestDuration];
	}

	/** @return array<string,int> */
	private function numbers(array $row): array {
		return [
			'requests' => (int)($row['requests'] ?? 0),
			'input_tokens' => (int)($row['input_tokens'] ?? 0),
			'output_tokens' => (int)($row['output_tokens'] ?? 0),
			'total_tokens' => (int)($row['total_tokens'] ?? 0),
			'estimated_requests' => (int)($row['estimated_requests'] ?? 0),
		];
	}
}
