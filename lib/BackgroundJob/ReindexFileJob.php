<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\DirtyIndexStore;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Drains a user's queued filesystem changes (Issue #79): each entry reindexes
 * one file or repairs paths after a folder rename/delete, reusing the normal
 * Indexer path with its per-user lock. The drain is bounded so a huge backlog
 * (sync-client burst) makes progress across several worker runs instead of
 * monopolising a single one; the periodic full scan remains the safety net.
 */
class ReindexFileJob extends QueuedJob {
    private const DRAIN_LIMIT = 300;

    public function __construct(
        ITimeFactory $time,
        private DirtyIndexStore $dirty,
        private Indexer $indexer,
        private AppConfig $config,
        private IJobList $jobList,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $userId = is_array($argument) ? trim((string)($argument['userId'] ?? '')) : '';
        if ($userId === '') {
            return;
        }
        $entries = $this->dirty->drain($userId, self::DRAIN_LIMIT);
        if ($entries === []) {
            return;
        }
        $this->config->setUserId($userId);
        try {
            // Honor the per-user opt-out: drop queued work silently.
            if ($this->config->hasIndexEnrollment($userId) && !$this->config->isIndexEnrolled($userId)) {
                $this->dirty->drain($userId, 100000); // clear everything
                return;
            }
            $processed = 0;
            foreach ($entries as $entry) {
                $kind = (string)($entry['kind'] ?? '');
                try {
                    if ($kind === 'file') {
                        $this->indexer->reindexFile($userId, (int)($entry['fileId'] ?? 0));
                    } elseif ($kind === 'folder-delete') {
                        $this->indexer->deleteByPathPrefix($userId, (string)($entry['path'] ?? ''));
                    } elseif ($kind === 'folder-rename') {
                        $this->indexer->updatePathPrefix($userId, (string)($entry['oldPath'] ?? ''), (string)($entry['path'] ?? ''));
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai incremental entry failed', [
                        'userId' => $userId,
                        'kind' => $kind,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
            if ($this->dirty->pending($userId) > 0) {
                // More entries arrived (or remain): continue on the next tick.
                $this->jobList->scheduleAfter(self::class, 30, ['userId' => $userId]);
            }
            $this->logger->info('eva_ai incremental reindex batch done', [
                'userId' => $userId,
                'processed' => $processed,
            ]);
        } finally {
            $this->config->setUserId(null);
        }
    }
}