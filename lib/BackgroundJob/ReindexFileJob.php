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
        $this->config->setUserId($userId);
        try {
            // Honor the per-user opt-out: drop queued work silently.
            if ($this->config->hasIndexEnrollment($userId) && !$this->config->isIndexEnrolled($userId)) {
                $this->dirty->deleteUserData($userId);
                return;
            }
            $processed = 0;
            $retainedFailures = 0;
            for ($claimed = 0; $claimed < self::DRAIN_LIMIT; $claimed++) {
                $entries = $this->dirty->claim($userId, 1, $claimed === 0);
                if ($entries === []) break;
                $entry = $entries[0];
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
                    $this->dirty->acknowledge($userId, [$entry]);
                    $processed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai incremental entry failed', [
                        'userId' => $userId,
                        'kind' => $kind,
                        'attempt' => max(0, (int)($entry['attempts'] ?? 0)) + 1,
                        'max_attempts' => self::MAX_ATTEMPTS,
                        'exception' => $e->getMessage(),
                    ]);
                    try {
                        $retry = $this->dirty->requeueFailed($userId, [$entry], self::MAX_ATTEMPTS, self::RETRY_DELAY_SECONDS);
                        $retainedFailures += $retry['requeued'];
                        if ($retry['exhausted'] > 0) {
                            $this->logger->error('eva_ai incremental entries exhausted their retry budget; periodic indexing remains the recovery path', [
                                'userId' => $userId,
                                'entries' => $retry['exhausted'],
                            ]);
                        }
                    } catch (\Throwable $retryError) {
                        // The lease expires and makes the claim available again
                        // if SQL could not persist the retry state.
                        $this->logger->error('eva_ai incremental retry could not be persisted', [
                            'userId' => $userId,
                            'exception' => $retryError->getMessage(),
                        ]);
                    }
                }
            }
            if ($this->dirty->pending($userId) > 0) {
                // Preserve delayed retries and recover expired leases if a
                // worker exits after claiming an entry.
                $delay = $this->dirty->secondsUntilNext($userId) ?? 30;
                $this->jobList->scheduleAfter(self::class, time() + max(30, $delay), ['userId' => $userId]);
            }
            $this->logger->info('eva_ai incremental reindex batch done', [
                'userId' => $userId,
                'processed' => $processed,
                'retrying' => $retainedFailures,
            ]);
        } finally {
            $this->config->setUserId(null);
        }
    }
}
