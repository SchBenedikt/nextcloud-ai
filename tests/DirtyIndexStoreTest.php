<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\DirtyIndexStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards for the coalesced filesystem-change queue.
 *
 * The queue moved from an AppData JSON file to Nextcloud SQL, so the previous
 * version of this test (which asserted on the removed AppData representation and
 * the removed drain() API) could not pass. Deduplication, lease reclaim and
 * bounded claiming are now SQL statements; they need a live connection and are
 * exercised by tests/runtime/account-erasure.php. What stays unit-testable are
 * the rejection paths below, which must never touch storage or take a lock.
 */
final class DirtyIndexStoreTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /** A store whose storage and locking must stay untouched by the call under test. */
    private function unusedStore(): DirtyIndexStore {
        $appData = $this->createMock(IAppDataFactory::class);
        $appData->expects(self::never())->method('get');
        $locks = $this->createMock(ILockingProvider::class);
        $locks->expects(self::never())->method('acquireLock');
        $db = $this->createMock(IDBConnection::class);
        $db->expects(self::never())->method('getQueryBuilder');
        $db->expects(self::never())->method('beginTransaction');
        return new DirtyIndexStore($appData, $locks, $db, $this->createMock(LoggerInterface::class));
    }

    public function testNonPositiveFileIdsAreIgnored(): void {
        $store = $this->unusedStore();

        $store->markFile('alice', 0);
        $store->markFile('alice', -7);
    }

    public function testFolderRenameNeedsBothPaths(): void {
        $store = $this->unusedStore();

        $store->markFolderRenamed('alice', '', 'Notes/New');
        $store->markFolderRenamed('alice', 'Notes/Old', '');
    }

    public function testClaimWithoutAUserDoesNothing(): void {
        self::assertSame([], $this->unusedStore()->claim(''));
    }

    public function testAcknowledgeWithoutEntriesDoesNothing(): void {
        self::assertSame(0, $this->unusedStore()->acknowledge('alice', []));
        self::assertSame(0, $this->unusedStore()->acknowledge('', [['id' => 1, 'revision' => 1]]));
    }

    public function testRequeueWithoutEntriesReportsNoCounts(): void {
        $empty = ['requeued' => 0, 'exhausted' => 0, 'superseded' => 0];

        self::assertSame($empty, $this->unusedStore()->requeueFailed('alice', []));
        self::assertSame($empty, $this->unusedStore()->requeueFailed('', [['id' => 1, 'revision' => 1]]));
    }
}
