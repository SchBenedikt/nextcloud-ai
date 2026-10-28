<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Vector\{GenerationEmbeddingProvider,GenerationEmbeddingConfiguration,VectorGeneration,VectorStoreException};
use OCA\EvaAi\Service\{AppConfig,EmbeddingCache,ProviderEndpointPolicy};
use OCP\Http\Client\{IClientService,IClient,IResponse};
use PHPUnit\Framework\TestCase;

final class GenerationEmbeddingProviderTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud required.');
    }
    private function fixture(string $provider='ollama', ?array $data=null): array {
        $url='https://embedding.example/v1';
        $generation=new VectorGeneration(str_repeat('a',32),'alice','qdrant',$provider,$provider==='ollama'?null:'local','embed-model',hash('sha256',$url),2,900,120);
        $state=(object)['context'=>['url'=>$url,'auth'=>'none','encrypted_key'=>null], 'guard'=>null,'before'=>null,'after'=>null,'transferred'=>0,'payload'=>null,'status'=>200,'body'=>null];
        $configuration=$this->createMock(GenerationEmbeddingConfiguration::class);
        $configuration->method('capture')->willReturnCallback(static fn()=>$state->context);
        $configuration->method('authorization')->willReturn([]);
        $policy=$this->createMock(ProviderEndpointPolicy::class);
        $policy->method('handler')->willReturnCallback(static function($guard)use($state){$state->guard=$guard;return static function(){};});
        $config=$this->createMock(AppConfig::class);$config->method('userId')->willReturn('alice');$config->method('providerEndpointPolicy')->willReturn($policy);
        $response=$this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturnCallback(static fn()=>$state->status);
        $data??=$provider==='ollama'?['model'=>'embed-model:latest','embeddings'=>[[1,2]]]:['model'=>'embed-model','data'=>[['index'=>0,'embedding'=>[1,2]]]];
        $response->method('getBody')->willReturnCallback(static fn()=>$state->body??json_encode($data));
        $client=$this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(static function($url,$options)use($state,$response){
            $state->payload=$options;
            if ($state->before) ($state->before)();
            ($state->guard)(); $state->transferred++;
            if ($state->after) ($state->after)();
            return $response;
        });
        $clients=$this->createMock(IClientService::class);$clients->method('newClient')->willReturn($client);
        $cache=$this->createMock(EmbeddingCache::class);
        return ['provider'=>new GenerationEmbeddingProvider($config,$clients,$configuration,$cache),'generation'=>$generation,'state'=>$state,'cache'=>$cache];
    }
    public function testExactModelCoalescedInputsAndOrderedCacheHits(): void {
        $f=$this->fixture();
        $f['cache']->method('getForGeneration')->willReturnCallback(static fn($g,$text)=>$text==='cached'?[9.0,8.0]:null);
        $f['cache']->expects(self::once())->method('putForGeneration')->with($f['generation'],['same'],[[1.0,2.0]]);
        $result=$f['provider']->embed($f['generation'],['same','cached','same'],static function(){});
        self::assertSame([[1.0,2.0],[9.0,8.0],[1.0,2.0]],$result);
        self::assertSame(['model'=>'embed-model','input'=>['same'],'truncate'=>false],$f['state']->payload['json']);
        self::assertFalse($f['state']->payload['allow_redirects']);
        self::assertSame(30,$f['state']->payload['timeout']);
        self::assertSame(1,$f['state']->transferred);
    }
    public function testCompatibleResponseIndexesRestoreInputOrder(): void {
        $f=$this->fixture('openai_compatible',['model'=>'embed-model','data'=>[['index'=>1,'embedding'=>[3,4]],['index'=>0,'embedding'=>[1,2]]]]);
        self::assertSame([[1.0,2.0],[3.0,4.0]],$f['provider']->embed($f['generation'],['a','b'],static function(){}));
        self::assertSame('float',$f['state']->payload['json']['encoding_format']);
        self::assertArrayNotHasKey('truncate',$f['state']->payload['json']);
    }
    public function testKeyOrProfileChangesBeforeTransferPreventSending(): void {
        foreach (['url','encrypted_key'] as $field) {
            $f=$this->fixture('openai_compatible');$f['state']->context['auth']='api_key';$f['state']->context['encrypted_key']='old-key';$f['cache']->expects(self::never())->method('putForGeneration');
            $f['state']->before=static function()use($f,$field){$f['state']->context[$field]=$field==='url'?'https://changed.example/v1':'changed';};
            try {$f['provider']->embed($f['generation'],['a'],static function(){});self::fail('Changed context accepted.');}
            catch (VectorStoreException $e){self::assertSame('embedding_profile_changed',$e->reason);}
            self::assertSame(0,$f['state']->transferred);
        }
    }
    public function testLostLeaseAfterResponsePublishesNeitherCacheNorVector(): void {
        $f=$this->fixture();$allowed=true;
        $f['state']->after=static function()use(&$allowed){$allowed=false;};
        $f['cache']->expects(self::never())->method('putForGeneration');
        $this->expectException(VectorStoreException::class);$this->expectExceptionMessage('stale_lease');
        $f['provider']->embed($f['generation'],['a'],static function()use(&$allowed){if(!$allowed)throw new VectorStoreException('stale_lease');});
    }
    public function testInvalidBatchesNeverPopulateCache(): void {
        $cases=[
            ['model'=>'different','embeddings'=>[[1,2]]],
            ['model'=>'embed-model','embeddings'=>[[0,0]]],
            ['model'=>'embed-model','embeddings'=>[[1,2,3]]],
            ['model'=>'embed-model','embeddings'=>[['1',2]]],
            ['model'=>'embed-model','embeddings'=>[]],
        ];
        foreach($cases as $data){$f=$this->fixture('ollama',$data);$f['cache']->expects(self::never())->method('putForGeneration');
            try{$f['provider']->embed($f['generation'],['a'],static function(){});self::fail('Invalid response accepted.');}catch(VectorStoreException){self::assertTrue(true);}}
        $f=$this->fixture('openai_compatible',['model'=>'embed-model','data'=>[['index'=>0,'embedding'=>[1,2]],['index'=>0,'embedding'=>[3,4]]]]);
        $this->expectException(VectorStoreException::class);$f['provider']->embed($f['generation'],['a','b'],static function(){});
    }
    public function testExplicitProbeBypassesCacheAndSanitizesErrors(): void {
        $f=$this->fixture();$f['cache']->expects(self::never())->method('getForGeneration');$f['cache']->expects(self::never())->method('putForGeneration');
        self::assertCount(1,$f['provider']->embed($f['generation'],['a'],static function(){},false));
        $f['state']->status=500;$f['state']->body='private upstream error and secret';
        try{$f['provider']->embed($f['generation'],['a'],static function(){},false);self::fail('HTTP failure accepted.');}
        catch(VectorStoreException $e){self::assertSame('embedding_http_500',$e->reason);self::assertStringNotContainsString('private',$e->getMessage());}
    }
}
