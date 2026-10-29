<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Embedding reuse over the same bounded provider samples as latency metrics. */
final class EmbeddingCacheHitRatio implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}
	public function name(): string { return 'eva_ai_embedding_cache_hit_ratio'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function help(): string { return 'Embedding cache hits divided by cache hits plus misses in the bounded recent provider sample'; }

	public function metrics(): Generator {
		foreach (EmbeddingSampleReader::PROVIDERS as $provider) {
			try { $rows = EmbeddingSampleReader::read($this->db, $provider); }
			catch (\Throwable) { continue; }
			$hits = array_sum(array_column($rows, 'cache_hits'));
			$misses = array_sum(array_column($rows, 'cache_misses'));
			$total = $hits + $misses;
			if ($total > 0) yield new Metric($hits / $total, ['provider' => $provider]);
		}
	}
}
