<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AgentStore;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\IndexScheduler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic background indexing job.
 *
 * Servers every user that has EVA index data (independent background indexing
 * per user) plus the legacy configured `index_user`, instead of only the
 * single app-global index_user (Issue #7). Each per-user pass is bounded by
 * the indexer's max_files_per_run budget, so the whole job stays bounded even
 * on multi-user instances.
 */
class IndexJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private Indexer $indexer,
        private DocumentMapper $documentMapper,
        private AgentStore $agentStore,
        private IndexScheduler $scheduler,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
    }

    /**
     * Maximum wall-clock seconds a single periodic run may spend indexing
     * before the next cron tick continues with the remaining users. Keep the
     * global lock short so one slow user cannot starve later users (Issue #112).
     */
    private const DEFAULT_MAX_SECONDS = 50;

    protected function run($argument): void {
        // The scheduler lock is global; the actual progress/settings are per user.
        $this->config->setUserId(null);
        if ($this->config->get('index_job_running') === '1') {
            $started = (int)$this->config->get('index_job_started');
            // Only reclaim a stale lock. A running pass now holds the lock for
            // a bounded budget (index_job_max_seconds), not up to an hour, so
            // a concurrent cron tick simply yields.
            if (time() - $started < 3600) {
                return;
            }
        }
        $this->config->set('index_job_running', '1');
        $this->config->set('index_job_started', (string)time());

        // Agent conversations are user data too. Prune abandoned state from
        // the shared table during the existing periodic maintenance job.
        $purged = $this->agentStore->purgeOlderThan();
        if ($purged > 0) {
            $this->logger->info('eva_ai agent state cleanup', ['deleted' => $purged]);
        }

        try {
            $documentUsers = $this->documentMapper->distinctUserIds();
            // Enrollment is persisted separately from indexed documents. An
            // explicit opt-out must win over stale document rows; users with
            // no stored choice are migrated into the enrolled set below.
            $eligibleDocumentUsers = array_values(array_filter(
                $documentUsers,
                fn(string $user): bool => !$this->config->hasIndexEnrollment($user)
                    || $this->config->isIndexEnrolled($user)
            ));
            // This keeps empty/reset indexes in the recurring schedule while
            // honoring an explicit per-user opt-out (Issue #49).
            $users = array_merge($eligibleDocumentUsers, $this->config->enrolledUserIds());
            $configured = $this->config->get('index_user');
            if ($configured !== ''
                && (!$this->config->hasIndexEnrollment($configured) || $this->config->isIndexEnrolled($configured))) {
                $users[] = $configured;
            }
            $users = array_values(array_unique(array_filter($users, static fn($u) => $u !== '')));

            // Fair round-robin continuation (Issue #112): begin this run after
            // the user the previous run finished, wrapping around, so users
            // later in the list are not starved by earlier slow users.
            $users = $this->rotateFromLastProcessed($users);

            $startedAt = time();
            $budget = $this->budgetSeconds();
            foreach ($users as $user) {
                // Existing installations are migrated lazily: a user with
                // indexed data is enrolled unless they already explicitly
                // chose an enrollment value (including 0).
                if (in_array($user, $eligibleDocumentUsers, true) && in_array($user, $documentUsers, true) && !$this->config->hasIndexEnrollment($user)) {
                    $this->config->setIndexEnrolled($user, true);
                }
                // Do not compete with an explicitly requested per-user job.
                $this->config->setUserId($user);
                if ($this->config->get('index_running') === '1') {
                    $heartbeat = (int)$this->config->get('index_heartbeat');
                    $age = $heartbeat > 0 ? time() - $heartbeat : PHP_INT_MAX;
                    $cancelRequested = $this->config->get('index_cancel_requested') === '1';
                    if ($age > 900 || ($cancelRequested && $age > 300)) {
                        // Cron must recover abandoned requests even when no
                        // browser calls the status endpoint.
                        $this->config->set('index_running', '0');
                        $this->config->set('index_mode', 'idle');
                        $this->config->set('index_cancel_requested', '0');
                        $this->config->set('index_run_id', '');
                        $this->config->set('index_heartbeat', '');
                    } else {
                        continue;
                    }
                }
                $this->config->setUserId($user);
                // Enforce the bounded time budget: one run must not block the
                // whole instance for every other user's index pass.
                if (time() - $startedAt >= $budget) {
                    $this->logger->info('eva_ai index job budget reached', [
                        'user' => $user,
                        'budget_seconds' => $budget,
                    ]);
                    break;
                }
                $this->logger->info('eva_ai index job start', ['user' => $user]);
                try {
                    $this->indexer->run($user);
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai index job failed for user', [
                        'user' => $user,
                        'exception' => $e->getMessage(),
                    ]);
                }
                // Remember the last user actually processed (even on failure)
                // so the next run continues fairly after this user.
                $this->config->set('index_job_last_user', $user);
            }

            // Fair drain of the scheduler queue (Issue #142): users queued
            // behind the global concurrency limit are served in FIFO order as
            // long as the time budget lasts. Each pass re-claims its slot via
            // Indexer::run(), so a queue entry that just became 'running'
            // moves to the back of the remaining picks.
            if (time() - $startedAt < $budget) {
                $drained = 0;
                foreach ($this->scheduler->queuedUsers(10) as $queuedUser) {
                    if (time() - $startedAt >= $budget) {
                        break;
                    }
                    $drained++;
                    $this->logger->info('eva_ai index job drains queued user', ['user' => $queuedUser]);
                    try {
                        $this->indexer->run($queuedUser);
                    } catch (\Throwable $e) {
                        $this->logger->warning('eva_ai index job drain failed for user', [
                            'user' => $queuedUser,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
                if ($drained > 0) {
                    $this->logger->info('eva_ai index job drained queue', ['users' => $drained]);
                }
            }
        } finally {
            $this->config->setUserId(null);
            $this->config->set('index_job_running', '0');
        }
    }

    /**
     * @param list<string> $users
     * @return list<string>
     */
    private function rotateFromLastProcessed(array $users): array {
        $last = (string)$this->config->get('index_job_last_user');
        if ($last === '') {
            return $users;
        }
        $pos = array_search($last, $users, true);
        if ($pos === false) {
            // The remembered user no longer exists - start from the beginning.
            return $users;
        }
        if ($pos === count($users) - 1) {
            // The whole list was processed in the previous run - wrap around.
            return $users;
        }
        // Start after the last processed user, wrapping around at the end.
        return array_merge(array_slice($users, $pos + 1), array_slice($users, 0, $pos + 1));
    }

    private function budgetSeconds(): int {
        $raw = (int)$this->config->get('index_job_max_seconds');
        if ($raw < 5) {
            return self::DEFAULT_MAX_SECONDS;
        }
        return min(600, $raw);
    }
}
