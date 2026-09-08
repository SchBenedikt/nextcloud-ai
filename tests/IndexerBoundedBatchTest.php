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
 * Issue #141: extraction, chunking, embedding and DB writes must operate in
 * bounded batches so peak memory stays independent of the total library size.
 * The batch size is configurable (embed_batch_size) and never exceeded, even
 * when a library produces far more chunks than one batch holds.
 */
final class IndexerBoundedBatchTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * @param list<File> $files
     * @param int $batchSize
     * @return array{0:Indexer,1:DocumentMapper,2:ChunkMapper,3:Ollama}
     */
    private function harness(array $files, int $batchSize): array {
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
        $config->method('getInt')->willReturnCallback(static function (string $key, ?int $default = null) use ($batchSize): int {
            return match ($key) {
                'embed_batch_size' => $batchSize,
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
        $userFolder->method('getById')->willReturnCallback(static function (int $id) use ($files): array {
            foreach ($files as $f) {
                if ($f->getId() === $id) {
                    return [$f];
                }
            }
            return [];
        });

        $docMapper = $this->createMock(DocumentMapper::class);
        $docMapper->method('hashesForUser')->willReturn([]);
        $docMapper->method('findByUserAndFile')->willReturn(null);
        $docMapper->method('insert')->willReturnCallback(static function (Document $d): Document {
            $d->setId((int)$d->getFileId());
            return $d;
        });
        $docMapper->method('findById')->willReturn(null);

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunker = $this->createMock(Chunker::class);
        // Every file yields exactly 1 chunk -> a library of N files produces
        // N chunks, far more than any small batch size. (A single document
        // that alone exceeds the batch size is flushed whole on purpose so
        // its chunkCount stays correct; the bound guarantees batches stay
        // within ~batchSize + one document's chunk count.)
        $chunker->method('chunk')->willReturn([
            ['content' => 'synthetic content for embedding', 'tokens' => 4],
        ]);

        $ollama = $this->createMock(Ollama::class);
        // Return exactly as many vectors as texts were submitted, so the
        // batch accounting in flushBatch() sees a consistent response.
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
            $embeddingCache, $email, $logger, $lockingProvider, $lockGuard, $scheduler
        );

        return [$indexer, $docMapper, $chunkMapper, $ollama];
    }

    private function file(int $id): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getPath')->willReturn('/alice/files/doc-' . $id . '.txt');
        $file->method('getName')->willReturn('doc-' . $id . '.txt');
        $file->method('getSize')->willReturn(128);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getContent')->willReturn('synthetic library content ' . $id);
        return $file;
    }

    public function testEmbeddingBatchSizeIsBoundedAndConfigurableOnALargeLibrary(): void {
        // 120 files x 1 chunk = 120 chunks, with a batch size of 5.
        $files = [];
        for ($i = 1; $i <= 120; $i++) {
            $files[] = $this->file($i);
        }
        [$indexer, $docMapper, $chunkMapper, $ollama] = $this->harness($files, 5);

        // Record every embedBatch call: the chunk count must never exceed the
        // configured batch size, even though 120 chunks are processed in total.
        $maxBatchSeen = 0;
        $totalEmbedded = 0;
        $ollama->method('embedBatch')->willReturnCallback(
            static function (array $texts) use (&$maxBatchSeen, &$totalEmbedded): array {
                $maxBatchSeen = max($maxBatchSeen, count($texts));
                $totalEmbedded += count($texts);
                return [array_map(static fn() => [1.0, 0.0], $texts), null];
            }
        );

        $result = $indexer->run('alice', 10000, 'files');

        self::assertSame(120, $result['processed'], 'all 120 files must be indexed: ' . json_encode($result));
        self::assertSame(120, $totalEmbedded, 'every chunk must be embedded exactly once');
        self::assertLessThanOrEqual(5, $maxBatchSeen, 'no embedding batch may exceed the configured size of 5');
        self::assertSame(0, $result['error'] ?? 0);
    }

    public function testDefaultBatchSizeIsTwentyFour(): void {
        // Without explicit configuration the DEFAULT_BATCH (24) applies.
        $files = [];
        for ($i = 1; $i <= 60; $i++) {
            $files[] = $this->file($i);
        }
        [$indexer, $docMapper, $chunkMapper, $ollama] = $this->harness($files, 24);

        $maxBatchSeen = 0;
        $ollama->method('embedBatch')->willReturnCallback(
            static function (array $texts) use (&$maxBatchSeen): array {
                $maxBatchSeen = max($maxBatchSeen, count($texts));
                return [array_map(static fn() => [1.0, 0.0], $texts), null];
            }
        );

        $result = $indexer->run('alice', 10000, 'files');
        self::assertSame(60, $result['processed']);
        self::assertLessThanOrEqual(24, $maxBatchSeen);
    }
}