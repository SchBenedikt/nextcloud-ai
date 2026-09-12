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
 * The full-pass fingerprint fast path: a file whose stored mtime+size match
 * the current values is skipped without re-reading or re-parsing its content.
 * This is what keeps incremental runs fast on large libraries. Renames and
 * touches (which preserve mtime) must still refresh the stored metadata, and
 * any real change must fall through to the full extraction path.
 */
final class IndexerFastPathTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * @param list<File> $files
     * @param array<int,?Document> $stored fileId => existing document (or null)
     * @param (callable(Folder):void)|null $configureUserFolder lets a test set
     *        its own expectations on the user folder (e.g. getById never called)
     */
    private function harness(array $files, array $stored, ?callable $configureUserFolder = null): array {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $default = null): string {
            return match ($key) {
                'scope_path' => '',
                'exclude_paths' => '',
                'index_cancel_requested' => '0',
                'index_running' => '0',
                'index_run_id' => '',
                default => $default ?? '',
            };
        });
        $config->method('getInt')->willReturnCallback(static function (string $key, ?int $default = null): int {
            return match ($key) {
                'embed_batch_size' => 24,
                'max_file_size' => 20971520,
                'max_files_per_run' => 10000,
                default => $default ?? 0,
            };
        });
        $config->method('setUserId');
        $config->method('set');
        $config->method('tryClaimIndex')->willReturn(true);
        $config->method('hasIndexEnrollment')->willReturn(false);
        $config->method('isIndexEnrolled')->willReturn(true);

        $rootFolder = $this->createMock(IRootFolder::class);
        $userFolder = $this->createMock(Folder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $userFolder->method('getDirectoryListing')->willReturn($files);
        if ($configureUserFolder !== null) {
            $configureUserFolder($userFolder);
        } else {
            $userFolder->method('getById')->willReturnCallback(static function (int $id) use ($files): array {
                foreach ($files as $f) {
                    if ($f->getId() === $id) {
                        return [$f];
                    }
                }
                return [];
            });
        }

        $docMapper = $this->createMock(DocumentMapper::class);
        $docMapper->method('hashesForUser')->willReturnCallback(static function () use ($stored): array {
            $map = [];
            foreach ($stored as $fileId => $doc) {
                $map[$fileId] = $doc !== null ? (string)$doc->getContentHash() : '';
            }
            return $map;
        });
        // The pass reads all per-file state in one query; the mock mirrors the
        // same $stored fixture so the fast path can be exercised without a DB.
        $docMapper->method('stateForUser')->willReturnCallback(static function () use ($stored): array {
            $map = [];
            foreach ($stored as $fileId => $doc) {
                if ($doc === null) {
                    continue;
                }
                $map[$fileId] = [
                    'id' => (int)$doc->getId(),
                    'content_hash' => (string)$doc->getContentHash(),
                    'size' => (int)$doc->getSize(),
                    'file_mtime' => (int)$doc->getFileMtime(),
                    'path' => (string)$doc->getPath(),
                    'name' => (string)$doc->getName(),
                    'mime' => (string)($doc->getMime() ?? ''),
                ];
            }
            return $map;
        });
        $docMapper->method('findByUserAndFile')->willReturnCallback(
            static function (string $userId, int $fileId) use ($stored): ?Document {
                return $stored[$fileId] ?? null;
            }
        );
        $docMapper->method('insert')->willReturnCallback(static function (Document $d): Document {
            $d->setId((int)$d->getFileId());
            return $d;
        });
        $docMapper->method('findById')->willReturn(null);

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunker = $this->createMock(Chunker::class);
        $chunker->method('chunk')->willReturn([
            ['content' => 'synthetic content for embedding', 'tokens' => 4],
        ]);

        $ollama = $this->createMock(Ollama::class);
        $ollama->method('embedBatch')->willReturnCallback(static function (array $texts): array {
            return [array_map(static fn() => [1.0, 0.0], $texts), null];
        });
        $ollama->method('lastEmbeddingStats')->willReturn(['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0]);

        $logger = $this->createMock(LoggerInterface::class);
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $lockGuard = $this->createMock(LockGuard::class);
        $embeddingCache = $this->createMock(EmbeddingCache::class);
        $email = $this->createMock(EmailService::class);

        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('acquireSlot')->willReturn(['state' => 'running', 'position' => 0]);
        $indexer = new Indexer(
            $config, $rootFolder, $docMapper, $chunkMapper, $chunker, $ollama,
            $embeddingCache, $email, $this->talkTranscripts(), $logger, $lockingProvider, $lockGuard, $scheduler
        );

        return [$indexer, $docMapper, $chunkMapper, $ollama];
    }

    private function file(int $id, int $mtime, string $content, int $size = 128): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getPath')->willReturn('/alice/files/doc-' . $id . '.txt');
        $file->method('getName')->willReturn('doc-' . $id . '.txt');
        $file->method('getSize')->willReturn($size);
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getContent')->willReturn($content);
        return $file;
    }

    private function storedDoc(int $fileId, int $mtime, int $size, string $hash): Document {
        $doc = new Document();
        $doc->setId($fileId);
        $doc->setUserId('alice');
        $doc->setFileId($fileId);
        $doc->setPath('doc-' . $fileId . '.txt');
        $doc->setName('doc-' . $fileId . '.txt');
        $doc->setMime('text/plain');
        $doc->setSize($size);
        $doc->setFileMtime($mtime);
        $doc->setContentHash($hash);
        $doc->setIndexedAt(time() - 3600);
        return $doc;
    }

    public function testUnchangedFileIsSkippedWithoutReadingItsContent(): void {
        $fileA = $this->file(1, 1000, 'old library content one');
        $fileA->expects($this->never())->method('getContent');
        $storedA = $this->storedDoc(1, 1000, 128, md5('old library content one'));

        [$indexer, $docMapper] = $this->harness([$fileA], [1 => $storedA]);

        $docMapper->expects($this->never())->method('insert');
        // Nothing changed, not even the stored path - so a re-scan of a settled
        // library writes nothing. A database write per file was the second half
        // of the per-file cost on a full pass.
        $docMapper->expects($this->never())->method('update');

        $result = $indexer->run('alice', 10000, 'files');

        self::assertSame(0, $result['processed'], 'nothing may be re-embedded');
        self::assertSame(1, $result['skipped'], 'the unchanged file is skipped via the fast path');
        self::assertNull($result['error']);
        // Metadata was already correct, so the stored row still matches.
        self::assertSame(1000, $storedA->getFileMtime());
    }

    /**
     * A rename preserves mtime and size, so the fast path still applies - but
     * the stored path must be updated, or search would report the old location.
     */
    public function testRenamedFileRefreshesStoredMetadata(): void {
        $fileA = $this->file(1, 1000, 'old library content one');
        $fileA->expects($this->never())->method('getContent');
        $storedA = $this->storedDoc(1, 1000, 128, md5('old library content one'));
        $storedA->setPath('old-name.txt');

        [$indexer, $docMapper] = $this->harness([$fileA], [1 => $storedA]);

        $docMapper->expects($this->never())->method('insert');
        $docMapper->expects($this->once())->method('update')->with($storedA);

        $result = $indexer->run('alice', 10000, 'files');

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('doc-1.txt', $storedA->getPath(), 'the rename must reach the database');
    }

    public function testChangedMtimeFallsThroughToFullReindex(): void {
        $fileB = $this->file(2, 2000, 'changed library content two');
        $storedB = $this->storedDoc(2, 1000, 128, md5('old content two'));

        [$indexer, $docMapper] = $this->harness([$fileB], [2 => $storedB]);

        $docMapper->expects($this->once())->method('insert');

        $result = $indexer->run('alice', 10000, 'files');

        self::assertSame(1, $result['processed'], 'the changed file must be re-extracted and re-embedded');
        self::assertSame(0, $result['skipped']);
        self::assertNull($result['error']);
    }

    /**
     * A large settled library must not pay a file-node lookup per file. The
     * fingerprint is known from the directory walk, so an unchanged file is
     * skipped without ever fetching the node.
     */
    public function testUnchangedFileIsSkippedWithoutFetchingTheFileNode(): void {
        $fileA = $this->file(1, 1000, 'old library content one');
        $fileA->expects($this->never())->method('getContent');
        $storedA = $this->storedDoc(1, 1000, 128, md5('old library content one'));

        [$indexer, $docMapper] = $this->harness([$fileA], [1 => $storedA], function (Folder $folder): void {
            $folder->expects($this->never())->method('getById');
        });

        $docMapper->expects($this->never())->method('insert');
        $docMapper->expects($this->never())->method('update');

        $result = $indexer->run('alice', 10000, 'files');

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['skipped']);
        self::assertNull($result['error']);
    }

    public function testEmptyStoredHashDisablesTheFastPath(): void {
        // After a config change clearHashesForUser() blanks content_hash; the
        // fast path must not trust the fingerprint then, so a rebuild happens.
        $file = $this->file(3, 3000, 'content three');
        $stored = $this->storedDoc(3, 3000, 128, '');

        [$indexer, $docMapper] = $this->harness([$file], [3 => $stored]);

        $docMapper->expects($this->once())->method('insert');

        $result = $indexer->run('alice', 10000, 'files');
        self::assertSame(1, $result['processed'], 'empty stored hash forces a re-embed');
    }

    /** Talk indexing is not exercised here; a mock keeps the constructor honest. */
    private function talkTranscripts(): \OCA\EvaAi\Service\TalkTranscriptService
    {
        $mock = $this->createMock(\OCA\EvaAi\Service\TalkTranscriptService::class);
        $mock->method('isAvailable')->willReturn(false);
        $mock->method('roomsForUser')->willReturn([]);
        return $mock;
    }
}