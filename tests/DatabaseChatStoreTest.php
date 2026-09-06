<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\LegacyChatStore;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseChatStoreTest extends TestCase {
    use Support\SqliteDatabase;
    private function store(): ChatStore {
        $db = $this->sqliteDatabase();
        $legacy = $this->createMock(LegacyChatStore::class);
        $legacy->method('list')->willReturn([]);
        return new ChatStore($db, $this->createMock(ILockingProvider::class), $legacy);
    }

    public function testHistoryBeyondTwoHundredMessagesAndSearchIsolation(): void {
        $s = $this->store();
        $chat = $s->create('alice', 'Budget');
        for ($i = 0; $i < 205; $i++) { $s->append('alice', $chat['id'], 'user', "message $i"); }
        $s->append('alice', $chat['id'], 'assistant', 'Vertrag: Needle near the end');
        self::assertSame(206, $s->get('alice', $chat['id'])['total']);
        self::assertCount(100, $s->get('alice', $chat['id'])['messages']);
        self::assertSame('message 0', $s->get('alice', $chat['id'], 50, 0)['messages'][0]['text']);
        self::assertSame($chat['id'], $s->list('alice', 'needle')[0]['id']);
        self::assertStringContainsString('Needle', $s->list('alice', 'needle')[0]['snippet']);
        self::assertSame([], $s->list('bob', 'needle'));
        self::assertNull($s->get('bob', $chat['id']));
    }

    public function testProjectsArchivePinPaginationAndSafeDeletion(): void {
        $s = $this->store();
        $p = $s->saveProject('alice', ['title' => 'Research']);
        $chat = $s->create('alice');
        $s->update('alice', $chat['id'], ['project' => $p['id'], 'pinned' => true]);
        self::assertCount(1, $s->list('alice', '', 100, 0, $p['id']));
        self::assertSame([], $s->list('alice', '', 100, 0, ''));
        $s->deleteProject('bob', $p['id']);
        self::assertCount(1, $s->projects('alice'));
        $s->deleteProject('alice', $p['id']);
        self::assertCount(1, $s->list('alice', '', 100, 0, ''));
        $s->update('alice', $chat['id'], ['archived' => true]);
        self::assertSame([], $s->list('alice'));
        self::assertCount(1, $s->list('alice', '', 100, 0, null, true));
        self::assertTrue($s->delete('alice', $chat['id']));
        self::assertNull($s->get('alice', $chat['id']));
    }

    public function testForeignProjectAssignmentRollsBack(): void {
        $s = $this->store();
        $p = $s->saveProject('bob', ['title' => 'Secret']);
        $c = $s->create('alice');
        try { $s->update('alice', $c['id'], ['project' => $p['id']]); self::fail('Must reject'); }
        catch (\InvalidArgumentException $e) { self::assertSame('Project not found', $e->getMessage()); }
        self::assertEmpty($s->get('alice', $c['id'])['project'] ?? '');
    }
}
