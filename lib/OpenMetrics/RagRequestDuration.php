<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate whole EVA request latency from a bounded, content-free sample. */
final class RagRequestDuration implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const OUTCOMES = ['complete', 'error', 'incomplete'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_rag_request_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate seven-day EVA request latency quantiles from at most 5000 requests'; }

	public function metrics(): Generator {
		foreach (self::OUTCOMES as $outcome) {
			$q = $this->db->getQueryBuilder();
			$q->select('duration_ms')->from('eva_ai_usage')
				->where($q->expr()->eq('operation', $q->createNamedParameter('rag_request')))
				->andWhere($q->expr()->eq('model', $q->createNamedParameter($outcome)))
				->andWhere($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
				->andWhere($q->expr()->gt('duration_ms', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
			try {
				$result = $q->executeQuery();
				$values = [];
				try {
					while (($row = $result->fetch()) !== false) $values[] = max(0, (int)$row['duration_ms']);
				} finally { $result->closeCursor(); }
			} catch (\Throwable) {
				// Missing or unavailable telemetry must not fail a Nextcloud scrape.
				continue;
			}
			if ($values === []) continue;
			sort($values, SORT_NUMERIC);
			$count = count($values);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($values[$index], ['outcome' => $outcome, 'quantile' => $label]);
			}
		}
	}
}
