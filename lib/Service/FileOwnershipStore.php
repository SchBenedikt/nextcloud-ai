<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\SimpleFS\ISimpleFile;

/** File IDs survive renames; an unrelated replacement cannot inherit a path grant. */
final class FileOwnershipStore {
    public function __construct(private ISimpleFile $file, private Folder $home) {
    }

    public function contains(Node $node): bool {
        return in_array($node->getId(), $this->read(), true);
    }

    public function remember(Node $node): void {
        $ids = $this->read();
        $id = $node->getId();
        if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
            $this->write($ids);
        }
    }

    public function forget(int $id): void {
        $this->write(array_values(array_filter($this->read(), static fn(int $candidate): bool => $candidate !== $id)));
    }

    /** @return list<int> */
    private function read(): array {
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
            if ($this->home->getById($id) !== []) {
                $ids[] = $id;
            }
        }
        if ($data !== ['version' => 2, 'file_ids' => $ids]) {
            $this->write($ids);
        }
        return $ids;
    }

    /** @param list<int> $ids */
    private function write(array $ids): void {
        $this->file->putContent(json_encode(['version' => 2, 'file_ids' => $ids], JSON_THROW_ON_ERROR));
    }
}
