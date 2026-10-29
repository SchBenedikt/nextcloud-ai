<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\GenerationBuildAdminController;
use OCA\EvaAi\Db\{GenerationBuildRepository, VectorGenerationRepository};
use OCA\EvaAi\Dto\GenerationBuildRequest;
use OCA\EvaAi\Vector\{GenerationBuildService, VectorSourceAccess, VectorGeneration, VectorStoreException};
use OCP\AppFramework\Http\Attribute\{AdminRequired,NoCSRFRequired};
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class GenerationBuildAdminTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }

    public function testOnlyAnExplicitBooleanAuthorizesStartingDocumentTransfer(): void {
        foreach ([null,false,1,'true',[],new \stdClass()] as $value) {
            try {GenerationBuildRequest::fromArray('start',['confirm_document_transfer'=>$value]);self::fail('Invalid consent accepted.');}
            catch(\InvalidArgumentException){self::assertTrue(true);}
        }
        self::assertTrue(GenerationBuildRequest::fromArray('start',['confirm_document_transfer'=>true])->confirmed);
        self::assertFalse(GenerationBuildRequest::fromArray('pause',[])->confirmed);
    }

    public function testNativeStatusDoesNotExposeLeasesTargetsOrTriggerWork(): void {
        $g=new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'embed',str_repeat('b',64),3,128,12);
        $repository=$this->createMock(GenerationBuildRepository::class);
        $repository->method('find')->willReturn(['status'=>'paused','documents_done'=>1,'total_documents'=>3,'heartbeat_at'=>10,'error_code'=>null,
            'lease_token'=>'secret-lease','target_hash'=>'private-target','plan_hash'=>'internal-plan']);
        $repository->method('available')->willReturn(false);$repository->method('stagedCount')->willReturn(8);
        $repository->expects(self::never())->method('start');$repository->expects(self::never())->method('claim');
        $generations=$this->createMock(VectorGenerationRepository::class);$generations->method('find')->willReturn($g);$generations->method('target')->willReturn(str_repeat('c',64));
        $access=$this->createMock(VectorSourceAccess::class);$access->expects(self::once())->method('assertOwner')->with('alice')->willReturn($this->createMock(\OCP\IUser::class));
        $data=(new GenerationBuildService($repository,$generations,$access))->status('alice',$g->id);
        self::assertSame('paused',$data['status']);self::assertFalse($data['can_start']);self::assertFalse($data['verified']);self::assertSame(8,$data['staged_chunks']);
        foreach(['lease_token','target_hash','plan_hash']as$key)self::assertArrayNotHasKey($key,$data);
    }

    public function testInvalidControlsFailBeforeServiceMutationAndKeepNativeGuards(): void {
        $request=$this->createMock(IRequest::class);$request->method('getHeader')->willReturn('application/json');
        $request->method('getParam')->willReturn('true');
        $service=$this->createMock(GenerationBuildService::class);$service->expects(self::never())->method('control');
        $controller=new GenerationBuildAdminController('eva_ai',$request,$service);
        self::assertSame(400,$controller->control('alice',str_repeat('a',32),'start')->getStatus());
        self::assertSame(400,$controller->control('alice','invalid','pause')->getStatus());
        foreach(['status','control']as$name){$method=new \ReflectionMethod($controller,$name);self::assertCount(1,$method->getAttributes(AdminRequired::class));self::assertCount(0,$method->getAttributes(NoCSRFRequired::class));}
    }

    public function testProviderFailuresAreNotReflectedByTheControlEndpoint(): void {
        $request=$this->createMock(IRequest::class);$request->method('getHeader')->willReturn('application/json');$request->method('getParam')->willReturn(true);
        $service=$this->createMock(GenerationBuildService::class);$service->method('control')->willThrowException(new \RuntimeException('private upstream body'));
        $controller=new GenerationBuildAdminController('eva_ai',$request,$service);
        $response=$controller->control('alice',str_repeat('a',32),'start');self::assertSame(503,$response->getStatus());
        self::assertStringNotContainsString('private upstream body',json_encode($response->getData()));
    }

    public function testUnpreparedOrDisabledGenerationCannotBeStarted(): void {
        $request=$this->createMock(IRequest::class);$request->method('getHeader')->willReturn('application/json');$request->method('getParam')->willReturn(true);
        $service=$this->createMock(GenerationBuildService::class);$service->method('control')->willThrowException(new VectorStoreException('generation_unprepared'));
        $response=(new GenerationBuildAdminController('eva_ai',$request,$service))->control('alice',str_repeat('a',32),'start');
        self::assertSame(409,$response->getStatus());self::assertSame('build_control_failed',$response->getData()['error']['code']);
    }
}
