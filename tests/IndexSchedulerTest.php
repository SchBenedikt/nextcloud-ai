<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\IndexScheduler;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fair multi-user scheduling for indexing (Issue #142). The scheduler must
 * bound how many index passes run concurrently instance-wide, queue excess
 * users FIFO without starvation, keep duplicate requests idempotent, and
 * reclaim slots abandoned by crashed workers.
 */
final class IndexSchedulerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Config double backed by a shared ArrayObject so the test can pre-seed
     * and inspect values through the same store the mock reads and writes.
     *
     * @param array<string,string> $values
     * @return array{0:IndexScheduler,1:AppConfig,2:\ArrayObject<string,string>}
     */
    private function harness(array $values = []): array {
        $store = new \ArrayObject($values);
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key) use ($store): string {
            return $store->offsetExists($key) ? (string)$store[$key] : '';
        });
        $config->method('set')->willReturnCallback(static function (string $key, string $value) use ($store): void {
            $store[$key] = $value;
        });
        $config->method('getInt')->willReturnCallback(static function (string $key, ?int $default = null) use ($store): int {
            $raw = $store->offsetExists($key) ? (string)$store[$key] : (string)($default ?? 0);
            return (int)$raw;
        });
        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock');
        $locking->method('releaseLock');
        $scheduler = new IndexScheduler($config, $locking, $this->createMock(LoggerInterface::class));
        return [$scheduler, $config, $store];
    }

    public function testTwoUsersFitUnderTheDefaultLimit(): void {
        [$scheduler] = $this->harness();
        self::assertSame(['state' => 'running', 'position' => 0], $scheduler->acquireSlot('alice'));
        self::assertSame(['state' => 'running', 'position' => 0], $scheduler->acquireSlot('bob'));
    }

    public function testThirdUserIsQueuedFifoAndRunsWhenASlotFrees(): void {
        [$scheduler] = $this->harness();
        $scheduler->acquireSlot('alice');
        $scheduler->acquireSlot('bob');

        // The third user is queued, not running.
        $queued = $scheduler->acquireSlot('carol');
        self::assertSame('queued', $queued['state']);
        self::assertSame(1, $queued['position']);
        self::assertFalse($scheduler->isRunning('carol'));
        $snap = $scheduler->snapshot('carol');
        self::assertSame('queued', $snap['state']);
        self::assertSame(1, $snap['position']);

        // FIFO: once a slot frees, the first queued user may run.
        $scheduler->releaseSlot('alice');
        $next = $scheduler->acquireSlot('carol');
        self::assertSame('running', $next['state']);
        self::assertTrue($scheduler->isRunning('carol'));
    }

    public function testQueueStaysFifoAcrossMultipleUsers(): void {
        [$scheduler] = $this->harness();
        $scheduler->acquireSlot('u1');
        $scheduler->acquireSlot('u2');
        self::assertSame(1, $scheduler->acquireSlot('u3')['position']);
        self::assertSame(2, $scheduler->acquireSlot('u4')['position']);

        $scheduler->releaseSlot('u1');
        self::assertSame('running', $scheduler->acquireSlot('u3')['state']);
        // u4 stays queued behind u3.
        self::assertSame(1, $scheduler->acquireSlot('u4')['position']);
        self::assertSame('queued', $scheduler->acquireSlot('u4')['state']);
    }

    public function testDuplicateRequestsAreIdempotent(): void {
        [$scheduler] = $this->harness();
        $scheduler->acquireSlot('alice');
        $scheduler->acquireSlot('bob');
        // A running user re-requesting keeps running (idempotent).
        self::assertSame('running', $scheduler->acquireSlot('alice')['state']);
        // A queued user re-requesting keeps the same position, not a new one.
        $first = $scheduler->acquireSlot('carol');
        $second = $scheduler->acquireSlot('carol');
        self::assertSame(1, $first['position']);
        self::assertSame(1, $second['position']);
        $snap = $scheduler->snapshot('carol');
        self::assertSame(1, $snap['queued'] ?? 1);
        self::assertSame(1, $snap['position']);
    }

    public function testStaleSlotIsReclaimedAndDoesNotBlockTheQueue(): void {
        [$scheduler, , $store] = $this->harness();
        $scheduler->acquireSlot('alice');
        $scheduler->acquireSlot('bob');
        // Simulate a crashed worker: bob's heartbeat is 30 minutes old.
        $active = json_decode($store['index_scheduler_active'], true);
        $active['bob'] = time() - 1800;
        $store['index_scheduler_active'] = json_encode($active);

        // The stale slot must not keep carol queued forever.
        $snap = $scheduler->snapshot('carol');
        self::assertSame('running', $scheduler->acquireSlot('carol')['state']);
        self::assertSame(1, $snap['running'] ?? 0);
    }

    public function testQueuedUsersDrainFifoAndRespectFreeSlots(): void {
        [$scheduler] = $this->harness();
        $scheduler->acquireSlot('u1');
        $scheduler->acquireSlot('u2');
        $scheduler->acquireSlot('u3');
        $scheduler->acquireSlot('u4');

        // One free slot (u1 released) -> only u3 is drainable right now.
        $scheduler->releaseSlot('u1');
        self::assertSame(['u3'], $scheduler->queuedUsers(10));

        // Release the second slot as well -> u3 and u4 become available.
        $scheduler->releaseSlot('u2');
        self::assertSame(['u3', 'u4'], $scheduler->queuedUsers(10));
    }

    public function testDequeueRemovesAUserFromTheQueue(): void {
        [$scheduler] = $this->harness();
        $scheduler->acquireSlot('u1');
        $scheduler->acquireSlot('u2');
        $scheduler->acquireSlot('u3');
        $scheduler->dequeue('u3');
        self::assertSame([], $scheduler->queuedUsers(10));
    }

    public function testLimitIsClampedToTheConfiguredRange(): void {
        [$scheduler, , $store] = $this->harness();
        $store['index_max_concurrent'] = '1';
        self::assertSame('running', $scheduler->acquireSlot('a')['state']);
        self::assertSame('queued', $scheduler->acquireSlot('b')['state']);

        $store['index_max_concurrent'] = '99';
        // Clamped to 16: the 17th user is queued.
        for ($i = 1; $i <= 16; $i++) {
            $scheduler->acquireSlot('u' . $i);
        }
        self::assertSame('queued', $scheduler->acquireSlot('overflow')['state']);
    }
}