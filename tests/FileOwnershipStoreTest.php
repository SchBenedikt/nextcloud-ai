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
