<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;

/**
 * Per-user queue of filesystem changes that still need incremental indexing
 * (Issue #79). File hooks mark entries here; a debounced background job drains
 * the whole list per user, so sync-client bursts are batched instead of each
 * write triggering an individual embedding run.
 *
 * Entries are keyed so repeated events for the same file/path collapse:
 *   - f<fileId>       file created/written/deleted (resolved by id)
 *   - d<path>         folder deleted (purge docs under the path)
 *   - r<oldPath>      folder renamed (re-point stored paths)
 */
class DirtyIndexStore {
    public function __construct(private IAppDataFactory $appDataFactory) {
    }

    /** @return list<array{kind:string,fileId:int,path:string,oldPath:string}> */
    public function entries(string $userId): array {
        $file = $this->fileFor($userId);
        try {
            $raw = $file->getContent();
        } catch (\Throwable $e) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function markFile(string $userId, int $fileId): void {
        $this->mark($userId, 'f' . $fileId, ['kind' => 'file', 'fileId' => $fileId, 'path' => '', 'oldPath' => '']);
    }

    public function markFolderDeleted(string $userId, string $path): void {
        $this->mark($userId, 'd' . $path, ['kind' => 'folder-delete', 'fileId' => 0, 'path' => $path, 'oldPath' => '']);
    }

    public function markFolderRenamed(string $userId, string $oldPath, string $newPath): void {
        $this->mark($userId, 'r' . $oldPath, ['kind' => 'folder-rename', 'fileId' => 0, 'path' => $newPath, 'oldPath' => $oldPath]);
    }

    /**
     * Atomically read + clear the queue. Entries are written back when the
     * caller processes only a bounded slice, so a huge backlog makes progress
     * across several job runs instead of timing out a single worker.
     *
     * @return list<array{kind:string,fileId:int,path:string,oldPath:string}>
     */
    public function drain(string $userId, int $limit = 300): array {
        $file = $this->fileFor($userId);
        $entries = $this->entries($userId);
        if ($entries === []) {
            return [];
        }
        $taken = array_slice($entries, 0, max(1, $limit));
        $rest = array_slice($entries, count($taken));
        $this->write($file, $rest);
        return $taken;
    }

    public function pending(string $userId): int {
        return count($this->entries($userId));
    }

    private function mark(string $userId, string $key, array $entry): void {
        $file = $this->fileFor($userId);
        $entries = $this->entries($userId);
        // Replace an existing entry for the same key (e.g. a write following
        // a create) instead of accumulating duplicates.
        $kept = [];
        foreach ($entries as $e) {
            $k = $this->keyFor($e);
            if ($k === $key) {
                continue;
            }
            $kept[] = $e;
        }
        $kept[] = $entry;
        $this->write($file, $kept);
    }

    private function keyFor(array $entry): string {
        $kind = (string)($entry['kind'] ?? '');
        $fileId = (int)($entry['fileId'] ?? 0);
        $path = (string)($entry['path'] ?? '');
        $oldPath = (string)($entry['oldPath'] ?? '');
        if ($kind === 'file') {
            return 'f' . $fileId;
        }
        if ($kind === 'folder-delete') {
            return 'd' . $path;
        }
        return 'r' . $oldPath;
    }

    private function write(ISimpleFile $file, array $entries): void {
        $file->putContent(json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fileFor(string $userId): ISimpleFile {
        $root = $this->appDataFactory->get('eva_ai');
        try {
            $folder = $root->getFolder('dirty');
        } catch (NotFoundException $e) {
            $folder = $root->newFolder('dirty');
        }
        $name = substr(hash('sha256', $userId), 0, 40) . '.json';
        if (!$folder->fileExists($name)) {
            return $folder->newFile($name, '[]');
        }
        return $folder->getFile($name);
    }
}