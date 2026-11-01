<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\VectorAdminController;
use OCA\EvaAi\Vector\{QdrantConnection,QdrantVectorStore};
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\{AdminRequired,NoCSRFRequired};
use PHPUnit\Framework\TestCase;

final class VectorPointAdminTest extends TestCase {
    protected function setUp():void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }
    public function testConsentIsAdministratorOnlyAndDoesNotProbeTheServer():void {
        $request=$this->createMock(IRequest::class);
        $request->method('getHeader')->with('Content-Type')->willReturn('application/json');
        $request->method('getParam')->with('enabled')->willReturn(true);
        $connection=$this->createMock(QdrantConnection::class);$connection->expects(self::once())->method('setPointDelivery')->with(true);
        $store=$this->createMock(QdrantVectorStore::class);$store->expects(self::never())->method('health');
        $response=(new VectorAdminController('eva_ai',$request,$connection,$store))->pointDelivery();
        self::assertSame(200,$response->getStatus());self::assertSame(['points_requested'=>true],$response->getData());
        $method=new \ReflectionMethod(VectorAdminController::class,'pointDelivery');
        self::assertCount(1,$method->getAttributes(AdminRequired::class));self::assertCount(0,$method->getAttributes(NoCSRFRequired::class));
    }
    public function testStringConsentIsRejectedWithoutChangingFlags():void {
        $request=$this->createMock(IRequest::class);$request->method('getHeader')->willReturn('application/json');$request->method('getParam')->willReturn('true');
        $connection=$this->createMock(QdrantConnection::class);$connection->expects(self::never())->method('setPointDelivery');
        $response=(new VectorAdminController('eva_ai',$request,$connection,$this->createMock(QdrantVectorStore::class)))->pointDelivery();
        self::assertSame(400,$response->getStatus());
    }
}
