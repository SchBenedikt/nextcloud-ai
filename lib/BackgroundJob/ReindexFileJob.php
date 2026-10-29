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
    private const MAX_ATTEMPTS = 5;
    private const RETRY_DELAY_SECONDS = 300;

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
            $failedEntries = [];
            foreach ($entries as $entry) {
                $kind = (string)($entry['kind'] ?? '');
                try {
                    if ($kind === 'file') {
                        $result = $this->indexer->reindexFile($userId, (int)($entry['fileId'] ?? 0));
                        if (is_string($result['error'] ?? null) && $result['error'] !== '') {
                            throw new \RuntimeException($result['error']);
                        }
                    } elseif ($kind === 'folder-delete') {
                        $this->indexer->deleteByPathPrefix($userId, (string)($entry['path'] ?? ''));
                    } elseif ($kind === 'folder-rename') {
                        $this->indexer->updatePathPrefix($userId, (string)($entry['oldPath'] ?? ''), (string)($entry['path'] ?? ''));
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $failedEntries[] = $entry;
                    $this->logger->warning('eva_ai incremental entry failed', [
                        'userId' => $userId,
                        'kind' => $kind,
                        'attempt' => max(0, (int)($entry['attempts'] ?? 0)) + 1,
                        'max_attempts' => self::MAX_ATTEMPTS,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $retainedFailures = 0;
            if ($failedEntries !== []) {
                try {
                    $retainedFailures = $this->dirty->requeueFailed($userId, $failedEntries, self::MAX_ATTEMPTS);
                    if (count($failedEntries) > $retainedFailures) {
                        $this->logger->error('eva_ai incremental entries exhausted their retry budget; periodic indexing remains the recovery path', [
                            'userId' => $userId,
                            'entries' => count($failedEntries) - $retainedFailures,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // The periodic full index is still the recovery path if
                    // AppData cannot persist a retry at this point.
                    $this->logger->error('eva_ai incremental retry could not be persisted', [
                        'userId' => $userId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
            if ($retainedFailures > 0 || $this->dirty->pending($userId) > 0) {
                // Retry transient failures with backoff; fresh events without
                // failures continue quickly through the debounced queue.
                $delay = $retainedFailures > 0 ? self::RETRY_DELAY_SECONDS : 30;
                $this->jobList->scheduleAfter(self::class, $delay, ['userId' => $userId]);
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
