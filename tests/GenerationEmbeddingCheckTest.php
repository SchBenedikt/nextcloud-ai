<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Vector\{GenerationEmbeddingCheck,GenerationEmbeddingProvider,VectorSourceAccess,VectorGeneration,VectorStoreException};
use OCA\EvaAi\Service\AppConfig;
use PHPUnit\Framework\TestCase;

final class GenerationEmbeddingCheckTest extends TestCase {
    protected function setUp():void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud required.');
    }
    public function testProbeUsesOnlyPublicTextAndRestoresUserScopeOnFailure():void {
        foreach([false,true]as$fail){
            $generation=new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'embed',str_repeat('b',64),2,900,120);
            $generations=$this->createMock(VectorGenerationRepository::class);$generations->method('find')->with('alice',$generation->id)->willReturn($generation);
            $generations->method('status')->willReturn('building');$generations->method('ownerStatus')->willReturn('open');
            $access=$this->createMock(VectorSourceAccess::class);$access->method('assertOwner')->willReturn($this->createMock(\OCP\IUser::class));
            $user='admin';$config=$this->createMock(AppConfig::class);$config->method('userId')->willReturnCallback(static function()use(&$user){return $user;});
            $config->method('setUserId')->willReturnCallback(static function($id)use(&$user){$user=$id;});
            $provider=$this->createMock(GenerationEmbeddingProvider::class);
            $provider->expects(self::once())->method('embed')->willReturnCallback(static function($g,$texts,$guard,$cache)use($fail,&$user){
                self::assertSame('alice',$user);self::assertSame(['Vector generation compatibility check.'],$texts);self::assertFalse($cache);$guard();
                if($fail)throw new VectorStoreException('embedding_connection_failed');return [[1.0,2.0]];
            });
            $check=new GenerationEmbeddingCheck($generations,$access,$config,$provider);
            try{$result=$check->check('alice',$generation->id);self::assertFalse($fail);self::assertFalse($result['document_content_sent']);self::assertFalse($result['retrieval_changed']);}
            catch(VectorStoreException $e){self::assertTrue($fail);self::assertSame('embedding_connection_failed',$e->reason);}
            self::assertSame('admin',$user);
        }
    }
}
