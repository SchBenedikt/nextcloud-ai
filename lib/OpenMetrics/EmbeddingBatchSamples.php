<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Counts successes and errors in the bounded embedding sample. */
final class EmbeddingBatchSamples implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}
	public function name(): string { return 'eva_ai_embedding_batch_samples'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function help(): string { return 'Success and error batches present in the bounded seven-day embedding sample'; }

	public function metrics(): Generator {
		foreach (EmbeddingSampleReader::PROVIDERS as $provider) {
			try { $rows = EmbeddingSampleReader::read($this->db, $provider); }
			catch (\Throwable) { continue; }
			$counts = ['success' => 0, 'error' => 0];
			foreach ($rows as $row) $counts[$row['operation'] === 'embedding' ? 'success' : 'error']++;
			foreach ($counts as $result => $count) {
				if ($count > 0) yield new Metric($count, ['provider' => $provider, 'result' => $result]);
			}
		}
	}
}
