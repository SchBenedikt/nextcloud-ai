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
					'retrieval_embedding_provider' => $qb->createNamedParameter($retrieval['embedding_provider'] ?? 'none'),
					'retrieval_dense_sql_rows_scanned' => $qb->createNamedParameter($retrieval['dense_sql_rows_scanned'] ?? 0, IQueryBuilder::PARAM_INT),
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
			try { $this->logger->debug('eva_ai request duration could not be stored', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Telemetry must not alter chat results. */ }
		}
	}

	/** Store bounded indexing-run metadata, never paths, names or extracted text. */
	public function recordIndexRun(string $userId, string $mode, int $durationMs, int $filePassDurationMs, int $items, int $fileChunks, string $outcome): void {
		if ($userId === '' || !in_array($mode, ['all', 'files', 'mail', 'talk'], true)
			|| !in_array($outcome, ['complete', 'error', 'incomplete'], true)) return;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter('internal'),
				'model' => $qb->createNamedParameter($outcome),
				'operation' => $qb->createNamedParameter('index_run'),
				'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
				'index_run_mode' => $qb->createNamedParameter($mode),
				'index_run_items' => $qb->createNamedParameter(max(0, $items), IQueryBuilder::PARAM_INT),
				'index_run_chunks' => $qb->createNamedParameter(max(0, $fileChunks), IQueryBuilder::PARAM_INT),
				'index_run_file_duration_ms' => $qb->createNamedParameter(max(0, $filePassDurationMs), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai index run metrics could not be stored', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Telemetry must not alter indexing results. */ }
		}
	}

	/** Store only real chat-lock contention; uncontended locks add no SQL write. */
	public function recordChatStoreLockWait(string $userId, string $mode, string $outcome, int $durationMs): void {
		if ($userId === '' || !in_array($mode, ['shared', 'exclusive'], true)
			|| !in_array($outcome, ['acquired', 'degraded_read', 'busy', 'read_failed'], true)
			|| $durationMs < 1) return;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter('internal'),
				'model' => $qb->createNamedParameter($mode . ':' . $outcome),
				'operation' => $qb->createNamedParameter('chat_lock_wait'),
				'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'duration_ms' => $qb->createNamedParameter($durationMs, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai chat lock wait metric could not be stored', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Optional telemetry must not affect chat storage. */ }
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
		$embeddingQueryDuration = ['samples' => 0, 'providers' => []];
		$sqlDenseScan = ['samples' => 0, 'backends' => []];
		$indexRuns = ['samples' => 0, 'modes' => []];
		$chatStoreLockWait = ['samples' => 0, 'modes' => []];
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
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('retrieval_embedding_provider', 'retrieval_stage_mask', 'retrieval_embedding_ms')->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(5000);
			$result = $qb->executeQuery();
			$samples = ['ollama' => [], 'openai_compatible' => []];
			try {
				while (($row = $result->fetch()) !== false) {
					$provider = (string)$row['retrieval_embedding_provider'];
					if (isset($samples[$provider]) && (((int)$row['retrieval_stage_mask'] & (1 << 1)) !== 0)) {
						$samples[$provider][] = max(0, (int)$row['retrieval_embedding_ms']);
					}
				}
			} finally { $result->closeCursor(); }
			foreach ($samples as $provider => $values) {
				if ($values === []) continue;
				sort($values, SORT_NUMERIC);
				$count = count($values);
				$quantiles = [];
				foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $quantile) {
					$quantiles[$name . '_ms'] = $values[max(0, (int)ceil($quantile * $count) - 1)];
				}
				$embeddingQueryDuration['samples'] += $count;
				$embeddingQueryDuration['providers'][$provider] = ['count' => $count] + $quantiles;
			}
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai query embedding metrics unavailable', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Optional metrics must not fail the dashboard. */ }
		}
		$embeddingQueryDuration['providers'] = (object)$embeddingQueryDuration['providers'];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('retrieval_backend', 'retrieval_stage_mask', 'retrieval_dense_sql_rows_scanned')->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('retrieval_dense_sql_rows_scanned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(5000);
			$result = $qb->executeQuery();
			$samples = ['sql_legacy' => [], 'sql_generation' => []];
			try {
				while (($row = $result->fetch()) !== false) {
					$backend = (string)$row['retrieval_backend'];
					if (isset($samples[$backend]) && (((int)$row['retrieval_stage_mask'] & (1 << 2)) !== 0)) {
						$samples[$backend][] = max(0, (int)$row['retrieval_dense_sql_rows_scanned']);
					}
				}
			} finally { $result->closeCursor(); }
			foreach ($samples as $backend => $values) {
				if ($values === []) continue;
				sort($values, SORT_NUMERIC);
				$count = count($values);
				$quantiles = [];
				foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $quantile) {
					$quantiles[$name . '_rows'] = $values[max(0, (int)ceil($quantile * $count) - 1)];
				}
				$sqlDenseScan['samples'] += $count;
				$sqlDenseScan['backends'][$backend] = ['count' => $count] + $quantiles;
			}
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai SQL dense scan metrics unavailable', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Optional metrics must not fail the dashboard. */ }
		}
		$sqlDenseScan['backends'] = (object)$sqlDenseScan['backends'];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('index_run_mode', 'model', 'duration_ms', 'index_run_items', 'index_run_chunks', 'index_run_file_duration_ms')->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('index_run')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('duration_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(5000);
			$result = $qb->executeQuery();
			$rows = [];
			try {
				while (($row = $result->fetch()) !== false) {
					$mode = (string)$row['index_run_mode'];
					$outcome = (string)$row['model'];
					if (!in_array($mode, ['all', 'files', 'mail', 'talk'], true)
						|| !in_array($outcome, ['complete', 'error', 'incomplete'], true)) continue;
					$rows[$mode][$outcome][] = [
						'duration_ms' => max(0, (int)$row['duration_ms']),
						'items' => max(0, (int)$row['index_run_items']),
						'file_chunks' => max(0, (int)$row['index_run_chunks']),
						'file_duration_ms' => max(0, (int)$row['index_run_file_duration_ms']),
					];
				}
			} finally { $result->closeCursor(); }
			foreach ($rows as $mode => $outcomes) {
				foreach ($outcomes as $outcome => $values) {
					$durations = array_column($values, 'duration_ms');
					sort($durations, SORT_NUMERIC);
					$count = count($values);
					$writeSamples = array_values(array_filter($values, static fn(array $value): bool => $value['file_chunks'] > 0 && $value['file_duration_ms'] > 0));
					$fileDurationTotal = array_sum(array_column($writeSamples, 'file_duration_ms'));
					$itemsTotal = array_sum(array_column($values, 'items'));
					$chunksTotal = array_sum(array_column($values, 'file_chunks'));
					$quantiles = [];
					foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $quantile) {
						$quantiles[$name . '_ms'] = $durations[max(0, (int)ceil($quantile * $count) - 1)];
					}
					$indexRuns['samples'] += $count;
					$indexRuns['modes'][$mode][$outcome] = [
						'runs' => $count,
						'items' => $itemsTotal,
						'file_chunks' => $chunksTotal,
						'average_file_chunks_per_second' => $fileDurationTotal > 0 ? round(array_sum(array_column($writeSamples, 'file_chunks')) / ($fileDurationTotal / 1000), 2) : 0,
					] + $quantiles;
				}
			}
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai index run summary unavailable', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Optional metrics must not fail the dashboard. */ }
		}
		foreach ($indexRuns['modes'] as $mode => $outcomes) $indexRuns['modes'][$mode] = (object)$outcomes;
		$indexRuns['modes'] = (object)$indexRuns['modes'];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('model', 'duration_ms')->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat_lock_wait')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('duration_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(5000);
			$result = $qb->executeQuery();
			$waits = [];
			try {
				while (($row = $result->fetch()) !== false) {
					$parts = explode(':', (string)$row['model'], 2);
					if (count($parts) !== 2 || !in_array($parts[0], ['shared', 'exclusive'], true)
						|| !in_array($parts[1], ['acquired', 'degraded_read', 'busy', 'read_failed'], true)) continue;
					$waits[$parts[0]][$parts[1]][] = max(0, (int)$row['duration_ms']);
				}
			} finally { $result->closeCursor(); }
			foreach ($waits as $mode => $outcomes) foreach ($outcomes as $outcome => $values) {
				sort($values, SORT_NUMERIC);
				$count = count($values);
				$quantiles = [];
				foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $quantile) {
					$quantiles[$name . '_ms'] = $values[max(0, (int)ceil($quantile * $count) - 1)];
				}
				$chatStoreLockWait['samples'] += $count;
				$chatStoreLockWait['modes'][$mode][$outcome] = ['count' => $count] + $quantiles;
			}
		} catch (\Throwable $e) {
			try { $this->logger->debug('eva_ai chat lock wait summary unavailable', ['exception' => $e->getMessage()]); }
			catch (\Throwable) { /* Optional metrics must not fail the dashboard. */ }
		}
		foreach ($chatStoreLockWait['modes'] as $mode => $outcomes) $chatStoreLockWait['modes'][$mode] = (object)$outcomes;
		$chatStoreLockWait['modes'] = (object)$chatStoreLockWait['modes'];
		return ['days' => $days, 'totals' => $totals, 'by_model' => $byModel, 'daily' => $daily, 'slow_tools' => $slowTools, 'rag_request_duration' => $ragRequestDuration, 'embedding_query_duration' => $embeddingQueryDuration, 'sql_dense_scan' => $sqlDenseScan, 'index_runs' => $indexRuns, 'chat_store_lock_wait' => $chatStoreLockWait];
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
