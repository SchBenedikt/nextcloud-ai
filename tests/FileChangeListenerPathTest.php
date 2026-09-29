<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Listener\FileChangeListener;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

final class FileChangeListenerPathTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testRelativePathHandlesFilesRootAndMountedStoragePaths(): void {
        $listener = (new \ReflectionClass(FileChangeListener::class))->newInstanceWithoutConstructor();
        $relativePath = (new \ReflectionClass(FileChangeListener::class))->getMethod('relativePath');

        self::assertSame('', $relativePath->invoke($listener, 'alice', '/alice/files'));
        self::assertSame('Shared/Projects/plan.md', $relativePath->invoke($listener, 'alice', '/alice/files/Shared/Projects/plan.md'));
        self::assertNull($relativePath->invoke($listener, 'alice', '/bob/files/plan.md'));
    }

    public function testMountedPathTakesPrecedenceOverOriginalOwner(): void {
        $listener = (new \ReflectionClass(FileChangeListener::class))->newInstanceWithoutConstructor();
        $file = $this->createMock(File::class);
        $file->expects(self::never())->method('getOwner');
        $file->method('getPath')->willReturn('/alice/files/Shared/plan.md');

        $userId = (new \ReflectionClass(FileChangeListener::class))
            ->getMethod('userIdFor')->invoke($listener, $file);

        self::assertSame('alice', $userId);
    }
}
