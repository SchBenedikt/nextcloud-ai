<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\UsageMetrics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Removes usage records older than the longest supported report window. */
final class UsageMetricsRetentionJob extends TimedJob {
	private const RETENTION_SECONDS = 365 * 86400;

	public function __construct(ITimeFactory $time, private UsageMetrics $usage, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void {
		try {
			$this->usage->deleteExpired(time() - self::RETENTION_SECONDS);
		} catch (\Throwable) {
			$this->logger->warning('eva_ai: usage metrics retention tick interrupted');
		}
	}
}
