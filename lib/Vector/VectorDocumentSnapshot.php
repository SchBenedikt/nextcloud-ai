<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Immutable canonical SQL source stamp, captured before out-of-transaction embedding. */
final readonly class VectorDocumentSnapshot {
    private function __construct(public int $id, public string $userId, public array $metadata, public string $stamp) {}

    public static function fromRow(array $row): self {
        $metadata = ['id' => (int)$row['id'], 'user_id' => (string)$row['user_id'],
            'file_id' => (int)$row['file_id'], 'path' => (string)$row['path'], 'name' => (string)$row['name'],
            'source' => (string)$row['source'], 'content_hash' => (string)$row['content_hash'],
            'file_mtime' => (int)$row['file_mtime'], 'size' => (int)$row['size'], 'mime' => $row['mime']];
        if ($metadata['id'] < 1 || $metadata['user_id'] === '' || mb_strlen($metadata['user_id']) > 64
            || !in_array($metadata['source'], ['files', 'mail', 'talk'], true)
            || preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $metadata['content_hash']) !== 1
            || strlen($metadata['path']) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $metadata['path'])) throw new VectorStoreException('invalid_source_snapshot');
        $stamp = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return new self($metadata['id'], $metadata['user_id'], $metadata, $stamp);
    }
}
