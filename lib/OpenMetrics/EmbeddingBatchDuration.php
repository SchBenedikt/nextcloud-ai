<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate successful and failed embedding batch latency from bounded samples. */
final class EmbeddingBatchDuration implements IMetricFamily {
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}
	public function name(): string { return 'eva_ai_embedding_batch_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate seven-day embedding batch latency quantiles from at most 5000 batches per provider'; }

	public function metrics(): Generator {
		foreach (EmbeddingSampleReader::PROVIDERS as $provider) {
			try { $rows = EmbeddingSampleReader::read($this->db, $provider); }
			catch (\Throwable) { continue; }
			$durations = ['success' => [], 'error' => []];
			foreach ($rows as $row) {
				if ($row['duration_ms'] > 0) {
					$result = $row['operation'] === 'embedding' ? 'success' : 'error';
					$durations[$result][] = $row['duration_ms'];
				}
			}
			foreach ($durations as $result => $values) {
				if ($values === []) continue;
				sort($values, SORT_NUMERIC);
				$count = count($values);
				foreach (self::QUANTILES as $label => $quantile) {
					$index = max(0, (int)ceil($quantile * $count) - 1);
					yield new Metric($values[$index], ['provider' => $provider, 'result' => $result, 'quantile' => $label]);
				}
			}
		}
	}
}
