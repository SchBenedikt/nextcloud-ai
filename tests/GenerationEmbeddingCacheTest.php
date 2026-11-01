<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\{AppConfig,EmbeddingCache};
use OCA\EvaAi\Vector\VectorGeneration;
use OCP\{ICache,ICacheFactory};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerationEmbeddingCacheTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud required.');
    }
    private function generation(string $owner='alice',string $model='model',int $dimension=2,string $endpoint='https://embed.example'): VectorGeneration {
        return new VectorGeneration(bin2hex(random_bytes(16)),$owner,'qdrant','ollama',null,$model,hash('sha256',$endpoint),$dimension,900,120);
    }
    public function testCacheReusesProvenIdentityButNotLegacyOrAnotherSpace(): void {
        $values=[];
        $backend=$this->createMock(ICache::class);
        $backend->method('get')->willReturnCallback(static function($key)use(&$values){return $values[$key]??null;});
        $backend->method('set')->willReturnCallback(static function($key,$value,$ttl)use(&$values){self::assertSame(2592000,$ttl);$values[$key]=$value;return true;});
        $backend->method('clear')->willReturnCallback(static function($prefix='')use(&$values){foreach(array_keys($values)as$key)if(str_starts_with($key,$prefix))unset($values[$key]);return true;});
        $factory=$this->createMock(ICacheFactory::class);$factory->method('createDistributed')->willReturn($backend);
        $config=$this->createMock(AppConfig::class);$config->method('get')->willReturn('model');$config->method('ollamaUrl')->willReturn('https://embed.example');
        $cache=new EmbeddingCache($factory,$config,$this->createMock(LoggerInterface::class));
        $generation=$this->generation();
        $cache->putMany([['userId'=>'alice','text'=>"a\nb",'vector'=>[9,9]]]);
        self::assertNull($cache->getForGeneration($generation,"a\nb"));
        $cache->putForGeneration($generation,["a\nb"],[[1,2]]);
        self::assertSame([1.0,2.0],$cache->getForGeneration($generation,"a\nb"));
        self::assertSame([1.0,2.0],$cache->getForGeneration($this->generation(),"a\nb"));
        self::assertNull($cache->getForGeneration($generation,'a b'));
        foreach([$this->generation('bob'),$this->generation('alice','other'),$this->generation('alice','model',3),$this->generation('alice','model',2,'https://other.example')]as$other)self::assertNull($cache->getForGeneration($other,"a\nb"));
        $bob=$this->generation('bob');$cache->putForGeneration($bob,['b'],[[3,4]]);
        $cache->clearUser('alice');
        self::assertNull($cache->getForGeneration($generation,"a\nb"));
        self::assertSame([3.0,4.0],$cache->getForGeneration($bob,'b'));
    }
}
