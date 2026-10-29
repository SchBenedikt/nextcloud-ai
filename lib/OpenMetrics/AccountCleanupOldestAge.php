<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCA\EvaAi\Db\AccountCleanupRepository;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Age of the oldest local erasure request; no account identity is exported. */
class AccountCleanupOldestAge implements IMetricFamily {
	public function __construct(private AccountCleanupRepository $cleanup) {}

	public function name(): string { return 'eva_ai_account_cleanup_oldest_age_seconds'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'seconds'; }
	public function help(): string { return 'Age of the oldest pending EVA account erasure request'; }

	public function metrics(): Generator {
		$summary = $this->cleanup->summary(time());
		yield new Metric($summary['oldest']);
	}
}
