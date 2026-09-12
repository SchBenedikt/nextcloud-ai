<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Serializes index runs per user through Nextcloud's shared locking provider
 * (database or distributed cache) and heals the one failure mode that would
 * otherwise block a user forever.
 *
 * Nextcloud's database-backed locking provider stores exclusive locks as rows
 * in the file_locks table with a TTL. The row is only removed again by the
 * maintenance job OCA\Files\BackgroundJob\CleanupFileLocks; a worker that
 * crashes while holding the lock therefore leaves an expired row behind, and
 * acquireLock() keeps failing with LockedException on instances where that
 * cron job never runs (AJAX background jobs, no cron configured).
 *
 * When the lock is still held by a live run (fresh heartbeat) we must not
 * steal it. Only when the per-user run state is idle or stale - the same
 * heartbeat rule recoverStaleIndex() uses - an expired row is deleted and the
 * acquire is retried once.
 */
class LockGuard {
    /** Same heartbeat age the controller, the cron job and the indexer use. */
    private const STALE_RUN_SECONDS = AppConfig::STALE_RUN_SECONDS;

    /**
     * Bounded per-user index lock key. The database-backed locking provider
     * stores ILockingProvider locks in the file_locks table, whose key column
     * is varchar(64). A path built from the full 64-hex sha256 exceeds that
     * limit, so the insert fails (or is silently truncated on lenient
     * servers): the lock is never acquired, releaseLock() cannot find the
     * row, and stale rows collide with every later attempt. 40 hex chars
     * (160 bits) keep the key far below the limit with a negligible collision
     * risk - the same bound the chat store already uses (Issue #78).
     */
    public static function indexLockPath(string $userId): string {
        return 'eva_ai/index/' . substr(hash('sha256', $userId), 0, 40);
    }

    public function __construct(
        private AppConfig $config,
        private ILockingProvider $lockingProvider,
        private IDBConnection $db
    ) {
    }

    /**
     * Acquire the per-user index lock, reclaiming an expired row left behind
     * by a crashed worker when the tracked run state is idle or stale.
     *
     * @throws LockedException when a live worker still holds the lock
     */
    public function acquireIndexLock(string $userId, string $lockPath): void {
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index for ' . $userId);
            return;
        } catch (LockedException $e) {
            // Expired lock rows are common after a crashed worker; reclaim below.
        }

        if (!$this->trackedRunIsStaleOrIdle($userId)) {
            // A live run owns the lock; never steal it.
            throw new LockedException($lockPath);
        }

        // Only a row whose TTL has already passed may be removed. A live
        // worker refreshes the TTL on acquire but not while it runs for a long
        // time, so the staleness check above is the actual safety boundary.
        $qb = $this->db->getQueryBuilder();
        $qb->delete('file_locks')
            ->where($qb->expr()->eq('key', $qb->createNamedParameter($lockPath)))
            ->andWhere($qb->expr()->lt('ttl', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        // If another worker grabbed the lock between our delete and retry, the
        // LockedException propagates to the caller as usual.
        $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index for ' . $userId);
    }

    private function trackedRunIsStaleOrIdle(string $userId): bool {
        $this->config->setUserId($userId);
        if ($this->config->get('index_running') !== '1') {
            return true;
        }
        $heartbeat = (int)$this->config->get('index_heartbeat');
        $started = $heartbeat > 0 ? $heartbeat : (int)$this->config->get('index_started');
        return $started > 0 && time() - $started > self::STALE_RUN_SECONDS;
    }
}
