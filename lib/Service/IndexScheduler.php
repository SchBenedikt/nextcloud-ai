<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Fair multi-user scheduling for indexing (Issue #142).
 *
 * Per-user locks already prevent duplicate work for one account; this service
 * bounds the total number of index passes running at the same time across all
 * users, so a burst on one account cannot exhaust CPU, memory, database
 * connections or Ollama capacity. Users beyond the limit are queued FIFO, so
 * no user is starved while others run repeatedly.
 *
 * The scheduler state lives in app-level config values (not per user):
 *   - index_scheduler_active: JSON map userId => heartbeat timestamp
 *   - index_scheduler_queue:  JSON list of {user, queuedAt}
 * All mutations run under a short-lived global lock (ILockingProvider) so
 * concurrent web/cron workers cannot corrupt the queue.
 *
 * Limits:
 *   - index_max_concurrent (app setting, default 2, bounds 1-16): how many
 *     index passes may run concurrently instance-wide.
 *   - A slot is released by the worker in its finally block; abandoned slots
 *     (heartbeat older than recoverStale) are reclaimed by the next caller.
 */
class IndexScheduler {
    /** An active slot whose heartbeat is older than this is considered abandoned. */
    private const STALE_SLOT_SECONDS = 900;

    private const ACTIVE_KEY = 'index_scheduler_active';
    private const QUEUE_KEY = 'index_scheduler_queue';
    private const LOCK_PATH = 'eva_ai/scheduler';

    public function __construct(
        private AppConfig $config,
        private ILockingProvider $lockingProvider,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Try to claim a scheduling slot. Returns 'running' when the caller may
     * start an index pass now, or 'queued' with the user's FIFO position when
     * the global concurrency limit is already reached.
     *
     * @return array{state:'running'|'queued',position:int}
     */
    public function acquireSlot(string $userId): array {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
            $queue = $this->readQueue();

            // Already running? Stay running (idempotent re-request), and if a
            // previous queued entry for this user exists, drop it now.
            if (isset($active[$userId])) {
                $this->dropUserLocked($queue, $userId);
                $this->writeQueue($queue);
                return ['state' => 'running', 'position' => 0];
            }

            if (count($active) < $this->maxConcurrent()) {
                // A slot is free: run now and remove this user from the queue
                // so their FIFO entry cannot linger and block later drains.
                $this->dropUserLocked($queue, $userId);
                $this->writeQueue($queue);
                $active[$userId] = time();
                $this->writeActive($active);
                return ['state' => 'running', 'position' => 0];
            }

            // Already queued? Keep the original position (idempotent).
            $position = 0;
            $found = false;
            foreach ($queue as $i => $entry) {
                if (($entry['user'] ?? '') === $userId) {
                    $position = $i + 1;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $queue[] = ['user' => $userId, 'queuedAt' => time()];
                $this->writeQueue($queue);
                $position = count($queue);
            }
            return ['state' => 'queued', 'position' => $position];
        } finally {
            $this->unlock();
        }
    }

    /**
     * Release the slot a worker holds. The next queued user moves up one
     * position automatically (the queue is FIFO); the actual start happens
     * when the caller polls or the periodic job drains the queue.
     */
    public function releaseSlot(string $userId): void {
        $this->lock();
        try {
            $active = $this->readActive();
            unset($active[$userId]);
            $this->writeActive($active);
        } finally {
            $this->unlock();
        }
    }

    /** Refresh the heartbeat of a live slot so it is not reclaimed as stale. */
    public function touchHeartbeat(string $userId): void {
        $this->lock();
        try {
            $active = $this->readActive();
            if (isset($active[$userId])) {
                $active[$userId] = time();
                $this->writeActive($active);
            }
        } finally {
            $this->unlock();
        }
    }

    /**
     * Idempotent check: is the user currently holding a running slot?
     */
    public function isRunning(string $userId): bool {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
            return isset($active[$userId]);
        } finally {
            $this->unlock();
        }
    }

    /**
     * Snapshot of the scheduler for the status endpoint: how many passes are
     * running instance-wide and where this user stands (running or queue
     * position). Cheap - no per-user progress polling involved.
     *
     * @return array{running:int,limit:int,queued:int,position:int,state:string}
     */
    public function snapshot(string $userId): array {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
            $queue = $this->readQueue();
            $position = 0;
            $state = isset($active[$userId]) ? 'running' : 'idle';
            foreach ($queue as $i => $entry) {
                if (($entry['user'] ?? '') === $userId) {
                    $position = $i + 1;
                    $state = 'queued';
                    break;
                }
            }
            return [
                'running' => count($active),
                'limit' => $this->maxConcurrent(),
                'queued' => count($queue),
                'position' => $position,
                'state' => $state,
            ];
        } finally {
            $this->unlock();
        }
    }

    /**
     * Reclaim abandoned slots so a crashed worker can never block the queue.
     * Called on every acquire and snapshot; also safe to call from the
     * periodic job.
     */
    public function recoverStale(): void {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
        } finally {
            $this->unlock();
        }
    }

    /**
     * FIFO drain used by the periodic job: returns queued users in order of
     * arrival, limited to the number of free slots. Callers start those users
     * with an index pass; acquireSlot() turns each into 'running'.
     *
     * @return list<string>
     */
    public function queuedUsers(int $limit): array {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
            $queue = $this->readQueue();
            $free = max(0, $this->maxConcurrent() - count($active));
            $out = [];
            foreach ($queue as $entry) {
                $user = (string)($entry['user'] ?? '');
                if ($user === '' || isset($active[$user])) {
                    continue;
                }
                $out[] = $user;
                if (count($out) >= min($free, $limit)) {
                    break;
                }
            }
            return $out;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Global scheduler state for the admin overview (Issue #82): how many
     * users are running/queued and which ones, without exposing per-user
     * internals.
     *
     * @return array{running:int,queued:int,limit:int,runningUsers:list<string>,queuedUsers:list<string>}
     */
    public function overview(): array {
        $this->lock();
        try {
            $active = $this->readActive();
            $this->reclaimStaleLocked($active);
            $queue = $this->readQueue();
            $runningUsers = [];
            foreach ($active as $user => $entry) {
                if ((string)$user !== '') {
                    $runningUsers[] = (string)$user;
                }
            }
            sort($runningUsers);
            $queuedUsers = [];
            foreach ($queue as $entry) {
                $user = (string)($entry['user'] ?? '');
                if ($user !== '' && !isset($active[$user])) {
                    $queuedUsers[] = $user;
                }
            }
            sort($queuedUsers);
            return [
                'running' => count($runningUsers),
                'queued' => count($queuedUsers),
                'limit' => $this->maxConcurrent(),
                'runningUsers' => $runningUsers,
                'queuedUsers' => $queuedUsers,
            ];
        } finally {
            $this->unlock();
        }
    }

    /** @param list<array{user:string,queuedAt:int}> $queue */
    private function dropUserLocked(array &$queue, string $userId): void {
        $queue = array_values(array_filter(
            $queue,
            static fn(array $entry): bool => (string)($entry['user'] ?? '') !== $userId
        ));
    }

    /** Remove a user from the queue entirely (cancel/stop path). */
    public function dequeue(string $userId): void {
        $this->lock();
        try {
            $queue = $this->readQueue();
            $this->dropUserLocked($queue, $userId);
            $this->writeQueue($queue);
        } finally {
            $this->unlock();
        }
    }

    private function maxConcurrent(): int {
        $limit = $this->config->getInt('index_max_concurrent', 2);
        return max(1, min(16, $limit));
    }

    /** @param array<string,int> $active */
    private function reclaimStaleLocked(array &$active): void {
        $now = time();
        $changed = false;
        foreach ($active as $user => $heartbeat) {
            if ($now - (int)$heartbeat > self::STALE_SLOT_SECONDS) {
                unset($active[$user]);
                $changed = true;
                $this->logger->info('eva_ai: reclaimed abandoned index slot', ['user' => $user]);
            }
        }
        if ($changed) {
            $this->writeActive($active);
        }
    }

    /** @return array<string,int> */
    private function readActive(): array {
        $raw = $this->config->get(self::ACTIVE_KEY);
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,int> $active */
    private function writeActive(array $active): void {
        $this->config->set(self::ACTIVE_KEY, json_encode($active, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{user:string,queuedAt:int}> */
    private function readQueue(): array {
        $raw = $this->config->get(self::QUEUE_KEY);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['user'] ?? null)) {
                $out[] = ['user' => $entry['user'], 'queuedAt' => (int)($entry['queuedAt'] ?? time())];
            }
        }
        return $out;
    }

    /** @param list<array{user:string,queuedAt:int}> $queue */
    private function writeQueue(array $queue): void {
        $this->config->set(self::QUEUE_KEY, json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function lock(): void {
        try {
            $this->lockingProvider->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index scheduler');
        } catch (LockedException $e) {
            // A concurrent worker holds the scheduler lock; wait briefly and
            // retry once, then give up rather than corrupting the queue.
            usleep(50000);
            $this->lockingProvider->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index scheduler');
        }
    }

    private function unlock(): void {
        $this->lockingProvider->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
    }
}