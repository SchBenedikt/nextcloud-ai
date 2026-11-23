<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\VectorOutboxRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Bounded retention for terminal point events; erasure intent is preserved. */
final class VectorOutboxRetentionJob extends TimedJob {
	private const RETENTION_SECONDS = 30 * 86400;

	public function __construct(ITimeFactory $time, private VectorOutboxRepository $outbox, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void {
		$cutoff = time() - self::RETENTION_SECONDS;
		try {
			$this->outbox->pruneTerminalPointEvents($cutoff, $cutoff);
		} catch (\Throwable) {
			try { $this->logger->warning('eva_ai: vector journal retention tick interrupted'); } catch (\Throwable) {}
		}
	}
}
