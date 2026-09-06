<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\LegacyChatStore as ChatStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ChatStoreTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testDeleteAllClearsTheUserChatFileAndReturnsTheDeletedCount(): void {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);
        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('chats')->willReturn($chats);
        $chats->method('getFolder')->with(substr(hash('sha256', 'alice'), 0, 40))->willReturn($userFolder);
        $userFolder->method('fileExists')->with('chats.json')->willReturn(true);
        $userFolder->method('getFile')->with('chats.json')->willReturn($file);
        $file->expects(self::once())->method('getContent')->willReturn(json_encode([
            ['id' => 'one', 'messages' => []],
            ['id' => 'two', 'messages' => []],
        ]));
        $file->expects(self::once())->method('putContent')->with('[]');

        $store = new ChatStore($factory, $logger);

        self::assertSame(2, $store->deleteAll('alice'));
    }

    public function testStorageFailureIsPropagatedFromCreate(): void {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);

        $factory->method('get')
            ->with('eva_ai')
            ->willReturn($appData);
        $appData->method('getFolder')
            ->with('chats')
            ->willReturn($chats);
        $chats->method('getFolder')
            ->with(substr(hash('sha256', 'alice'), 0, 40))
            ->willReturn($userFolder);
        $userFolder->method('fileExists')
            ->with('chats.json')
            ->willReturn(true);
        $userFolder->method('getFile')
            ->with('chats.json')
            ->willReturn($file);
        $file->expects(self::once())
            ->method('getContent')
            ->willReturn('[]');
        $file->expects(self::once())
            ->method('putContent')
            ->willThrowException(new \RuntimeException('storage unavailable'));
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'eva_ai: chat save failed - chats may disappear after reload',
                self::arrayHasKey('exception')
            );

        $store = new ChatStore($factory, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage unavailable');
        $store->create('alice');
    }
}
