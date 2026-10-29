<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\RequestRateLimiter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Removes short-lived SQL fallback counters left by a shared cache outage. */
final class RateLimitCleanupJob extends TimedJob {
	public function __construct(ITimeFactory $time, private RequestRateLimiter $limiter, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(900);
	}

	protected function run($argument): void {
		try {
			$this->limiter->deleteExpired(time());
		} catch (\Throwable) {
			$this->logger->warning('eva_ai: rate-limit counter cleanup interrupted');
		}
	}
}
