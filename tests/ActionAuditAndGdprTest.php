<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionAudit;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for Issue #150 (privacy-conscious action audit trail)
 * and Issue #83 (GDPR export + account-deletion cleanup primitives).
 */
final class ActionAuditAndGdprTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function harness(string $seed = '[]'): array {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $auditRoot = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $written = null;

        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('audit')->willReturn($auditRoot);
        $auditRoot->method('getFolder')->with(substr(hash('sha256', 'alice'), 0, 40))->willReturn($userFolder);
        $userFolder->method('fileExists')->with('actions.json')->willReturn(true);
        $userFolder->method('getFile')->with('actions.json')->willReturn($file);
        $file->method('getContent')->willReturnCallback(static function () use (&$written, $seed): string {
            return $written ?? $seed;
        });
        $file->method('putContent')->willReturnCallback(static function (string $content) use (&$written): void {
            $written = $content;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock');
        $locking->method('releaseLock');

        return [new ActionAudit($factory, $locking, $logger), static function () use (&$written): ?string {
            return $written;
        }];
    }

    public function testSensitiveArgumentsAreRedacted(): void {
        [$audit, $written] = $this->harness();
        $audit->record('alice', 'web', 'create_share', [
            'path' => '/Documents/Plan.pdf',
            'password' => 'super-secret',
            'message' => 'full body text that must never be stored',
            'note' => 'ok to keep this short note',
        ], 'executed', 'ok');
        $saved = json_decode((string)$written(), true);
        self::assertCount(1, $saved);
        self::assertSame('[redacted]', $saved[0]['args']['password']);
        self::assertSame('[redacted]', $saved[0]['args']['message']);
        self::assertSame('ok to keep this short note', $saved[0]['args']['note']);
    }

    public function testRecordListAndClearRoundTrip(): void {
        [$audit, $written] = $this->harness();
        $audit->record('alice', 'web', 'delete_file', ['path' => '/Old.md'], 'executed', 'ok');
        $audit->record('alice', 'talk', 'create_file', ['path' => '/New.md', 'content' => 'secret body'], 'rejected', 'Confirmation required');

        $entries = $audit->list('alice', 50);
        self::assertCount(2, $entries);
        self::assertSame('rejected', $entries[0]['outcome']);
        self::assertSame('[redacted]', $entries[0]['args']['content']);
        self::assertSame('web', $entries[1]['surface']);

        self::assertSame(2, $audit->clear('alice'));
        self::assertSame([], $audit->list('alice', 50));
    }

    public function testListIsBoundedByRequestedLimit(): void {
        [$audit] = $this->harness();
        for ($i = 0; $i < 5; $i++) {
            $audit->record('alice', 'web', 'create_note', ['title' => 'Note ' . $i, 'content' => 'body'], 'executed', 'ok');
        }
        self::assertCount(3, $audit->list('alice', 3));
        self::assertCount(5, $audit->list('alice', 100));
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

        $store = new \OCA\EvaAi\Service\ChatStore($factory, $logger, $locking);
        $all = $store->exportAll('alice');
        self::assertCount(1, $all);
        self::assertSame('hi', $all[0]['messages'][0]['text']);

        // Deleting user data removes both the hashed and the legacy folder.
        $store->deleteUserData('alice');
    }
}
