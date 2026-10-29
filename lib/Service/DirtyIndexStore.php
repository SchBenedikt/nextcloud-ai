<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Coalesced, per-user queue of filesystem changes. Nextcloud SQL is the durable
 * source; the old AppData JSON queue is imported incrementally after upgrade.
 */
class DirtyIndexStore {
    private const LEGACY_IMPORT_BATCH = 300;
    private const CLAIM_LEASE_SECONDS = 900;

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private ILockingProvider $locks,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function markFile(string $userId, int $fileId): void {
        if ($fileId <= 0) return;
        $this->mark($userId, 'f' . $fileId, ['kind' => 'file', 'fileId' => $fileId, 'path' => '', 'oldPath' => '']);
    }

    public function markFolderDeleted(string $userId, string $path): void {
        $this->mark($userId, 'd' . $path, ['kind' => 'folder-delete', 'fileId' => 0, 'path' => $path, 'oldPath' => '']);
    }

    public function markFolderRenamed(string $userId, string $oldPath, string $newPath): void {
        if ($oldPath === '' || $newPath === '') return;
        $this->mark($userId, 'r' . $oldPath, ['kind' => 'folder-rename', 'fileId' => 0, 'path' => $newPath, 'oldPath' => $oldPath]);
    }

    /**
     * Claim due work. Expired leases are reclaimed after a worker crash.
     *
     * @return list<array{id:int,eventKey:string,kind:string,fileId:int,path:string,oldPath:string,attempts:int,revision:int}>
     */
    public function claim(string $userId, int $limit = 1, bool $importLegacy = true): array {
        if ($userId === '') return [];
        $limit = max(1, min(100, $limit));
        return $this->withUserLock($userId, function () use ($userId, $limit, $importLegacy): array {
            if ($importLegacy) $this->importLegacyLocked($userId, self::LEGACY_IMPORT_BATCH);
            $now = time();
            $read = $this->db->getQueryBuilder();
            $read->select('id', 'event_key', 'kind', 'file_id', 'path', 'old_path', 'attempts', 'revision')
                ->from('eva_ai_dirty_index')
                ->where($read->expr()->eq('user_id', $read->createNamedParameter($userId)))
                ->andWhere($read->expr()->orX(
                    $read->expr()->andX(
                        $read->expr()->eq('status', $read->createNamedParameter('pending')),
                        $read->expr()->lte('available_at', $read->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                    ),
                    $read->expr()->andX(
                        $read->expr()->eq('status', $read->createNamedParameter('running')),
                        $read->expr()->lte('lease_until', $read->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                    ),
                ))
                ->orderBy('available_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->setMaxResults($limit);
            $result = $read->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            if ($rows === []) return [];

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $update = $this->db->getQueryBuilder();
                    $update->update('eva_ai_dirty_index')
                        ->set('status', $update->createNamedParameter('running'))
                        ->set('lease_until', $update->createNamedParameter($now + self::CLAIM_LEASE_SECONDS, IQueryBuilder::PARAM_INT))
                        ->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                        ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($update->expr()->eq('user_id', $update->createNamedParameter($userId)))
                        ->andWhere($update->expr()->eq('revision', $update->createNamedParameter((int)$row['revision'], IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }

            return array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'eventKey' => (string)$row['event_key'],
                'kind' => (string)$row['kind'],
                'fileId' => (int)$row['file_id'],
                'path' => (string)$row['path'],
                'oldPath' => (string)$row['old_path'],
                'attempts' => (int)$row['attempts'],
                'revision' => (int)$row['revision'],
            ], $rows);
        });
    }

    /** Delete completed claims only if the source revision has not changed. */
    public function acknowledge(string $userId, array $completed): int {
        if ($userId === '' || $completed === []) return 0;
        return $this->withUserLock($userId, function () use ($userId, $completed): int {
            $deleted = 0;
            $this->db->beginTransaction();
            try {
                foreach ($completed as $entry) {
                    if (!is_array($entry) || (int)($entry['id'] ?? 0) <= 0 || (int)($entry['revision'] ?? 0) <= 0) continue;
                    $delete = $this->db->getQueryBuilder();
                    $deleted += $delete->delete('eva_ai_dirty_index')
                        ->where($delete->expr()->eq('id', $delete->createNamedParameter((int)$entry['id'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($delete->expr()->eq('user_id', $delete->createNamedParameter($userId)))
                        ->andWhere($delete->expr()->eq('revision', $delete->createNamedParameter((int)$entry['revision'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($delete->expr()->eq('status', $delete->createNamedParameter('running')))
                        ->executeStatement();
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
            return $deleted;
        });
    }

    /**
     * Return failed claims to the queue or dead-letter them after the budget.
     * A newer event increments the revision, making the old failure a no-op.
     *
     * @param list<array<string,mixed>> $failed
     * @return array{requeued:int,exhausted:int,superseded:int}
     */
    public function requeueFailed(string $userId, array $failed, int $maxAttempts = 5, int $retryDelay = 300): array {
        $counts = ['requeued' => 0, 'exhausted' => 0, 'superseded' => 0];
        if ($userId === '' || $failed === []) return $counts;
        return $this->withUserLock($userId, function () use ($userId, $failed, $maxAttempts, $retryDelay, $counts): array {
            $now = time();
            $this->db->beginTransaction();
            try {
                foreach ($failed as $entry) {
                    if (!is_array($entry) || (int)($entry['id'] ?? 0) <= 0 || (int)($entry['revision'] ?? 0) <= 0) {
                        $counts['superseded']++;
                        continue;
                    }
                    $read = $this->db->getQueryBuilder();
                    $read->select('attempts')->from('eva_ai_dirty_index')
                        ->where($read->expr()->eq('id', $read->createNamedParameter((int)$entry['id'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($read->expr()->eq('user_id', $read->createNamedParameter($userId)))
                        ->andWhere($read->expr()->eq('revision', $read->createNamedParameter((int)$entry['revision'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($read->expr()->eq('status', $read->createNamedParameter('running')));
                    $result = $read->executeQuery();
                    try { $row = $result->fetch(); } finally { $result->closeCursor(); }
                    if ($row === false) {
                        $counts['superseded']++;
                        continue;
                    }
                    $attempts = max(0, (int)$row['attempts']) + 1;
                    $exhausted = $attempts >= max(1, $maxAttempts);
                    $update = $this->db->getQueryBuilder();
                    $update->update('eva_ai_dirty_index')
                        ->set('status', $update->createNamedParameter($exhausted ? 'failed' : 'pending'))
                        ->set('attempts', $update->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
                        ->set('available_at', $update->createNamedParameter($now + ($exhausted ? 0 : max(1, $retryDelay)), IQueryBuilder::PARAM_INT))
                        ->set('lease_until', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                        ->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                        ->where($update->expr()->eq('id', $update->createNamedParameter((int)$entry['id'], IQueryBuilder::PARAM_INT)))
                        ->andWhere($update->expr()->eq('revision', $update->createNamedParameter((int)$entry['revision'], IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                    $counts[$exhausted ? 'exhausted' : 'requeued']++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
            return $counts;
        });
    }

    public function pending(string $userId): int {
        if ($userId === '') return 0;
        $query = $this->db->getQueryBuilder();
        $query->selectAlias($query->createFunction('COUNT(*)'), 'pending_count')
            ->from('eva_ai_dirty_index')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->in('status', $query->createNamedParameter(['pending', 'running'], IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $query->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return max(0, (int)($row['pending_count'] ?? 0)) + $this->legacyRemaining($userId);
    }

    /** Seconds until the next claim can run; null means the queue is empty. */
    public function secondsUntilNext(string $userId): ?int {
        if ($userId === '') return null;
        if ($this->legacyRemaining($userId) > 0) return 0;
        $times = [];
        foreach ([['pending', 'available_at'], ['running', 'lease_until']] as [$status, $column]) {
            $query = $this->db->getQueryBuilder();
            $query->selectAlias($query->createFunction('MIN(' . $column . ')'), 'next_at')
                ->from('eva_ai_dirty_index')
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
                ->andWhere($query->expr()->eq('status', $query->createNamedParameter($status)));
            $result = $query->executeQuery();
            try { $row = $result->fetch(); } finally { $result->closeCursor(); }
            if (isset($row['next_at']) && $row['next_at'] !== null) $times[] = max(0, (int)$row['next_at'] - time());
        }
        return $times === [] ? null : min($times);
    }

    /** @return list<string> Users with due events or abandoned claims. */
    public function dueUserIds(int $limit = 100): array {
        $now = time();
        $query = $this->db->getQueryBuilder();
        $query->selectDistinct('user_id')->from('eva_ai_dirty_index')
            ->where($query->expr()->orX(
                $query->expr()->andX(
                    $query->expr()->eq('status', $query->createNamedParameter('pending')),
                    $query->expr()->lte('available_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                ),
                $query->expr()->andX(
                    $query->expr()->eq('status', $query->createNamedParameter('running')),
                    $query->expr()->lte('lease_until', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                ),
            ))
            ->orderBy('user_id', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)));
        $result = $query->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        return array_values(array_filter(
            array_map(static fn(array $row): string => trim((string)$row['user_id']), $rows),
            static fn(string $userId): bool => $userId !== '',
        ));
    }

    /** Delete both current SQL queue rows and any not-yet-imported legacy file. */
    public function deleteUserData(string $userId): void {
        if ($userId === '') return;
        $this->withUserLock($userId, function () use ($userId): void {
            foreach (['eva_ai_dirty_index', 'eva_ai_dirty_meta'] as $table) {
                $delete = $this->db->getQueryBuilder();
                $delete->delete($table)->where($delete->expr()->eq('user_id', $delete->createNamedParameter($userId)))->executeStatement();
            }
            $this->legacyFileFor($userId)?->delete();
        });
    }

    private function mark(string $userId, string $key, array $entry): void {
        if ($userId === '') return;
        $this->withUserLock($userId, function () use ($userId, $key, $entry): void {
            $eventKey = hash('sha256', $key);
            $now = time();
            $read = $this->db->getQueryBuilder();
            $read->select('id', 'revision')->from('eva_ai_dirty_index')
                ->where($read->expr()->eq('user_id', $read->createNamedParameter($userId)))
                ->andWhere($read->expr()->eq('event_key', $read->createNamedParameter($eventKey)));
            $result = $read->executeQuery();
            try { $existing = $result->fetch(); } finally { $result->closeCursor(); }
            if ($existing !== false) {
                $update = $this->db->getQueryBuilder();
                $update->update('eva_ai_dirty_index')
                    ->set('kind', $update->createNamedParameter($entry['kind']))
                    ->set('file_id', $update->createNamedParameter($entry['fileId'], IQueryBuilder::PARAM_INT))
                    ->set('path', $update->createNamedParameter($entry['path']))
                    ->set('old_path', $update->createNamedParameter($entry['oldPath']))
                    ->set('attempts', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->set('revision', $update->createNamedParameter((int)$existing['revision'] + 1, IQueryBuilder::PARAM_INT))
                    ->set('status', $update->createNamedParameter('pending'))
                    ->set('available_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->set('lease_until', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                return;
            }
            $this->insertEntryLocked($userId, $eventKey, $entry, 0, false);
        });
    }

    private function insertEntryLocked(string $userId, string $eventKey, array $entry, int $attempts, bool $onlyIfMissing): void {
        if ($onlyIfMissing) {
            $read = $this->db->getQueryBuilder();
            $read->select('id')->from('eva_ai_dirty_index')
                ->where($read->expr()->eq('user_id', $read->createNamedParameter($userId)))
                ->andWhere($read->expr()->eq('event_key', $read->createNamedParameter($eventKey)));
            $result = $read->executeQuery();
            try { $existing = $result->fetch(); } finally { $result->closeCursor(); }
            if ($existing !== false) return;
        }
        $now = time();
        $exhausted = $attempts >= 5;
        $insert = $this->db->getQueryBuilder();
        $insert->insert('eva_ai_dirty_index')->values([
            'user_id' => $insert->createNamedParameter($userId),
            'event_key' => $insert->createNamedParameter($eventKey),
            'kind' => $insert->createNamedParameter($entry['kind']),
            'file_id' => $insert->createNamedParameter($entry['fileId'], IQueryBuilder::PARAM_INT),
            'path' => $insert->createNamedParameter($entry['path']),
            'old_path' => $insert->createNamedParameter($entry['oldPath']),
            'attempts' => $insert->createNamedParameter(max(0, $attempts), IQueryBuilder::PARAM_INT),
            'revision' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'status' => $insert->createNamedParameter($exhausted ? 'failed' : 'pending'),
            'available_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'lease_until' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /** Import a bounded slice without losing work if AppData replacement is interrupted. */
    private function importLegacyLocked(string $userId, int $limit): void {
        $file = $this->legacyFileFor($userId);
        if ($file === null) {
            $this->deleteLegacyCursor($userId);
            return;
        }
        $raw = $file->getContent();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('eva_ai discarded a malformed legacy file-change queue', ['userId' => $userId]);
            $file->delete();
            $this->deleteLegacyCursor($userId);
            return;
        }
        $entries = array_values($decoded);
        $fingerprint = hash('sha256', $raw);
        $cursor = $this->legacyCursor($userId);
        $offset = $cursor !== null && hash_equals((string)$cursor['legacy_hash'], $fingerprint)
            ? max(0, (int)$cursor['legacy_offset'])
            : 0;
        if ($offset >= count($entries)) {
            $file->delete();
            $this->deleteLegacyCursor($userId);
            return;
        }

        $batch = array_slice($entries, $offset, max(1, $limit));
        $nextOffset = $offset + count($batch);
        $this->db->beginTransaction();
        try {
            foreach ($batch as $legacy) {
                $entry = $this->normalizeLegacyEntry($legacy);
                if ($entry === null) continue;
                $key = $this->keyFor($entry);
                if ($key === null) continue;
                $this->insertEntryLocked($userId, hash('sha256', $key), $entry, (int)($entry['attempts'] ?? 0), true);
            }
            $this->saveLegacyCursor($userId, $fingerprint, $nextOffset);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        if ($nextOffset >= count($entries)) {
            $file->delete();
            $this->deleteLegacyCursor($userId);
        } else {
            // If this write fails, the SQL cursor skips the already imported
            // prefix on retry. If it succeeds and the process stops before the
            // next cursor update, the changed file hash safely restarts at zero.
            $file->putContent(json_encode(array_slice($entries, $nextOffset), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    private function saveLegacyCursor(string $userId, string $hash, int $offset): void {
        $read = $this->db->getQueryBuilder();
        $read->select('user_id')->from('eva_ai_dirty_meta')->where($read->expr()->eq('user_id', $read->createNamedParameter($userId)));
        $result = $read->executeQuery();
        try { $exists = $result->fetch() !== false; } finally { $result->closeCursor(); }
        if ($exists) {
            $query = $this->db->getQueryBuilder();
            $query->update('eva_ai_dirty_meta')
                ->set('legacy_hash', $query->createNamedParameter($hash))
                ->set('legacy_offset', $query->createNamedParameter($offset, IQueryBuilder::PARAM_INT))
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
        } else {
            $query = $this->db->getQueryBuilder();
            $query->insert('eva_ai_dirty_meta')->values([
                'user_id' => $query->createNamedParameter($userId),
                'legacy_hash' => $query->createNamedParameter($hash),
                'legacy_offset' => $query->createNamedParameter($offset, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }
    }

    private function legacyCursor(string $userId): ?array {
        $query = $this->db->getQueryBuilder();
        $query->select('legacy_hash', 'legacy_offset')->from('eva_ai_dirty_meta')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
        $result = $query->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return $row === false ? null : $row;
    }

    private function deleteLegacyCursor(string $userId): void {
        $query = $this->db->getQueryBuilder();
        $query->delete('eva_ai_dirty_meta')->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
    }

    private function legacyRemaining(string $userId): int {
        $file = $this->legacyFileFor($userId);
        if ($file === null) return 0;
        try { $raw = $file->getContent(); } catch (\Throwable) { return 0; }
        $entries = json_decode($raw, true);
        if (!is_array($entries)) return 0;
        $cursor = $this->legacyCursor($userId);
        $offset = $cursor !== null && hash_equals((string)$cursor['legacy_hash'], hash('sha256', $raw))
            ? max(0, (int)$cursor['legacy_offset'])
            : 0;
        return max(0, count($entries) - $offset);
    }

    private function normalizeLegacyEntry(mixed $entry): ?array {
        if (!is_array($entry)) return null;
        $kind = (string)($entry['kind'] ?? '');
        $fileId = max(0, (int)($entry['fileId'] ?? 0));
        $path = (string)($entry['path'] ?? '');
        $oldPath = (string)($entry['oldPath'] ?? '');
        if ($kind === 'file' && $fileId > 0) return ['kind' => $kind, 'fileId' => $fileId, 'path' => '', 'oldPath' => '', 'attempts' => max(0, (int)($entry['attempts'] ?? 0))];
        if ($kind === 'folder-delete') return ['kind' => $kind, 'fileId' => 0, 'path' => $path, 'oldPath' => '', 'attempts' => max(0, (int)($entry['attempts'] ?? 0))];
        if ($kind === 'folder-rename' && $path !== '' && $oldPath !== '') return ['kind' => $kind, 'fileId' => 0, 'path' => $path, 'oldPath' => $oldPath, 'attempts' => max(0, (int)($entry['attempts'] ?? 0))];
        return null;
    }

    private function keyFor(array $entry): ?string {
        return match ($entry['kind'] ?? '') {
            'file' => (int)($entry['fileId'] ?? 0) > 0 ? 'f' . (int)$entry['fileId'] : null,
            'folder-delete' => 'd' . (string)($entry['path'] ?? ''),
            'folder-rename' => (string)($entry['oldPath'] ?? '') !== '' ? 'r' . (string)$entry['oldPath'] : null,
            default => null,
        };
    }

    private function legacyFileFor(string $userId): ?ISimpleFile {
        try {
            $root = $this->appDataFactory->get(AppConfig::APP);
            $folder = $root->getFolder('dirty');
            $name = substr(hash('sha256', $userId), 0, 40) . '.json';
            return $folder->fileExists($name) ? $folder->getFile($name) : null;
        } catch (NotFoundException) {
            return null;
        }
    }

    private function withUserLock(string $userId, callable $callback): mixed {
        $path = 'eva_ai/dirty-index/' . substr(hash('sha256', $userId), 0, 40);
        $acquired = false;
        try {
            $this->locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, 'EVA dirty index queue');
            $acquired = true;
            return $callback();
        } finally {
            if ($acquired) $this->locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}
