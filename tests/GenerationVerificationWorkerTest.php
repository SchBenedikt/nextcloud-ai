<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\{GenerationVerificationRepository,VectorGenerationRepository};
use OCA\EvaAi\Vector\{GenerationVerificationWorker,QdrantVectorStore,VectorDocumentSnapshot,VectorGeneration,VectorStoreException};
use PHPUnit\Framework\TestCase;

final class GenerationVerificationWorkerTest extends TestCase {
    private function fixture(array $remote,bool $shouldVerify=true): array {
        $generation=new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'embed',str_repeat('b',64),3,900,120);
        $source=['id'=>4,'user_id'=>'alice','file_id'=>9,'path'=>'Notes/a.txt','name'=>'a.txt','source'=>'files',
            'content_hash'=>md5('source'),'file_mtime'=>123,'size'=>6,'mime'=>'text/plain'];
        $sourceStamp=VectorDocumentSnapshot::fromRow($source)->stamp;
        $state=['user_id'=>'alice','generation_id'=>$generation->id,'status'=>'running','phase'=>'points','lease_token'=>str_repeat('c',32),
            'lease_until'=>time()+100,'target_hash'=>str_repeat('d',64),'upper_document_id'=>4,'after_chunk_id'=>0,'after_document_id'=>0,
            'expected_count'=>1,'checked_count'=>0,'sample_count'=>0,'verification_hash'=>hash('sha256','seed')];
        $repo=$this->createMock(GenerationVerificationRepository::class);
        $repo->method('claim')->willReturnCallback(static function()use(&$state):array{$state['lease_token']=str_repeat('c',32);return $state;});
        $repo->method('find')->willReturnCallback(static function()use(&$state):array{return $state;});
        $repo->method('assertLease')->willReturnCallback(static function()use(&$state):array{return $state;});
        $repo->method('page')->willReturnCallback(static function(array $claim)use(&$state):array {
            if($state['after_chunk_id']>0)return [];
            return [['id'=>11,'document_id'=>4,'vector_revision'=>7,'embedding'=>'[1,0,0]','content_hash'=>hash('sha256','chunk'),'source'=>'files']];
        });
        $repo->method('sourcePage')->willReturnCallback(static function()use(&$state,$source,$sourceStamp):array {
            if($state['after_document_id']>0)return [];
            return [$source+['source_stamp'=>$sourceStamp,'chunk_count'=>1,'current_chunks'=>1]];
        });
        $repo->method('withLease')->willReturnCallback(static function(array $claim,callable $change)use(&$state):mixed{return $change($state);});
        $repo->method('checkpoint')->willReturnCallback(static function(array $claim,array $values)use(&$state):void{$state=array_merge($state,$values);});
        $repo->method('documentsBeyondSnapshot')->willReturn(0);
        $repo->method('expectedCount')->willReturn(1);
        $repo->expects($shouldVerify?self::once():self::never())->method('markVerified')->with(self::anything(),1);
        if(!$shouldVerify)$repo->expects(self::once())->method('release')->with(self::anything(),'remote_point_missing');

        $generations=$this->createMock(VectorGenerationRepository::class);
        $generations->method('find')->willReturn($generation);
        $store=$this->createMock(QdrantVectorStore::class);
        $store->method('forTarget')->willReturn($store);
        $store->method('retrieve')->willReturn($remote);
        $store->method('countLive')->willReturn(1);
        return [new GenerationVerificationWorker($repo,$generations,$store),$generation];
    }

    public function testMatchingSqlAndRemotePointAndCurrentSourceProduceCertificate(): void {
        $generationId=str_repeat('a',32);
        [$worker]=$this->fixture([11=>['id'=>11,'payload'=>['user_id'=>hash('sha256','alice'),'generation_id'=>$generationId,
            'revision'=>7,'deleted'=>false,'document_id'=>4,'source'=>'files','content_hash'=>hash('sha256','chunk')],'vector'=>[1.0,0.0,0.0]]]);
        $result=$worker->run();
        self::assertTrue($result['verified']);self::assertSame(1,$result['checked']);self::assertSame(1,$result['sampled']);self::assertNull($result['error']);
    }

    public function testMissingRemotePointFailsClosedAndDoesNotIssueCertificate(): void {
        [$worker]=$this->fixture([],false);
        $result=$worker->run();
        self::assertFalse($result['verified']);self::assertSame('remote_point_missing',$result['error']);
    }
}
