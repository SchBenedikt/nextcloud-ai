<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Vector\{QdrantConnection,QdrantVectorStore,SearchFilters,VectorGeneration,VectorStoreException};
use PHPUnit\Framework\TestCase;

final class QdrantVectorInventoryTest extends TestCase {
    private function generation(): VectorGeneration {
        return new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'embed',str_repeat('b',64),3,900,120);
    }

    public function testExactCountAlwaysScopesByOwnerGenerationAndLiveState(): void {
        $connection=$this->createMock(QdrantConnection::class);
        $connection->expects(self::once())->method('request')->with('post','/collections/eva_g_'.str_repeat('a',32).'/points/count?timeout=10',self::callback(static function(array $body):bool {
            self::assertTrue($body['exact']);
            self::assertSame(hash('sha256','alice'),$body['filter']['must'][0]['match']['value']);
            self::assertSame(str_repeat('a',32),$body['filter']['must'][1]['match']['value']);
            self::assertSame(false,$body['filter']['must'][2]['match']['value']);
            return true;
        }),false,12,self::anything(),self::anything())->willReturn(['status'=>'ok','result'=>['count'=>12]]);
        self::assertSame(12,(new QdrantVectorStore($connection))->forTarget(str_repeat('c',64))->countLive($this->generation()));
    }

    public function testRetrievalValidatesIdentityDimensionAndRevisionBeforeReturning(): void {
        $connection=$this->createMock(QdrantConnection::class);
        $connection->expects(self::once())->method('request')->with('post','/collections/eva_g_'.str_repeat('a',32).'/points?timeout=10',self::callback(static fn(array $body):bool=>$body['ids']===[5,6]&&$body['with_vector']===true),false,12,self::anything(),self::anything())
            ->willReturn(['status'=>'ok','result'=>[
                ['id'=>5,'payload'=>['user_id'=>hash('sha256','alice'),'generation_id'=>str_repeat('a',32),'revision'=>9,'deleted'=>false,'document_id'=>3,'source'=>'files','content_hash'=>str_repeat('d',64)],'vector'=>['dense'=>[1,0,0]]],
            ]]);
        $points=(new QdrantVectorStore($connection))->forTarget(str_repeat('c',64))->retrieve($this->generation(),[5,6]);
        self::assertSame([5],array_keys($points));
        self::assertSame(9,$points[5]['payload']['revision']);
        self::assertSame([1.0,0.0,0.0],$points[5]['vector']);
    }

    public function testRetrievalRejectsAnotherTenantsPoint(): void {
        $connection=$this->createMock(QdrantConnection::class);
        $connection->method('request')->willReturn(['status'=>'ok','result'=>[
            ['id'=>5,'payload'=>['user_id'=>hash('sha256','bob'),'generation_id'=>str_repeat('a',32),'revision'=>1,'deleted'=>false],'vector'=>['dense'=>[1,0,0]]],
        ]]);
        try {(new QdrantVectorStore($connection))->forTarget(str_repeat('c',64))->retrieve($this->generation(),[5]);self::fail('Cross-tenant point accepted.');}
        catch(VectorStoreException $error){self::assertSame('point_identity_mismatch',$error->reason);}
    }

    public function testShadowSearchUsesShortTimeoutAndServerSideTenantGenerationAndScopeFilters(): void {
        $scope='Documents/Projects';$connection=$this->createMock(QdrantConnection::class);
        $connection->expects(self::once())->method('request')->with('post','/collections/eva_g_'.str_repeat('a',32).'/points/query?timeout=2',
            self::callback(static function(array$body)use($scope):bool{
                self::assertSame('dense',$body['using']);self::assertSame(hash('sha256','alice'),$body['filter']['must'][0]['match']['value']);
                self::assertSame(str_repeat('a',32),$body['filter']['must'][1]['match']['value']);
                self::assertSame(hash('sha256',$scope),$body['filter']['must'][3]['match']['value']);return true;
            }),false,2,str_repeat('c',64),self::anything())->willReturn(['status'=>'ok','result'=>['points'=>[
                ['id'=>5,'score'=>0.9,'payload'=>['user_id'=>hash('sha256','alice'),'generation_id'=>str_repeat('a',32),'revision'=>4,'deleted'=>false,
                    'document_id'=>3,'source'=>'files','scope_paths'=>[hash('sha256',$scope)]]],
            ]]]);
        $result=(new QdrantVectorStore($connection))->forTarget(str_repeat('c',64))->search($this->generation(),[1,0,0],10,new SearchFilters($scope),2);
        self::assertSame([['chunkId'=>5,'revision'=>4,'score'=>0.9]],$result);
    }
}
