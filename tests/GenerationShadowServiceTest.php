<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\{GenerationBuildRepository,GenerationChunkRepository,GenerationVerificationRepository,VectorGenerationRepository,VectorOutboxRepository};
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Vector\{GenerationEmbeddingProvider,GenerationShadowService,QdrantVectorStore,VectorGeneration,VectorSourceAccess,VectorStoreException};
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerationShadowServiceTest extends TestCase {
    private function fixture(bool $certificate=true,int $sqlRevision=5,int $remoteRevision=5): array {
        $generation=new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'embed',str_repeat('b',64),2,900,120);
        $config=$this->createMock(AppConfig::class);$config->method('userId')->willReturn('prior-user');
        $builds=$this->createMock(GenerationBuildRepository::class);$builds->method('available')->willReturn(true);
        $chunks=$this->createMock(GenerationChunkRepository::class);
        $chunks->method('shadowDenseRanking')->willReturn(['points'=>[['id'=>11,'revision'=>$sqlRevision,'score'=>1.0]],'scanned'=>1,'duration_ms'=>2]);
        $chunks->method('shadowRevisions')->willReturn($sqlRevision===5?[11=>$sqlRevision]:[]);
        $embeddings=$this->createMock(GenerationEmbeddingProvider::class);
        $embeddings->expects($certificate?self::once():self::never())->method('embed')->willReturnCallback(
            static function(VectorGeneration$g,array$texts,\Closure$guard):array{$guard();return [[1.0,0.0]];});
        $verifications=$this->createMock(GenerationVerificationRepository::class);$verifications->method('certificateCurrent')->willReturn($certificate);
        $generations=$this->createMock(VectorGenerationRepository::class);$generations->method('find')->willReturn($generation);
        $generations->method('target')->willReturn(str_repeat('c',64));
        $outbox=$this->createMock(VectorOutboxRepository::class);$outbox->method('pointsEnabled')->willReturn(true);
        $access=$this->createMock(VectorSourceAccess::class);
        $store=$this->createMock(QdrantVectorStore::class);$store->method('forTarget')->willReturn($store);
        $store->method('search')->willReturn([['chunkId'=>11,'revision'=>$remoteRevision,'score'=>0.99]]);
        $locks=$this->createMock(ILockingProvider::class);
        $locks->expects($certificate?self::once():self::never())->method('acquireLock');
        $locks->expects($certificate?self::once():self::never())->method('releaseLock');
        $service=new GenerationShadowService($config,$builds,$chunks,$embeddings,$verifications,$generations,$outbox,$access,$store,$locks,$this->createMock(LoggerInterface::class));
        return[$service,$generation];
    }

    public function testVerifiedComparisonReturnsOnlyAggregateRankingMetrics(): void {
        [$service]=$this->fixture();$result=$service->compare('alice',str_repeat('a',32),'short query');
        self::assertSame(1,$result['overlap_at_k']);self::assertSame(1.0,$result['recall_at_k']);
        self::assertSame(1.0,$result['precision_at_k']);self::assertTrue($result['top_result_matches']);
        self::assertFalse($result['retrieval_changed']);self::assertArrayNotHasKey('query',$result);self::assertArrayNotHasKey('ids',$result);
    }

    public function testUnverifiedGenerationCannotStartShadowTransfer(): void {
        [$service]=$this->fixture(false);
        try{$service->compare('alice',str_repeat('a',32),'short query');self::fail('Expected the unverified generation to be rejected.');}
        catch(VectorStoreException$error){self::assertSame('shadow_prerequisites_missing',$error->reason);}
    }

    public function testStaleRemoteRevisionFailsClosed(): void {
        [$service]=$this->fixture(true,5,4);
        try{$service->compare('alice',str_repeat('a',32),'short query');self::fail('Expected the stale point to be rejected.');}
        catch(VectorStoreException$error){self::assertSame('shadow_stale_point',$error->reason);}
    }
}
