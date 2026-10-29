<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\{VectorOutboxRepository, VectorGenerationRepository};
use OCA\EvaAi\Vector\{VectorGeneration, VectorErasureWorker, QdrantVectorStore};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VectorErasureGraceTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }

    public function testRetriedClaimWithoutCompletedGraceDefersBeforeDatabaseOrRemoteWork(): void {
        $generation = new VectorGeneration(str_repeat('a', 32), 'alice', 'qdrant', 'ollama', null, 'model', str_repeat('b', 64), 3, 900, 120);
        $outbox = $this->createMock(VectorOutboxRepository::class);
        $outbox->method('workerEnabled')->willReturn(true);
        $outbox->method('claim')->willReturn(['id' => 7, 'lease_token' => str_repeat('c', 32), 'attempts' => 2,
            'operation' => 'delete_generation', 'document_id' => null, 'chunk_id' => null,
            'generation_json' => json_encode($generation->toRow()), 'generation_id' => $generation->id,
            'user_id' => 'alice', 'target_hash' => str_repeat('d', 64), 'erasure_ready_at' => null]);
        $outbox->expects(self::once())->method('deferErasure')->with(7, str_repeat('c', 32), self::isType('int'), null)->willReturn(true);
        $generations = $this->createMock(VectorGenerationRepository::class);
        $generations->expects(self::never())->method('status');
        $store = $this->createMock(QdrantVectorStore::class);
        $store->expects(self::never())->method('forTarget');
        $worker = new VectorErasureWorker($outbox, $generations, $store, $this->createMock(LoggerInterface::class));
        self::assertSame(1, $worker->run(1)['deferred']);
    }
}
