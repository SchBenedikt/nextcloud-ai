<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Lock\ILockingProvider;

/**
 * File IDs survive renames; an unrelated replacement cannot inherit a path grant.
 *
 * Ownership markers are written BEFORE the filesystem action they record: the
 * caller begins a pending record for the target path, performs the action and
 * then finalizes the record with the resulting node. A crash, process kill or
 * storage error between the action and the finalize leaves a truthful pending
 * record instead of a missing grant — reconcile() resolves those records on the
 * next ownership check (Issue #183).
 */
final class FileOwnershipStore {
    private const RESOLVE_SLACK = 3600;

    public function __construct(
        private ISimpleFile $file,
        private Folder $home,
        private ILockingProvider $lockingProvider,
        private string $userId,
    ) {
    }

    public function contains(Node $node): bool {
        return $this->withLock(fn(): bool => in_array($node->getId(), $this->read(), true));
    }

    public function remember(Node $node): void {
        $this->withLock(function () use ($node): void {
            [$ids, $pending] = $this->load();
            $id = $node->getId();
            if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
                $this->write($ids, $pending);
            }
        });
    }

    public function forget(int $id): void {
        $this->withLock(function () use ($id): void {
            [$ids, $pending] = $this->load();
            $filtered = array_values(array_filter($ids, static fn(int $candidate): bool => $candidate !== $id));
            if ($filtered !== $ids) {
                $this->write($filtered, $pending);
            }
        });
    }

    /**
     * Record the intent to create a node at $path before the filesystem action
     * runs. Returns a token that must be finalized (action succeeded) or
     * cancelled (action failed) afterwards.
     */
    public function beginPending(string $path): string {
        return $this->withLock(function () use ($path): string {
            [$ids, $pending] = $this->load();
            $token = bin2hex(random_bytes(12));
            $pending[$token] = ['path' => $path, 'created' => time()];
            $this->write($ids, $pending);
            return $token;
        });
    }

    /** Move a pending record into a durable grant once the action succeeded. */
    public function finalizePending(string $token, Node $node): void {
        $this->withLock(function () use ($token, $node): void {
            [$ids, $pending] = $this->load();
            if (!isset($pending[$token])) {
                return;
            }
            $id = $node->getId();
            if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            unset($pending[$token]);
            $this->write($ids, $pending);
        });
    }

    /** Drop a pending record because the action failed before creating anything. */
    public function cancelPending(string $token): void {
        $this->withLock(function () use ($token): void {
            [$ids, $pending] = $this->load();
            if (isset($pending[$token])) {
                unset($pending[$token]);
                $this->write($ids, $pending);
            }
        });
    }

    /**
     * Resolve pending records left by an interrupted action:
     * - the path now resolves to a node that plausibly was created by the
     *   action (creation time within a slack window) → finalize the grant;
     * - the path does not exist → the action never completed, drop the record;
     * - the path cannot be read or holds an unrelated replacement → keep the
     *   record and report it as unknown. Pending records are never dropped
     *   silently.
     *
     * @return array{finalized:int,dropped:int,unknown:int}
     */
    public function reconcile(): array {
        return $this->withLock(fn(): array => $this->reconcileLocked());
    }

    // Reads may prune stale markers, so they need the same exclusive lock
    // as updates. Use the shared provider to coordinate across app servers.
    private function withLock(callable $operation): mixed {
        $key = 'eva_ai/ownership/' . substr(hash('sha256', $this->userId), 0, 40);
        $this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            return $operation();
        } finally {
            $this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /** @return array{finalized:int,dropped:int,unknown:int} */
    private function reconcileLocked(): array {
        [$ids, $pending] = $this->load();
        if ($pending === []) {
            return ['finalized' => 0, 'dropped' => 0, 'unknown' => 0];
        }
        $finalized = 0;
        $dropped = 0;
        $unknown = 0;
        $kept = [];
        foreach ($pending as $token => $record) {
            $path = (string)($record['path'] ?? '');
            if ($path === '') {
                $unknown++;
                $kept[$token] = $record;
                continue;
            }
            try {
                if (!$this->home->nodeExists($path)) {
                    // The action never completed: nothing was created, so there
                    // is no grant to record.
                    $dropped++;
                    continue;
                }
                $node = $this->home->get($path);
                $created = (int)($record['created'] ?? 0);
                if ($created > 0) {
                    $nodeCreated = (int)$node->getCreationTime();
                    // A replacement file must not inherit a path grant (Issue
                    // #71): only finalize when the node at the path was created
                    // around the time the action ran.
                    if ($nodeCreated > 0 && abs($nodeCreated - $created) > self::RESOLVE_SLACK) {
                        $unknown++;
                        $kept[$token] = $record;
                        continue;
                    }
                }
                $id = $node->getId();
                if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
                $finalized++;
            } catch (\Throwable) {
                // Storage error: leave the record explicitly unknown rather than
                // guessing.
                $unknown++;
                $kept[$token] = $record;
            }
        }
        $this->write($ids, $kept);
        return ['finalized' => $finalized, 'dropped' => $dropped, 'unknown' => $unknown];
    }

    /** @return list<int> */
    private function read(): array {
        // Resolve pending records first so a crash between the filesystem
        // action and its finalize heals the missing grant before the ownership
        // check (Issue #183).
        $this->reconcileLocked();
        [$ids, $pending, $data] = $this->load();
        $filtered = [];
        foreach ($ids as $id) {
            if ($this->home->getById($id) !== []) {
                $filtered[] = $id;
            }
        }
        $canonical = ['version' => 2, 'file_ids' => $filtered];
        if ($pending !== []) {
            $canonical['pending'] = $pending;
        }
        if ($data !== $canonical) {
            $this->write($filtered, $pending);
        }
        return $filtered;
    }

    /**
     * @return array{0:list<int>,1:array<string,array{path:string,created:int}>,2:mixed}
     */
    private function load(): array {
        $data = json_decode($this->file->getContent(), true);
        // Legacy path markers cannot prove who created the current node. Never
        // migrate them by resolving today's path: that would authorize replacements.
        $stored = is_array($data) && ($data['version'] ?? null) === 2 && is_array($data['file_ids'] ?? null)
            ? $data['file_ids'] : [];
        $ids = [];
        foreach ($stored as $id) {
            if (!is_int($id) || $id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
        }
        $pending = [];
        $rawPending = is_array($data) && is_array($data['pending'] ?? null) ? $data['pending'] : [];
        foreach ($rawPending as $token => $record) {
            if (!is_string($token) || $token === '' || !is_array($record)) {
                continue;
            }
            $path = (string)($record['path'] ?? '');
            $created = (int)($record['created'] ?? 0);
            if ($path !== '') {
                $pending[$token] = ['path' => $path, 'created' => $created];
            }
        }
        return [$ids, $pending, $data];
    }

    /** @param list<int> $ids */
    private function write(array $ids, array $pending = []): void {
        $data = ['version' => 2, 'file_ids' => array_values(array_unique($ids))];
        if ($pending !== []) {
            $data['pending'] = $pending;
        }
        $this->file->putContent(json_encode($data, JSON_THROW_ON_ERROR));
    }
}