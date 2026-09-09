<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\FileOwnershipStore;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\TestCase;

final class FileOwnershipStoreTest extends TestCase {
    protected function setUp(): void {
        if (!EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testReplacementDoesNotInheritGrantAndStaleIdsArePruned(): void {
        $original = $this->node(42);
        $replacement = $this->node(43);
        $visible = [42 => [$original]];
        $raw = '{}';
        $store = $this->store($raw, $visible);
        $store->remember($original);
        self::assertTrue($store->contains($original));
        $visible = [43 => [$replacement]];
        self::assertFalse($store->contains($replacement));
        self::assertSame([], json_decode($raw, true)['file_ids']);
    }

    public function testIdentitySurvivesRenameAndForgetRevokesGrant(): void {
        $before = $this->node(42);
        $renamed = $this->node(42);
        $visible = [42 => [$renamed]];
        $raw = '{}';
        $store = $this->store($raw, $visible);
        $store->remember($before);
        $store->remember($renamed);
        self::assertTrue($store->contains($renamed));
        self::assertSame([42], json_decode($raw, true)['file_ids']);
        $store->forget(42);
        self::assertFalse($store->contains($renamed));
    }

    public function testLegacyPathsAndMalformedMarkersNeverAuthorizeCurrentFiles(): void {
        $node = $this->node(42);
        $visible = [42 => [$node]];
        foreach (['["notes.txt"]', 'invalid', '{"version":2,"file_ids":["42",null,-1]}'] as $input) {
            $raw = $input;
            $store = $this->store($raw, $visible);
            self::assertFalse($store->contains($node));
            self::assertSame(['version' => 2, 'file_ids' => []], json_decode($raw, true));
        }
    }

    public function testPendingMarkerFinalizesIntoAGrantAfterTheActionSucceeded(): void {
        $node = $this->node(42);
        $node->method('getCreationTime')->willReturn(time());
        $raw = '{}';
        $home = $this->homeMock(['/Notes/meeting.md' => $node], [42 => [$node]]);
        $store = $this->pendingHarness($raw, $home);

        $token = $store->beginPending('/Notes/meeting.md');
        self::assertArrayHasKey('pending', json_decode($raw, true));
        // Crash between the action and the finalize: nothing else runs.
        // The next ownership check reconciles the pending record into a grant.
        self::assertTrue($store->contains($node));
        self::assertTrue($store->contains($node), 'grant stays durable after reconciliation');
        $saved = json_decode($raw, true);
        self::assertSame([42], $saved['file_ids']);
        self::assertArrayNotHasKey('pending', $saved, 'resolved pending record is dropped');
    }

    public function testCrashBeforeCreateDropsPendingWhenThePathNeverExisted(): void {
        $raw = '{}';
        $home = $this->homeMock([], []);
        $store = $this->pendingHarness($raw, $home);

        $store->beginPending('/gone.md');
        $report = $store->reconcile();

        self::assertSame(['finalized' => 0, 'dropped' => 1, 'unknown' => 0], $report);
        self::assertSame(['version' => 2, 'file_ids' => []], json_decode($raw, true));
    }

    public function testReplacementAtPendingPathIsNotGranted(): void {
        $replacement = $this->node(43);
        $replacement->method('getCreationTime')->willReturn(time() + 7200);
        $raw = '{}';
        $home = $this->homeMock(['/x.md' => $replacement], []);
        $store = $this->pendingHarness($raw, $home);

        $store->beginPending('/x.md');
        $report = $store->reconcile();

        self::assertSame(['finalized' => 0, 'dropped' => 0, 'unknown' => 1], $report, 'unrelated replacement stays explicitly unknown');
        $saved = json_decode($raw, true);
        self::assertSame([], $saved['file_ids']);
        self::assertArrayHasKey('pending', $saved, 'unknown records are never silently dropped');
    }

    public function testReconcileKeepsPendingWhenTheStoreCannotBeRead(): void {
        $raw = '{}';
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->willThrowException(new \RuntimeException('storage error'));
        $store = $this->pendingHarness($raw, $home);

        $store->beginPending('/x.md');
        $report = $store->reconcile();

        self::assertSame(['finalized' => 0, 'dropped' => 0, 'unknown' => 1], $report);
        self::assertArrayHasKey('pending', json_decode($raw, true));
    }

    public function testFinalizeAndCancelResolveThePendingRecordExplicitly(): void {
        $node = $this->node(42);
        $raw = '{}';
        $home = $this->homeMock(['/a.md' => $node], []);
        $store = $this->pendingHarness($raw, $home);

        $kept = $store->beginPending('/a.md');
        $cancelled = $store->beginPending('/b.md');
        $store->finalizePending($kept, $node);
        $store->cancelPending($cancelled);

        $saved = json_decode($raw, true);
        self::assertSame([42], $saved['file_ids']);
        self::assertArrayNotHasKey('pending', $saved);
    }

    public function testContendingRequestCannotReadOrOverwriteMarkers(): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->expects(self::never())->method('getContent');
        $file->expects(self::never())->method('putContent');
        $locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
        $locking->method('acquireLock')->willThrowException(new \RuntimeException('lock busy'));
        $locking->expects(self::never())->method('releaseLock');
        $store = new FileOwnershipStore($file, $this->createMock(Folder::class), $locking, 'alice');
        $this->expectExceptionMessage('lock busy');
        $store->remember($this->node(42));
    }

    private function node(int $id): Node {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        return $node;
    }

    private function homeMock(array $byPath, array $byId): Folder {
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->willReturnCallback(static function (string $path) use ($byPath): bool {
            return isset($byPath[$path]);
        });
        $home->method('get')->willReturnCallback(static function (string $path) use ($byPath) {
            if (!isset($byPath[$path])) {
                throw new \OCP\Files\NotFoundException();
            }
            return $byPath[$path];
        });
        $home->method('getById')->willReturnCallback(static function (int $id) use ($byId): array {
            return $byId[$id] ?? [];
        });
        return $home;
    }

    private function pendingHarness(string &$raw, Folder $home): FileOwnershipStore {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('putContent')->willReturnCallback(static function ($content) use (&$raw): void { $raw = $content; });
        $file->method('getContent')->willReturnCallback(static function () use (&$raw): string { return $raw; });
        $locked = false;
        $locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(static function ($key) use (&$locked): void {
            self::assertLessThanOrEqual(64, strlen($key));
            self::assertFalse($locked);
            $locked = true;
        });
        $locking->method('releaseLock')->willReturnCallback(static function () use (&$locked): void {
            self::assertTrue($locked);
            $locked = false;
        });
        return new FileOwnershipStore($file, $home, $locking, 'alice');
    }

    private function store(string &$raw, array &$visible): FileOwnershipStore {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('putContent')->willReturnCallback(static function ($content) use (&$raw): void { $raw = $content; });
        $home = $this->createMock(Folder::class);
        $home->method('getById')->willReturnCallback(static function ($id) use (&$visible): array { return $visible[$id] ?? []; });
        $locked = false;
        $locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(static function ($key) use (&$locked): void {
            self::assertLessThanOrEqual(64, strlen($key));
            self::assertFalse($locked);
            $locked = true;
        });
        $locking->method('releaseLock')->willReturnCallback(static function () use (&$locked): void {
            self::assertTrue($locked);
            $locked = false;
        });
        $file->method('getContent')->willReturnCallback(static function () use (&$raw, &$locked): string {
            self::assertTrue($locked, 'Marker reads must hold the shared lock');
            return $raw;
        });
        return new FileOwnershipStore($file, $home, $locking, 'alice');
    }
}
