<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\LexicalTermRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Gradually builds portable exact-token postings for existing chunks. */
final class LexicalIndexBackfillJob extends TimedJob {
	public function __construct(ITimeFactory $time, private LexicalTermRepository $terms, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		try {
			$this->terms->backfill();
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: lexical index backfill tick failed', [
				'exception' => $e,
			]);
		}
	}
}
