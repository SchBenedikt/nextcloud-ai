<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCA\EvaAi\Service\IndexScheduler;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Aggregate wait-age buckets for the instance-wide index scheduler. */
final class IndexQueueAge implements IMetricFamily {
	private const BUCKETS = ['under_1m', '1m_5m', '5m_1h', '1h_24h', 'over_24h'];

	public function __construct(private IndexScheduler $scheduler) {}

	public function name(): string { return 'eva_ai_index_queue_age_events'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'events'; }
	public function help(): string { return 'Queued EVA index requests by scheduler wait-age bucket'; }

	public function metrics(): Generator {
		$buckets = $this->scheduler->queueAgeBuckets();
		foreach (self::BUCKETS as $bucket) {
			yield new Metric($buckets[$bucket] ?? 0, ['age_bucket' => $bucket]);
		}
	}
}
