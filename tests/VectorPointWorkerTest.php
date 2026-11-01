<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\{GenerationChunkRepository, VectorGenerationRepository, VectorOutboxRepository};
use OCA\EvaAi\Vector\{VectorGeneration, VectorPointWorker, VectorSourceAccess, QdrantVectorStore};
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VectorPointWorkerTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }

    private function fixture(string $operation = 'upsert', bool $enabled = true, string $state = 'building', bool $lease = true, bool $currentTarget = true, bool $healthy = true): array {
        $generation = new VectorGeneration(str_repeat('a',32),'alice','qdrant','ollama',null,'model',str_repeat('b',64),3,900,120);
        $event = ['id'=>7,'user_id'=>'alice','generation_id'=>$generation->id,'target_hash'=>str_repeat('c',64),
            'document_id'=>8,'chunk_id'=>9,'lease_token'=>str_repeat('d',32),'operation'=>$operation,'generation_json'=>json_encode($generation->toRow())];
        $flag = (object)['enabled'=>$enabled];
        $outbox = $this->createMock(VectorOutboxRepository::class);
        $outbox->method('pointsEnabled')->willReturnCallback(static fn()=> $flag->enabled);
        $outbox->method('claimPoint')->willReturn($event);
        $outbox->method('ownsLease')->willReturn($lease);
        $outbox->method('pointTargetIsCurrent')->willReturn($currentTarget);
        $generations = $this->createMock(VectorGenerationRepository::class);
        $generations->method('deliveryState')->willReturn($state);
        $generations->method('ownerStatus')->willReturn('open');
        $chunks = $this->createMock(GenerationChunkRepository::class);
        $access = $this->createMock(VectorSourceAccess::class);
        $access->method('assertOwner')->willReturn($this->createMock(IUser::class));
        $store = $this->createMock(QdrantVectorStore::class);
        $store->method('health')->willReturn(['available'=>$healthy,'code'=>$healthy?'api_reachable':'unsupported_version']);
        $worker = new VectorPointWorker($outbox,$generations,$chunks,$access,$store,$this->createMock(LoggerInterface::class));
        return compact('generation','event','flag','outbox','generations','chunks','access','store','worker');
    }

    public function testDisabledWorkersDoNotClaimOrContactStorage(): void {
        $f=$this->fixture(enabled:false);
        $f['outbox']->expects(self::never())->method('claimPoint');
        $f['store']->expects(self::never())->method('forTarget');
        self::assertFalse($f['worker']->run(1)['enabled']);
    }

    public function testCurrentPointIsRecheckedAtFinalTransferAndAcknowledged(): void {
        $f=$this->fixture(); $point=['id'=>9,'document_id'=>8,'vector'=>[1.0,0.0,0.0]];
        $f['chunks']->method('pointForDelivery')->willReturn($point);
        $f['access']->expects(self::exactly(2))->method('assertAccessible')->with('alice',$point);
        $f['store']->method('forTarget')->willReturnCallback(static function($target,$guard) use($f){$guard();return $f['store'];});
        $f['store']->expects(self::once())->method('upsert')->with($f['generation'],[$point],7);
        $f['outbox']->expects(self::once())->method('completePoint')->with($f['event'],self::isType('int'))->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['delivered']);
    }

    public function testDeletedOrSupersededSqlPointNeverTransfers(): void {
        $f=$this->fixture(); $f['chunks']->method('pointForDelivery')->willReturn(null);
        $f['store']->expects(self::never())->method('forTarget');
        $f['outbox']->expects(self::once())->method('supersedePoint')->with(7,$f['event']['lease_token'],'stale_point',self::isType('int'))->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['superseded']);
    }

    public function testSourceChangedAfterPreparationIsRejectedByTheFinalGuard(): void {
        $f=$this->fixture();
        $f['chunks']->method('pointForDelivery')->willReturnOnConsecutiveCalls(['id'=>9,'document_id'=>8],null);
        $f['store']->method('forTarget')->willReturnCallback(static function($target,$guard) use($f){$guard();return $f['store'];});
        $f['store']->expects(self::never())->method('upsert');
        $f['outbox']->expects(self::once())->method('supersedePoint')->with(7,$f['event']['lease_token'],'stale_point',self::isType('int'))->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['superseded']);
    }

    public function testDisableAtFinalBoundaryPreventsTheWrite(): void {
        $f=$this->fixture(); $f['chunks']->method('pointForDelivery')->willReturn(['id'=>9,'document_id'=>8]);
        $f['store']->method('forTarget')->willReturnCallback(static function($target,$guard) use($f){$f['flag']->enabled=false;$guard();return $f['store'];});
        $f['store']->expects(self::never())->method('upsert');
        $f['outbox']->expects(self::once())->method('failed')->with(7,$f['event']['lease_token'],'sync_disabled',self::isType('int'),false)->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['failed']);
    }

    public function testUnsupportedServerVersionNeverReceivesAPointWrite(): void {
        $f=$this->fixture(healthy:false);
        $f['chunks']->method('pointForDelivery')->willReturn(['id'=>9,'document_id'=>8]);
        $f['store']->method('forTarget')->willReturn($f['store']);
        $f['store']->expects(self::never())->method('upsert');
        $f['outbox']->expects(self::once())->method('failed')->with(7,$f['event']['lease_token'],'unsupported_version',self::isType('int'),false)->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['failed']);
    }

    public function testChangingTheCurrentTargetPausesOldUploads(): void {
        $f=$this->fixture(currentTarget:false);
        $f['chunks']->expects(self::never())->method('pointForDelivery');
        $f['store']->expects(self::never())->method('forTarget');
        $f['outbox']->expects(self::once())->method('failed')->with(7,$f['event']['lease_token'],'target_inactive',self::isType('int'),false)->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['failed']);
    }

    public function testLostLeaseDoesNotAcknowledgeOrOverwriteItsSuccessor(): void {
        $f=$this->fixture(lease:false);
        $f['outbox']->expects(self::never())->method('completePoint');
        $f['outbox']->expects(self::never())->method('failed');
        $f['store']->expects(self::never())->method('forTarget');
        self::assertSame(1,$f['worker']->run(1)['lost_leases']);
    }

    public function testRetiredGenerationIsSupersededOnlyWithDurableErasureIntent(): void {
        $f=$this->fixture('delete_chunk',state:'retired');
        $f['outbox']->method('hasGenerationErasure')->willReturn(true);
        $f['outbox']->expects(self::once())->method('supersedePoint')->with(7,$f['event']['lease_token'],'generation_erasure',self::isType('int'))->willReturn(true);
        $f['store']->expects(self::never())->method('forTarget');
        self::assertSame(1,$f['worker']->run(1)['superseded']);
    }

    public function testMissingErasureIntentIsNotReportedAsSuccessfulCleanup(): void {
        $f=$this->fixture('delete_chunk',state:'retired');
        $f['outbox']->method('hasGenerationErasure')->willReturn(false);
        $f['outbox']->expects(self::never())->method('supersedePoint');
        $f['outbox']->expects(self::once())->method('failed')->with(7,$f['event']['lease_token'],'generation_unavailable',self::isType('int'),false)->willReturn(true);
        $f['store']->expects(self::never())->method('forTarget');
        self::assertSame(1,$f['worker']->run(1)['failed']);
    }

    public function testTombstoneDoesNotRequireTheDeletedSourceToExist(): void {
        $f=$this->fixture('delete_chunk');
        $f['chunks']->expects(self::never())->method('pointForDelivery');
        $f['access']->expects(self::never())->method('assertAccessible');
        $f['store']->method('forTarget')->willReturnCallback(static function($target,$guard) use($f){$guard();return $f['store'];});
        $f['store']->expects(self::once())->method('tombstoneChunks')->with($f['generation'],[9],7);
        $f['outbox']->method('completePoint')->willReturn(true);
        self::assertSame(1,$f['worker']->run(1)['delivered']);
    }
}
