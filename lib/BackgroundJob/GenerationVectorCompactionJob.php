<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\GenerationChunkRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/** Rewrites historical local-generation vectors in small, resumable pages. */
final class GenerationVectorCompactionJob extends TimedJob {
	private const CURSOR_KEY = 'generation_vector_compaction_cursor';

	public function __construct(ITimeFactory $time, private GenerationChunkRepository $chunks,
		private IConfig $config, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		$cursor = max(0, (int)$this->config->getAppValue('eva_ai', self::CURSOR_KEY, '0'));
		try {
			$page = $this->chunks->compactSqlVectorsAfter($cursor);
			$this->config->setAppValue('eva_ai', self::CURSOR_KEY, (string)$page['cursor']);
		} catch (\Throwable $e) {
			$this->logger->warning('eva_ai: generation vector compaction tick interrupted', ['exception' => $e->getMessage()]);
		}
	}
}
