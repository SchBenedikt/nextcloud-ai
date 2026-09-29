<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\{AgentStore, AppConfig, BackgroundChatQueue, ChatStore, DirtyIndexStore, EmbeddingCache, UserDataService};
use OCA\EvaAi\Db\{ChunkMapper, DocumentMapper};
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GDPR export + account-deletion cleanup primitives (Issue #83).
 */
final class GdprDataRemovalTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testChatStoreExportAndUserDataRemoval(): void {
        // ChatStore::exportAll returns full chats; deleteUserData removes the
        // hashed namespace folder (used by the GDPR endpoint and the
        // UserDeleted listener, Issue #83).
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);
        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock');
        $locking->method('releaseLock');

        $legacyFolder = $this->createMock(ISimpleFolder::class);
        $ns = substr(hash('sha256', 'alice'), 0, 40);
        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('chats')->willReturn($chats);
        $chats->method('getFolder')->willReturnMap([
            [$ns, $userFolder],
            ['alice', $legacyFolder],
        ]);
        $userFolder->method('fileExists')->with('chats.json')->willReturn(true);
        $userFolder->method('getFile')->with('chats.json')->willReturn($file);
        $file->method('getContent')->willReturn(json_encode([
            ['id' => 'c1', 'title' => 'Chat', 'created' => 1, 'updated' => 1, 'messages' => [['role' => 'user', 'text' => 'hi']]],
        ]));
        $file->method('putContent');
        $userFolder->method('delete');
        $legacyFolder->method('delete');

        $store = new ChatStore($factory, $logger, $locking);
        $all = $store->exportAll('alice');
        self::assertCount(1, $all);
        self::assertSame('hi', $all[0]['messages'][0]['text']);

        // Deleting user data removes both the hashed and the legacy folder.
        $store->deleteUserData('alice');
    }

    public function testAccountDeletionErasesEveryUserScopedStore(): void {
        $config = $this->createMock(AppConfig::class);
        $config->expects(self::once())->method('deleteUserValues')->with('alice');
        $chats = $this->createMock(ChatStore::class);
        $chats->expects(self::once())->method('deleteUserData')->with('alice');
        $dirty = $this->createMock(DirtyIndexStore::class);
        $dirty->expects(self::once())->method('deleteUserData')->with('alice');
        $agent = $this->createMock(AgentStore::class);
        $agent->expects(self::once())->method('deleteUserData')->with('alice');
        $queueConfig = $this->createMock(IConfig::class);
        $queueConfig->expects(self::exactly(2))->method('deleteUserValue')->willReturnCallback(
            static function (string $uid, string $app, string $key): void {
                self::assertSame('alice', $uid);
                self::assertSame('eva_ai', $app);
                self::assertContains($key, [BackgroundChatQueue::KEY, BackgroundChatQueue::HISTORY_KEY]);
            }
        );
        $queueConfig->method('getAppValue')->with('eva_ai', 'background_chat_users', '[]')->willReturn('["alice","bob"]');
        $queueConfig->expects(self::once())->method('setAppValue')->with('eva_ai', 'background_chat_users', '["bob"]');
        $queueLocks = $this->createMock(ILockingProvider::class);
        $queueLocks->expects(self::exactly(2))->method('acquireLock');
        $queueLocks->expects(self::exactly(2))->method('releaseLock');
        $queue = new BackgroundChatQueue($queueConfig, $queueLocks, $this->createMock(LoggerInterface::class));
        $documents = $this->createMock(DocumentMapper::class);
        $documents->expects(self::once())->method('deleteByUser')->with('alice');
        $chunks = $this->createMock(ChunkMapper::class);
        $chunks->expects(self::once())->method('deleteForUser')->with('alice');
        $embeddings = $this->createMock(EmbeddingCache::class);
        $embeddings->expects(self::once())->method('clearUser')->with('alice');
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willThrowException(new \RuntimeException('account home is gone'));
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('ai-marks')->willThrowException(new NotFoundException('missing'));

        $service = new UserDataService(
            $config,
            $chats,
            $dirty,
            $agent,
            $queue,
            $documents,
            $chunks,
            $embeddings,
            $root,
            $factory,
            $this->createMock(LoggerInterface::class),
        );

        $service->cleanupDeletedAccount('alice');
    }
}
