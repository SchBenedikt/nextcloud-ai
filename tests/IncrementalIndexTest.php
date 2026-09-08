<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Chunker;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\EmailService;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\Service\Ollama;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for the incremental re-indexing hook path (Issue #79):
 * create/write re-embeds, unchanged content only refreshes metadata, deleted /
 * out-of-scope / excluded / oversized files purge their stale rows.
 */
final class IncrementalIndexTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function file(int $id, string $path, string $content, int $size = 128): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getPath')->willReturn($path);
        $file->method('getName')->willReturn('notes.txt');
        $file->method('getSize')->willReturn($size);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getContent')->willReturn($content);
        return $file;
    }

    /**
     * @return array{0:Indexer,1:DocumentMapper,2:ChunkMapper,3:Ollama,4:Folder}
     */
    private function harness(string $scope = '', string $exclude = '', int $maxSize = 20971520, array $files = [], ?Document $existing = null): array {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $default = null) use ($scope, $exclude): string {
            return match ($key) {
                'scope_path' => $scope,
                'exclude_paths' => $exclude,
                'index_cancel_requested' => '0',
                default => $default ?? '',
            };
        });
        $config->method('getInt')->with('max_file_size', self::anything())->willReturn($maxSize);
        $config->method('setUserId');
        $config->method('set');
        $config->method('hasIndexEnrollment')->willReturn(false);
        $config->method('isIndexEnrolled')->willReturn(true);

        $rootFolder = $this->createMock(IRootFolder::class);
        $userFolder = $this->createMock(Folder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $userFolder->method('getById')->willReturnCallback(fn(int $id) => array_values(array_filter($files, static fn(File $f) => $f->getId() === $id)));

        $docMapper = $this->createMock(DocumentMapper::class);
        $docMapper->method('findByUserAndFile')->willReturn($existing);
        $docMapper->method('insert')->willReturnCallback(static function (Document $d): Document {
            $d->setId(42);
            return $d;
        });
        $docMapper->method('findById')->willReturn(null);

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunker = $this->createMock(Chunker::class);
        $chunker->method('chunk')->willReturn([
            ['content' => 'content one', 'tokens' => 2],
        ]);

        $ollama = $this->createMock(Ollama::class);
        $ollama->method('embedBatch')->willReturn([[[1.0, 0.0]], null]);
        $ollama->method('lastEmbeddingStats')->willReturnCallback(static fn(): array => ['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0]);

        $logger = $this->createMock(LoggerInterface::class);
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $lockGuard = $this->createMock(LockGuard::class);
        $embeddingCache = $this->createMock(EmbeddingCache::class);
        $email = $this->createMock(EmailService::class);

        $indexer = new Indexer(
            $config, $rootFolder, $docMapper, $chunkMapper, $chunker, $ollama,
            $embeddingCache, $email, $logger, $lockingProvider, $lockGuard
        );

        return [$indexer, $docMapper, $chunkMapper, $ollama, $userFolder];
    }

    public function testWriteReindexesTheFileAndEmbedsChunks(): void {
        [$indexer, $docMapper, $chunkMapper, $ollama] = $this->harness(files: [$this->file(7, '/alice/files/notes.txt', 'new content')]);

        $docMapper->expects(self::once())->method('insert');
        $chunkMapper->expects(self::once())->method('insert');

        $result = $indexer->reindexFile('alice', 7);
        self::assertSame(1, $result['processed'], 'result: ' . json_encode($result));
        self::assertSame(1, $result['changed']);
        self::assertNull($result['error']);
    }

    public function testUnchangedContentOnlyRefreshesMetadata(): void {
        $content = 'the same text';
        $existing = new Document();
        $existing->setId(9);
        $existing->setPath('notes.txt');
        $existing->setContentHash(md5($content));
        [$indexer, $docMapper, $chunkMapper, $ollama] = $this->harness(files: [$this->file(9, '/alice/files/notes.txt', $content)], existing: $existing);

        $docMapper->expects(self::once())->method('update');
        $docMapper->expects(self::never())->method('insert');
        $chunkMapper->expects(self::never())->method('insert');
        $ollama->expects(self::never())->method('embedBatch');

        $result = $indexer->reindexFile('alice', 9);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['changed']);
    }

    public function testDeletedFilePurgesIndexRows(): void {
        $existing = new Document();
        $existing->setId(5);
        [$indexer, $docMapper, $chunkMapper] = $this->harness(files: [], existing: $existing);

        $docMapper->expects(self::once())->method('deleteByUserAndFile')->with('alice', 12);
        $chunkMapper->expects(self::once())->method('deleteByDocument')->with(5);

        $result = $indexer->reindexFile('alice', 12);
        self::assertSame(1, $result['deleted']);
    }

    public function testOutOfScopeFilePurgesRows(): void {
        $existing = new Document();
        $existing->setId(3);
        [$indexer, $docMapper] = $this->harness(scope: 'Documents', files: [$this->file(3, '/alice/files/Private/x.txt', 'secret')], existing: $existing);

        $docMapper->expects(self::once())->method('deleteByUserAndFile');
        $result = $indexer->reindexFile('alice', 3);
        self::assertSame(1, $result['deleted']);
    }

    public function testExcludedPathPurgesRows(): void {
        $existing = new Document();
        $existing->setId(4);
        [$indexer, $docMapper] = $this->harness(exclude: 'Secret', files: [$this->file(4, '/alice/files/Secret/x.txt', 'secret')], existing: $existing);

        $docMapper->expects(self::once())->method('deleteByUserAndFile');
        $result = $indexer->reindexFile('alice', 4);
        self::assertSame(1, $result['deleted']);
    }

    public function testOversizedFilePurgesRows(): void {
        $big = 20971521;
        $existing = new Document();
        $existing->setId(6);
        [$indexer, $docMapper] = $this->harness(maxSize: 20971520, files: [$this->file(6, '/alice/files/big.txt', 'big', $big)], existing: $existing);

        $docMapper->expects(self::once())->method('deleteByUserAndFile');
        $result = $indexer->reindexFile('alice', 6);
        self::assertSame(1, $result['deleted']);
    }
}