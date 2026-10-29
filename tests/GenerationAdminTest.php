<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\GenerationAdminController;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Vector\{GenerationEmbeddingCheck,VectorSourceAccess,VectorGeneration,VectorStoreException};
use OCA\EvaAi\Service\AppConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\{AdminRequired,NoCSRFRequired};
use PHPUnit\Framework\TestCase;

final class GenerationAdminTest extends TestCase {
    protected function setUp():void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud required.');
    }
    public function testMetadataReadsNeverProbeAndOnlyExposeWhitelistedFields():void {
        $request=$this->createMock(IRequest::class);$request->method('getParam')->willReturn('');
        $generations=$this->createMock(VectorGenerationRepository::class);
        $row=['id'=>str_repeat('a',32),'status'=>'building','embedding_provider'=>'ollama','profile_id'=>null,'embedding_model'=>'embed','dimension'=>'3','chunk_size'=>'900','chunk_overlap'=>'120','created_at'=>'1','endpoint_hash'=>'private-hash','storage_endpoint_hash'=>'private-target'];
        $generations->expects(self::once())->method('page')->with('alice','')->willReturn([$row]);
        $access=$this->createMock(VectorSourceAccess::class);$access->expects(self::once())->method('assertOwner')->with('alice')->willReturn($this->createMock(\OCP\IUser::class));
        $check=$this->createMock(GenerationEmbeddingCheck::class);$check->expects(self::never())->method('check');
        $controller=new GenerationAdminController('eva_ai',$request,$generations,$access,$check);
        $response=$controller->listing('alice');self::assertSame(200,$response->getStatus());
        $item=$response->getData()['items'][0];self::assertSame(3,$item['dimension']);self::assertArrayNotHasKey('endpoint_hash',$item);self::assertArrayNotHasKey('storage_endpoint_hash',$item);
        foreach(['listing','check'] as $name){$method=new \ReflectionMethod($controller,$name);self::assertCount(1,$method->getAttributes(AdminRequired::class));self::assertCount(0,$method->getAttributes(NoCSRFRequired::class));}
    }
    public function testInvalidCursorFailsBeforeOwnedMetadataReads():void {
        $request=$this->createMock(IRequest::class);$request->method('getParam')->willReturn(['bad']);
        $generations=$this->createMock(VectorGenerationRepository::class);$generations->expects(self::never())->method('page');
        $controller=new GenerationAdminController('eva_ai',$request,$generations,$this->createMock(VectorSourceAccess::class),$this->createMock(GenerationEmbeddingCheck::class));
        self::assertSame(400,$controller->listing('alice')->getStatus());
    }
    public function testExplicitCheckFailureDoesNotReflectProviderBodies():void {
        $request=$this->createMock(IRequest::class);$check=$this->createMock(GenerationEmbeddingCheck::class);
        $check->method('check')->willThrowException(new VectorStoreException('embedding_model_mismatch'));
        $controller=new GenerationAdminController('eva_ai',$request,$this->createMock(VectorGenerationRepository::class),$this->createMock(VectorSourceAccess::class),$check);
        $response=$controller->check('alice',str_repeat('a',32));self::assertSame(503,$response->getStatus());
        self::assertSame('embedding_check_failed',$response->getData()['error']['code']);
    }
}
