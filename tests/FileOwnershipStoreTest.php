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

    private function node(int $id): Node {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        return $node;
    }

    private function store(string &$raw, array &$visible): FileOwnershipStore {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturnCallback(static function () use (&$raw): string { return $raw; });
        $file->method('putContent')->willReturnCallback(static function ($content) use (&$raw): void { $raw = $content; });
        $home = $this->createMock(Folder::class);
        $home->method('getById')->willReturnCallback(static function ($id) use (&$visible): array { return $visible[$id] ?? []; });
        return new FileOwnershipStore($file, $home);
    }
}
