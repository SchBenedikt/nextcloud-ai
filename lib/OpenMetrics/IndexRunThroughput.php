<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate file-chunk ingest throughput from a bounded indexing-run sample. */
final class IndexRunThroughput implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const MODES = ['all', 'files', 'mail', 'talk'];
	private const OUTCOMES = ['complete', 'error', 'incomplete'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_index_run_file_chunks_per_second'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'chunks_per_second'; }
	public function help(): string { return 'Approximate file chunk throughput quantiles from recent EVA indexing runs'; }

	public function metrics(): Generator {
		$groups = [];
		try {
			$q = $this->db->getQueryBuilder();
			$q->select('index_run_mode', 'model', 'index_run_file_duration_ms', 'index_run_chunks')->from('eva_ai_usage')
				->where($q->expr()->eq('operation', $q->createNamedParameter('index_run')))
				->andWhere($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
				->andWhere($q->expr()->gt('index_run_file_duration_ms', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
			$result = $q->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$mode = (string)$row['index_run_mode'];
					$outcome = (string)$row['model'];
					if (!in_array($mode, self::MODES, true) || !in_array($outcome, self::OUTCOMES, true)) continue;
					$duration = max(0, (int)$row['index_run_file_duration_ms']);
					$chunks = max(0, (int)$row['index_run_chunks']);
					if ($duration === 0 || $chunks === 0) continue;
					$groups[$mode][$outcome][] = $chunks * 1000 / $duration;
				}
			} finally { $result->closeCursor(); }
		} catch (\Throwable) {
			return;
		}

		foreach ($groups as $mode => $outcomes) {
			foreach ($outcomes as $outcome => $values) {
				sort($values, SORT_NUMERIC);
				$count = count($values);
				foreach (self::QUANTILES as $label => $quantile) {
					$index = max(0, (int)ceil($quantile * $count) - 1);
					yield new Metric($values[$index], ['mode' => $mode, 'outcome' => $outcome, 'quantile' => $label]);
				}
			}
		}
	}
}
