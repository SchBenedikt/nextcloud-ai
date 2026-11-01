<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\UserDataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Retries one durable account-erasure request per cron tick. */
final class AccountCleanupJob extends TimedJob {
	public function __construct(ITimeFactory $time, private UserDataService $userData, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		try { $this->userData->retryPendingAccountCleanup(); }
		catch (\Throwable) { $this->logger->warning('eva_ai: account erasure retry tick interrupted'); }
	}
}
