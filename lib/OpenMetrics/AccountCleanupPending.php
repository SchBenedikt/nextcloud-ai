<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCA\EvaAi\Db\AccountCleanupRepository;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Aggregate retry backlog only; the encrypted account identity is never exported. */
class AccountCleanupPending implements IMetricFamily {
	public function __construct(private AccountCleanupRepository $cleanup) {}

	public function name(): string { return 'eva_ai_account_cleanup_pending'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'requests'; }
	public function help(): string { return 'Pending durable EVA account erasure requests'; }

	public function metrics(): Generator {
		$summary = $this->cleanup->summary(time());
		yield new Metric($summary['pending']);
	}
}
