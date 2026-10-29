<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate SQL vector rows read by successful dense retrievals. */
final class SqlDenseScanRows implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const BACKENDS = ['sql_legacy', 'sql_generation'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_sql_dense_scan_rows'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'rows'; }
	public function help(): string { return 'Approximate seven-day SQL vector rows scanned per dense EVA retrieval'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('retrieval_backend', 'retrieval_stage_mask', 'retrieval_dense_sql_rows_scanned')->from('eva_ai_usage')
			->where($q->expr()->eq('operation', $q->createNamedParameter('chat')))
			->andWhere($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->gt('retrieval_dense_sql_rows_scanned', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
		$samples = [];
		try {
			$result = $q->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$backend = (string)$row['retrieval_backend'];
					if (!in_array($backend, self::BACKENDS, true) || (((int)$row['retrieval_stage_mask'] & (1 << 2)) === 0)) continue;
					$samples[$backend][] = max(0, (int)$row['retrieval_dense_sql_rows_scanned']);
				}
			} finally { $result->closeCursor(); }
		} catch (\Throwable) {
			// Scrapes remain available during upgrades or on older schemas.
			return;
		}

		foreach ($samples as $backend => $values) {
			sort($values, SORT_NUMERIC);
			$count = count($values);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($values[$index], ['backend' => $backend, 'quantile' => $label]);
			}
		}
	}
}
