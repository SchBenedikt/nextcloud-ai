<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Bounded approximate duration quantiles with fixed, non-sensitive labels. */
final class OperationDuration implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const OPERATIONS = ['chat_completion', 'tool'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_operation_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate recent EVA provider chat-completion and slow-tool duration quantiles'; }

	public function metrics(): Generator {
		$samples = array_fill_keys(self::OPERATIONS, []);
		$storedOperations = ['chat', 'tool'];
		$q = $this->db->getQueryBuilder();
		$q->select('operation', 'duration_ms')->from('eva_ai_usage')
			->where($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->in('operation', $q->createNamedParameter($storedOperations, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($q->expr()->gt('duration_ms', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
		try {
			$result = $q->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$operation = (string)$row['operation'] === 'chat' ? 'chat_completion' : (string)$row['operation'];
					if (isset($samples[$operation])) $samples[$operation][] = max(0, (int)$row['duration_ms']);
				}
			} finally { $result->closeCursor(); }
		} catch (\Throwable) {
			// Metrics scraping must not turn an optional/unavailable table into an app failure.
			return;
		}

		foreach (self::OPERATIONS as $operation) {
			if ($samples[$operation] === []) continue;
			sort($samples[$operation], SORT_NUMERIC);
			$count = count($samples[$operation]);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($samples[$operation][$index], ['operation' => $operation, 'quantile' => $label]);
			}
		}
	}
}
