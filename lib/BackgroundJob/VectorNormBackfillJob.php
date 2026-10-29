<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\VectorNormRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Gradually backfills cosine metadata without blocking requests or migrations. */
final class VectorNormBackfillJob extends TimedJob {
	public function __construct(ITimeFactory $time, private VectorNormRepository $norms, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		try {
			$this->norms->backfill();
		} catch (\Throwable) {
			$this->logger->warning('eva_ai: vector norm backfill tick interrupted');
		}
	}
}
