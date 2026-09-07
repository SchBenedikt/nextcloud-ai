<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ChatStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
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
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $namespace = substr(hash('sha256', 'alice'), 0, 40);
        // Chat mutations must be serialized through Nextcloud's shared locking
        // provider so concurrent writes cannot race across nodes (Issue #78).
        $lockingProvider->expects(self::once())
            ->method('acquireLock')
            ->with('eva_ai/chat/' . $namespace, ILockingProvider::LOCK_EXCLUSIVE);
        $lockingProvider->expects(self::once())
            ->method('releaseLock')
            ->with('eva_ai/chat/' . $namespace, ILockingProvider::LOCK_EXCLUSIVE);
        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('chats')->willReturn($chats);
        $chats->method('getFolder')->with($namespace)->willReturn($userFolder);
        $userFolder->method('fileExists')->with('chats.json')->willReturn(true);
        $userFolder->method('getFile')->with('chats.json')->willReturn($file);
        $file->expects(self::once())->method('getContent')->willReturn(json_encode([
            ['id' => 'one', 'messages' => []],
            ['id' => 'two', 'messages' => []],
        ]));
        $file->expects(self::once())->method('putContent')->with('[]');

        $store = new ChatStore($factory, $logger, $lockingProvider);

        self::assertSame(2, $store->deleteAll('alice'));
    }

    public function testStorageFailureIsPropagatedFromCreate(): void {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $lockingProvider->method('acquireLock');
        $lockingProvider->method('releaseLock');

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

        $store = new ChatStore($factory, $logger, $lockingProvider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage unavailable');
        $store->create('alice');
    }

    private function chatFileHarness(string $json, ?string &$written): array {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $lockingProvider->method('acquireLock');
        $lockingProvider->method('releaseLock');

        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('chats')->willReturn($chats);
        $chats->method('getFolder')
            ->with(substr(hash('sha256', 'alice'), 0, 40))
            ->willReturn($userFolder);
        $userFolder->method('fileExists')->with('chats.json')->willReturn(true);
        $userFolder->method('getFile')->with('chats.json')->willReturn($file);
        $file->method('getContent')->willReturnCallback(static function () use (&$written, $json): string {
            // Subsequent reads observe what was written (like a real file).
            return $written ?? $json;
        });
        $file->method('putContent')->willReturnCallback(static function (string $content) use (&$written): void {
            $written = $content;
        });

        return [new ChatStore($factory, $logger, $lockingProvider), $file];
    }

    public function testTrimmingTheMessageCapIsNeverSilent(): void {
        // Seed a chat at the 1000-message cap; one more message must drop the
        // oldest entry AND record the drop on the chat (Issue #96).
        $messages = [];
        for ($i = 1; $i <= 1000; $i++) {
            $messages[] = ['role' => 'user', 'text' => 'message ' . $i];
        }
        $seed = json_encode([[
            'id' => 'c1',
            'title' => 'Long chat',
            'created' => 1,
            'updated' => 1,
            'messages' => $messages,
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = null;
        [$store] = $this->chatFileHarness($seed, $written);

        $store->append('alice', 'c1', 'assistant', 'the new tail');

        $saved = json_decode((string)$written, true);
        $chat = $saved[0];
        self::assertSame(1000, count($chat['messages']));
        self::assertSame(1, $chat['trimmed']);
        self::assertSame('message 2', $chat['messages'][0]['text'], 'oldest message is dropped');
        self::assertSame('the new tail', $chat['messages'][999]['text']);
        // The detail endpoint must surface the counter for the UI.
        $detail = $store->get('alice', 'c1');
        self::assertSame(1, $detail['trimmed']);
    }

    public function testListSearchMatchesMessageContentAndAddsSnippets(): void {
        $seed = json_encode([
            [
                'id' => 'budget',
                'title' => 'Budget meeting',
                'created' => 1,
                'updated' => 3,
                'messages' => [
                    ['role' => 'user', 'text' => 'Please summarise the revenue projections.'] ,
                    ['role' => 'assistant', 'text' => 'Revenue should grow by 20%.'],
                ],
            ],
            [
                'id' => 'other',
                'title' => 'Birthday plans',
                'created' => 1,
                'updated' => 4,
                'messages' => [
                    ['role' => 'user', 'text' => 'Cake and candles tomorrow.'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = null;
        [$store] = $this->chatFileHarness($seed, $written);

        // Content-only hit: the chat matches even though the title does not.
        $hits = $store->list('alice', 'revenue');
        self::assertCount(1, $hits);
        self::assertSame('budget', $hits[0]['id']);
        self::assertSame(2, $hits[0]['matchCount'], 'both the question and the answer match');
        self::assertStringContainsString('revenue', $hits[0]['snippet']);

        // No match anywhere -> empty result.
        self::assertSame([], $store->list('alice', 'no-such-term'));

        // Without a query every chat is listed (newest update first).
        $all = $store->list('alice');
        self::assertCount(2, $all);
        self::assertSame('other', $all[0]['id']);
        self::assertArrayNotHasKey('snippet', $all[0]);
    }
}
