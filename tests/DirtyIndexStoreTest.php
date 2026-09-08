<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\DirtyIndexStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

final class DirtyIndexStoreTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function harness(string $initial, ?string &$written): DirtyIndexStore {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $dir = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $name = substr(hash('sha256', 'alice'), 0, 40) . '.json';

        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('dirty')->willReturn($dir);
        $dir->method('fileExists')->with($name)->willReturn(true);
        $dir->method('getFile')->with($name)->willReturn($file);
        $file->method('getContent')->willReturnCallback(static function () use (&$written, $initial): string {
            return $written ?? $initial;
        });
        $file->method('putContent')->willReturnCallback(static function (string $content) use (&$written): void {
            $written = $content;
        });

        return new DirtyIndexStore($factory);
    }

    public function testMarkDrainAndDeduplicate(): void {
        $written = null;
        $store = $this->harness('[]', $written);

        $store->markFile('alice', 7);
        $store->markFile('alice', 7); // repeated event collapses
        $store->markFile('alice', 8);
        self::assertSame(2, $store->pending('alice'));

        $entries = $store->drain('alice');
        self::assertCount(2, $entries);
        $kinds = array_map(static fn($e) => $e['kind'], $entries);
        self::assertSame(['file', 'file'], $kinds);
        self::assertSame(0, $store->pending('alice'), 'queue is cleared after drain');
    }

    public function testFolderRenameReplacesOldEntry(): void {
        $written = null;
        $store = $this->harness('[]', $written);

        $store->markFolderRenamed('alice', 'Notes/Old', 'Notes/New');
        $store->markFolderRenamed('alice', 'Notes/Old', 'Notes/Newer'); // same old path
        $entries = $store->drain('alice');
        self::assertCount(1, $entries);
        self::assertSame('folder-rename', $entries[0]['kind']);
        self::assertSame('Notes/Old', $entries[0]['oldPath']);
        self::assertSame('Notes/Newer', $entries[0]['path']);
    }

    public function testDrainIsBounded(): void {
        $initial = json_encode([
            ['kind' => 'file', 'fileId' => 1, 'path' => '', 'oldPath' => ''],
            ['kind' => 'file', 'fileId' => 2, 'path' => '', 'oldPath' => ''],
            ['kind' => 'file', 'fileId' => 3, 'path' => '', 'oldPath' => ''],
        ]);
        $written = null;
        $store = $this->harness($initial, $written);

        $first = $store->drain('alice', 2);
        self::assertCount(2, $first);
        self::assertSame([1, 2], array_map(static fn($e) => $e['fileId'], $first));
        self::assertSame(1, $store->pending('alice'));

        $rest = $store->drain('alice', 2);
        self::assertCount(1, $rest);
        self::assertSame(3, $rest[0]['fileId']);
    }
}