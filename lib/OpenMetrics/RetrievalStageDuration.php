<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate retrieval-stage quantiles from a bounded, PII-free sample window. */
final class RetrievalStageDuration implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const BACKENDS = ['sql_legacy', 'sql_generation', 'qdrant_generation'];
	private const STAGES = [
		'count' => 'retrieval_count_ms',
		'embedding' => 'retrieval_embedding_ms',
		'candidates' => 'retrieval_candidates_ms',
		'hydration' => 'retrieval_hydrate_ms',
		'scope' => 'retrieval_scope_ms',
		'ranking' => 'retrieval_ranking_ms',
		'total' => 'retrieval_total_ms',
	];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_retrieval_stage_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate recent EVA retrieval-stage duration quantiles from at most 5000 chat requests'; }

	public function metrics(): Generator {
		$samples = [];
		$stageNames = array_keys(self::STAGES);
		$columns = array_values(self::STAGES);
		$q = $this->db->getQueryBuilder();
		$q->select('retrieval_backend', 'retrieval_stage_mask', ...$columns)->from('eva_ai_usage')
			->where($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('operation', $q->createNamedParameter('chat')))
			->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
		try {
			$result = $q->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$backend = (string)$row['retrieval_backend'];
					if (!in_array($backend, self::BACKENDS, true)) continue;
					$stageMask = (int)$row['retrieval_stage_mask'];
					foreach ($stageNames as $index => $stage) {
						if (($stageMask & (1 << $index)) === 0) continue;
						$samples[$backend][$stage][] = max(0, (int)$row[self::STAGES[$stage]]);
					}
				}
			} finally { $result->closeCursor(); }
		} catch (\Throwable) {
			// A scrape must remain available during migrations or on older schemas.
			return;
		}

		foreach ($samples as $backend => $stageSamples) {
			foreach ($stageSamples as $stage => $values) {
				sort($values, SORT_NUMERIC);
				$count = count($values);
				foreach (self::QUANTILES as $label => $quantile) {
					$index = max(0, (int)ceil($quantile * $count) - 1);
					yield new Metric($values[$index], ['backend' => $backend, 'stage' => $stage, 'quantile' => $label]);
				}
			}
		}
	}
}
