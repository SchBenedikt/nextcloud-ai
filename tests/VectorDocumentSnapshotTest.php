<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Vector\{VectorDocumentSnapshot, VectorStoreException};
use PHPUnit\Framework\TestCase;

final class VectorDocumentSnapshotTest extends TestCase {
    public function testExistingIndexerHashesProduceStableSourceStamps(): void {
        foreach (['files', 'mail', 'talk'] as $source) {
            $row = $this->row($source);
            $snapshot = VectorDocumentSnapshot::fromRow($row);
            self::assertSame(md5('Indexed source'), $snapshot->metadata['content_hash']);
            self::assertSame($snapshot->stamp, VectorDocumentSnapshot::fromRow($row)->stamp);
            $row['content_hash'] = md5('Changed source');
            self::assertNotSame($snapshot->stamp, VectorDocumentSnapshot::fromRow($row)->stamp);
        }
    }

    public function testMalformedSourceHashIsRejected(): void {
        $row = $this->row('files');
        $row['content_hash'] = str_repeat('a', 33);
        $this->expectException(VectorStoreException::class);
        VectorDocumentSnapshot::fromRow($row);
    }

    private function row(string $source): array {
        return ['id' => 1, 'user_id' => 'alice', 'file_id' => 11, 'path' => $source === 'files' ? 'Documents/notes.txt' : $source . '://notes',
            'name' => 'notes', 'source' => $source, 'content_hash' => md5('Indexed source'),
            'file_mtime' => 1700000000, 'size' => 14, 'mime' => 'text/plain'];
    }
}
