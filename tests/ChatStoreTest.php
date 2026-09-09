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

    public function testDeleteOlderThanRemovesOnlyInactiveChats(): void {
        $now = time();
        $json = json_encode([
            ['id' => 'old', 'updated' => $now - 40 * 86400],
            ['id' => 'recent', 'updated' => $now - 3600],
            ['id' => 'ancient', 'updated' => $now - 100 * 86400],
            ['id' => 'no-stamp', 'messages' => []],
        ]);
        $written = null;
        [$store, $file] = $this->chatFileHarness($json, $written);

        // Chats whose last activity is older than the retention window are
        // removed; active and timestamp-less chats are kept. The file is only
        // rewritten when something was actually deleted.
        self::assertSame(2, $store->deleteOlderThan('alice', 30));
        $kept = json_decode((string)$written, true);
        self::assertSame(['recent', 'no-stamp'], array_column($kept, 'id'));

        // A disabled retention (0) must never touch the stored chats.
        $written = null;
        self::assertSame(0, $store->deleteOlderThan('alice', 0));
        self::assertNull($written);
    }

    public function testDeleteOlderThanKeepsEverythingInsideTheWindow(): void {
        $now = time();
        $json = json_encode([
            ['id' => 'a', 'updated' => $now - 5 * 86400],
            ['id' => 'b', 'updated' => $now - 6 * 86400 + 60],
        ]);
        $written = null;
        [$store] = $this->chatFileHarness($json, $written);

        self::assertSame(0, $store->deleteOlderThan('alice', 7));
        self::assertNull($written);
    }

    public function testCorruptChatDataIsNeverOverwritten(): void {
        foreach (['{"broken":', 'null', '{"unexpected":"object"}', '[null]'] as $json) {
            $written = null;
            [$store, $file] = $this->chatFileHarness($json, $written);
            $file->expects(self::never())->method('putContent');
            try {
                $store->create('alice');
                self::fail('Corrupt chat data must abort the mutation');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid EVA', $e->getMessage());
            }
            self::assertNull($written);
        }
    }

    public function testCorruptFolderRegistryIsNeverOverwritten(): void {
        $written = null;
        $foldersWritten = null;
        [$store] = $this->chatFileHarness('[]', $written, '{"broken":', $foldersWritten);
        try {
            $store->createFolder('alice', 'Work');
            self::fail('Corrupt folder data must abort the mutation');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Invalid EVA', $e->getMessage());
        }
        self::assertNull($foldersWritten);
    }

    public function testUntitledChatsUseEmptyTitlesAndLegacyGermanDefaultIsNormalized(): void {
        // New chats must not carry a hardcoded German default title: each
        // client renders its own translated "New chat" placeholder instead.
        $written = null;
        [$store] = $this->chatFileHarness('[]', $written);
        $created = $store->create('alice');
        self::assertSame('', $created['title']);

        // Legacy chats with the old literal default are normalized on read,
        // while a real title is never touched.
        $written = null;
        $seed = json_encode([
            ['id' => 'legacy', 'title' => 'Neuer Chat', 'created' => 1, 'updated' => 2, 'messages' => []],
            ['id' => 'real', 'title' => 'Budget meeting', 'created' => 1, 'updated' => 1, 'messages' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$store2] = $this->chatFileHarness($seed, $written);
        $titles = array_column($store2->list('alice', null, true), 'title');
        self::assertSame(['', 'Budget meeting'], $titles);
        self::assertSame('', $store2->get('alice', 'legacy')['title']);
        self::assertSame('Budget meeting', $store2->get('alice', 'real')['title']);
    }

    public function testFirstUserMessageTitlesAnUntitledChat(): void {
        // append() derives the title from the first user message, both for
        // the new empty default and for legacy chats with the German literal.
        foreach (['', 'Neuer Chat'] as $storedTitle) {
            $seed = json_encode([[
                'id' => 'c1',
                'title' => $storedTitle,
                'created' => 1,
                'updated' => 1,
                'messages' => [],
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $written = null;
            [$store] = $this->chatFileHarness($seed, $written);
            $store->append('alice', 'c1', 'user', 'Budget meeting tomorrow');
            $saved = json_decode((string)$written, true);
            self::assertSame('Budget meeting tomorrow', $saved[0]['title']);
            // The derived title is served as-is (no longer normalized away).
            self::assertSame('Budget meeting tomorrow', $store->list('alice')[0]['title']);
        }
    }

    public function testNewChatDoesNotReuseArchivedOrCustomizedEmptyChats(): void {
        $written = null;
        [$store] = $this->chatFileHarness(json_encode([
            ['id' => 'archived', 'archived' => true, 'messages' => []],
            ['id' => 'custom', 'instructions' => 'Translate everything', 'messages' => []],
            ['id' => 'scoped', 'scopePath' => '/Work', 'messages' => []],
        ]), $written);
        $chat = $store->create('alice');
        self::assertNotContains($chat['id'], ['archived', 'custom', 'scoped']);
        self::assertCount(4, json_decode($written, true));
    }

    private function chatFileHarness(string $json, ?string &$written, string $foldersJson = '[]', ?string &$foldersWritten = null): array {
        $factory = $this->createMock(IAppDataFactory::class);
        $appData = $this->createMock(IAppData::class);
        $chats = $this->createMock(ISimpleFolder::class);
        $userFolder = $this->createMock(ISimpleFolder::class);
        $file = $this->createMock(ISimpleFile::class);
        $foldersFile = $this->createMock(ISimpleFile::class);
        $logger = $this->createMock(LoggerInterface::class);
        $lockingProvider = $this->createMock(ILockingProvider::class);
        $lockingProvider->method('acquireLock');
        $lockingProvider->method('releaseLock');

        $factory->method('get')->with('eva_ai')->willReturn($appData);
        $appData->method('getFolder')->with('chats')->willReturn($chats);
        $chats->method('getFolder')
            ->with(substr(hash('sha256', 'alice'), 0, 40))
            ->willReturn($userFolder);
        $userFolder->method('fileExists')->willReturnCallback(static fn(string $name): bool => $name === 'chats.json' || $name === 'folders.json');
        $userFolder->method('getFile')->willReturnCallback(static function (string $name) use ($file, $foldersFile) {
            return $name === 'folders.json' ? $foldersFile : $file;
        });
        $file->method('getContent')->willReturnCallback(static function () use (&$written, $json): string {
            // Subsequent reads observe what was written (like a real file).
            return $written ?? $json;
        });
        $file->method('putContent')->willReturnCallback(static function (string $content) use (&$written): void {
            $written = $content;
        });
        $foldersFile->method('getContent')->willReturnCallback(static function () use (&$foldersWritten, $foldersJson): string {
            return $foldersWritten ?? $foldersJson;
        });
        $foldersFile->method('putContent')->willReturnCallback(static function (string $content) use (&$foldersWritten): void {
            $foldersWritten = $content;
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

    public function testMetaPinningFolderAndArchivingPersistAndListFiltersArchived(): void {
        $seed = json_encode([
            ['id' => 'a', 'title' => 'A', 'created' => 1, 'updated' => 1, 'messages' => []],
            ['id' => 'b', 'title' => 'B', 'created' => 1, 'updated' => 2, 'messages' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = null;
        $foldersWritten = null;
        [$store] = $this->chatFileHarness($seed, $written, '[]', $foldersWritten);

        // Folders must exist before a chat can be assigned to one.
        $store->createFolder('alice', 'Work');
        self::assertTrue($store->setMeta('alice', 'a', ['pinned' => true, 'folder' => 'Work']));
        self::assertTrue($store->setMeta('alice', 'b', ['archived' => true]));

        // Pinned chats sort first; archived chats are hidden from the list.
        $list = $store->list('alice');
        self::assertCount(1, $list);
        self::assertSame('a', $list[0]['id']);
        self::assertTrue($list[0]['pinned']);
        self::assertSame('Work', $list[0]['folder']);
        self::assertFalse($list[0]['archived']);

        // Include-archived mode returns everything.
        $all = $store->list('alice', null, true);
        self::assertCount(2, $all);
        $archived = null;
        foreach ($all as $entry) {
            if ($entry['id'] === 'b') {
                $archived = $entry;
            }
        }
        self::assertTrue($archived['archived']);

        // Legacy chats without the new fields default to unpinned/no folder.
        $saved = json_decode((string)$written, true);
        foreach ($saved as $chat) {
            if ($chat['id'] === 'a') {
                self::assertTrue($chat['pinned']);
                self::assertSame('Work', $chat['folder']);
            }
            if ($chat['id'] === 'b') {
                self::assertTrue($chat['archived']);
            }
        }
    }	public function testMetaCreatesUnknownFolderAndRejectsMissingChat(): void {
		$seed = json_encode([
			['id' => 'a', 'title' => 'A', 'created' => 1, 'updated' => 1, 'messages' => []],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$written = null;
		[$store] = $this->chatFileHarness($seed, $written);

		// Assigning to a folder that was never created creates it on the fly,
		// so the sidebar's "new folder" flow needs no separate round-trip.
		self::assertTrue($store->setMeta('alice', 'a', ['folder' => 'Missing']));
		self::assertSame('Missing', $store->listFolders('alice')[0]['name']);
		// Unknown chat ids return false as well.
		self::assertFalse($store->setMeta('alice', 'nope', ['pinned' => true]));
	}

    public function testFolderRegistryCreateRenameDelete(): void {
        $seed = json_encode([
            ['id' => 'a', 'title' => 'A', 'created' => 1, 'updated' => 1, 'messages' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = null;
        $foldersWritten = null;
        [$store] = $this->chatFileHarness($seed, $written, '[]', $foldersWritten);

        $store->createFolder('alice', 'Work');
        $store->createFolder('alice', 'Work'); // idempotent
        $store->setMeta('alice', 'a', ['folder' => 'Work']);

        self::assertCount(1, $store->listFolders('alice'));
        self::assertSame('Work', $store->listFolders('alice')[0]['name']);

        // Renaming re-points the chat.
        self::assertTrue($store->renameFolder('alice', 'Work', 'Office'));
        self::assertSame('Office', $store->listFolders('alice')[0]['name']);
        $list = $store->list('alice');
        self::assertSame('Office', $list[0]['folder']);		// Deleting unassigns the chat.
		self::assertTrue($store->deleteFolder('alice', 'Office'));
		self::assertSame([], $store->listFolders('alice'));
		$list = $store->list('alice');
		self::assertSame('', $list[0]['folder']);
	}

	public function testSetMetaAutoCreatesAnUnknownFolder(): void {
		$seed = json_encode([
			['id' => 'c1', 'title' => 'Work', 'created' => 1, 'updated' => 1, 'messages' => []],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$written = null;
		$foldersWritten = null;
		[$store] = $this->chatFileHarness($seed, $written, '[]', $foldersWritten);

		// The sidebar lets the user type a new folder name directly; the
		// assignment must create that folder instead of failing (Issue #87).
		self::assertTrue($store->setMeta('alice', 'c1', ['folder' => 'Projekte']));

		$saved = json_decode((string)$written, true);
		self::assertSame('Projekte', $saved[0]['folder']);
		$folders = json_decode((string)$foldersWritten, true);
		self::assertCount(1, $folders);
		self::assertSame('Projekte', $folders[0]['name']);
	}

	public function testScopePathMetaIsStoredAndListed(): void {
		$seed = json_encode([
			['id' => 's1', 'title' => 'Scoped', 'created' => 1, 'updated' => 1, 'messages' => []],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$written = null;
		[$store] = $this->chatFileHarness($seed, $written);

		self::assertSame('', $store->list('alice')[0]['scopePath'], 'legacy chats default to no scope');

		// "Chat with this folder" binds the chat's RAG retrieval (Issue #88).
		self::assertTrue($store->setMeta('alice', 's1', ['scopePath' => 'Documents/Projekte']));
		self::assertSame('Documents/Projekte', $store->list('alice')[0]['scopePath']);

		// Empty string clears the scope again.
		self::assertTrue($store->setMeta('alice', 's1', ['scopePath' => '']));
		self::assertSame('', $store->list('alice')[0]['scopePath']);
	}

	public function testCustomInstructionsAndPersonaMetaAreStoredAndListed(): void {
		$seed = json_encode([
			['id' => 's1', 'title' => 'Custom', 'created' => 1, 'updated' => 1, 'messages' => []],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$written = null;
		[$store] = $this->chatFileHarness($seed, $written);

		// Per-chat custom instructions (Issue #90): legacy chats default to none.
		self::assertSame('', $store->list('alice')[0]['instructions']);
		self::assertSame('', $store->list('alice')[0]['persona']);

		self::assertTrue($store->setMeta('alice', 's1', [
			'instructions' => 'Always answer in German.',
			'persona' => 'concise',
		]));
		self::assertSame('Always answer in German.', $store->list('alice')[0]['instructions']);
		self::assertSame('concise', $store->list('alice')[0]['persona']);

		// Clearing works per field; an oversized instruction is capped.
		self::assertTrue($store->setMeta('alice', 's1', ['instructions' => str_repeat('x', 5000)]));
		self::assertSame(2000, mb_strlen($store->list('alice')[0]['instructions']));
		self::assertTrue($store->setMeta('alice', 's1', ['persona' => '']));
		self::assertSame('', $store->list('alice')[0]['persona']);
	}

	public function testListHidesArchivedChatsUnlessRequested(): void {
		$seed = json_encode([
			['id' => 'a1', 'title' => 'Active', 'created' => 1, 'updated' => 5, 'messages' => []],
			['id' => 'a2', 'title' => 'Done', 'created' => 1, 'updated' => 4, 'archived' => true, 'messages' => []],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$written = null;
		[$store] = $this->chatFileHarness($seed, $written);

		// The dashboard widget keeps hiding archived chats ...
		$default = $store->list('alice');
		self::assertCount(1, $default);
		self::assertSame('a1', $default[0]['id']);
		self::assertFalse($default[0]['archived']);

		// ... but the sidebar needs them back for its archive section.
		$all = $store->list('alice', null, true);
		self::assertCount(2, $all);
		self::assertSame('a2', $all[1]['id']);
		self::assertTrue($all[1]['archived']);
	}

	private function conversationSeed(): string {
		return json_encode([
			[
				'id' => 'c1',
				'title' => 'Conv',
				'created' => 1,
				'updated' => 1,
				'rev' => 5,
				'messages' => [
					['role' => 'user', 'text' => 'q1'],
					['role' => 'assistant', 'text' => 'a1'],
					['role' => 'user', 'text' => 'q2'],
					['role' => 'assistant', 'text' => 'a2'],
				],
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public function testFailedRegenerateLeavesStoredHistoryIntact(): void {
		$written = null;
		[$store] = $this->chatFileHarness($this->conversationSeed(), $written);

		// Issue #182: beginRegenerate must not truncate anything; the
		// truncation is committed only when the new answer is persisted.
		$result = $store->beginRegenerate('alice', 'c1', 0, null, 5);
		self::assertTrue($result['ok']);
		self::assertSame(6, $result['rev']);
		self::assertSame('q1', $result['targetText']);

		// Model call fails -> nothing appended. The stored history is intact.
		$chat = $store->get('alice', 'c1');
		self::assertCount(4, $chat['messages']);
		self::assertSame('a1', $chat['messages'][1]['text']);
		self::assertSame('a2', $chat['messages'][3]['text']);
		// The internal pending marker is never exposed to clients.
		self::assertArrayNotHasKey('regenerate', $chat);
	}

	public function testSuccessfulRegenerateCommitsTruncationWithAnswerToken(): void {
		$written = null;
		[$store] = $this->chatFileHarness($this->conversationSeed(), $written);

		$result = $store->beginRegenerate('alice', 'c1', 0, null, 5);
		self::assertTrue($result['ok']);

		// The streamed answer is persisted with the revision token, which
		// commits the deferred truncation atomically.
		$store->append('alice', 'c1', 'assistant', 'a1-new', [], $result['rev']);

		$chat = $store->get('alice', 'c1');
		self::assertCount(2, $chat['messages']);
		self::assertSame('q1', $chat['messages'][0]['text']);
		self::assertSame('a1-new', $chat['messages'][1]['text']);
		// The commit bumped the revision; the client tracks it for the next
		// regenerate/edit.
		self::assertSame(7, $chat['rev']);
	}

	public function testRegenerateEditCommitsNewUserTextWithTheAnswer(): void {
		$written = null;
		[$store] = $this->chatFileHarness($this->conversationSeed(), $written);

		$result = $store->beginRegenerate('alice', 'c1', 0, 'q1-edited', 5);
		self::assertTrue($result['ok']);
		self::assertSame('q1-edited', $result['targetText']);

		// Before the answer is persisted the original user text stays stored.
		self::assertSame('q1', $store->get('alice', 'c1')['messages'][0]['text']);

		$store->append('alice', 'c1', 'assistant', 'a1-new', [], $result['rev']);
		$chat = $store->get('alice', 'c1');
		self::assertCount(2, $chat['messages']);
		self::assertSame('q1-edited', $chat['messages'][0]['text']);
		self::assertSame('a1-new', $chat['messages'][1]['text']);
	}

	public function testPlainAppendCancelsPendingRegenerateWithoutTruncating(): void {
		$written = null;
		[$store] = $this->chatFileHarness($this->conversationSeed(), $written);

		$result = $store->beginRegenerate('alice', 'c1', 0, null, 5);
		self::assertTrue($result['ok']);

		// A newer user action (e.g. from another tab) supersedes the pending
		// regeneration: the messages are never truncated.
		$store->append('alice', 'c1', 'user', 'q3');
		self::assertCount(5, $store->get('alice', 'c1')['messages']);

		// A late answer carrying the old token can no longer truncate the
		// newer message; it is appended as a plain message.
		$store->append('alice', 'c1', 'assistant', 'a1-late', [], $result['rev']);
		$chat = $store->get('alice', 'c1');
		self::assertCount(6, $chat['messages']);
		self::assertSame('q3', $chat['messages'][4]['text']);
		self::assertSame('a1-late', $chat['messages'][5]['text']);
	}

	public function testRegenerateConflictsAndValidation(): void {
		$written = null;
		[$store] = $this->chatFileHarness($this->conversationSeed(), $written);

		// Stale revision (chat was modified elsewhere) -> conflict.
		self::assertSame('conflict', $store->beginRegenerate('alice', 'c1', 0, null, 4)['error']);

		// Unknown chat -> not_found.
		self::assertSame('not_found', $store->beginRegenerate('alice', 'nope', 0, null, 5)['error']);

		// Invalid target (assistant message / out of range / empty edit) -> invalid.
		self::assertSame('invalid', $store->beginRegenerate('alice', 'c1', 1, null, 5)['error']);
		self::assertSame('invalid', $store->beginRegenerate('alice', 'c1', 99, null, 5)['error']);
		self::assertSame('invalid', $store->beginRegenerate('alice', 'c1', 0, '   ', 5)['error']);

		// First regenerate is allowed from the current revision.
		$first = $store->beginRegenerate('alice', 'c1', 0, null, 5);
		self::assertTrue($first['ok']);
		// The same client (same base revision) may retry after a failure.
		$retry = $store->beginRegenerate('alice', 'c1', 0, null, 5);
		self::assertTrue($retry['ok']);
		self::assertSame(7, $retry['rev']);
		// A different base revision while a regeneration is pending -> conflict.
		self::assertSame('conflict', $store->beginRegenerate('alice', 'c1', 0, null, 6)['error']);
	}

	public function testRepairStoreReportsCorruptionWithoutWritingAndAppliesWithBackup(): void {
		$raw = '{"broken": tru'; // truncated/invalid JSON
		$written = null;
		$backup = null;
		[$store] = $this->repairHarness($raw, $written, $backup);

		// Dry run: corrupt store is preserved, nothing is written.
		$report = $store->repairStore('alice', false);
		self::assertSame('corrupt', $report['status']);
		self::assertNull($written);
		self::assertNull($backup);

		// Apply: the damaged file is backed up first, then replaced with a
		// minimal valid store (no parseable chats survived).
		$report = $store->repairStore('alice', true);
		self::assertSame('repaired', $report['status']);
		self::assertSame(0, $report['kept']);
		self::assertNotNull($report['backup']);
		self::assertSame($raw, $backup);
		self::assertSame([], json_decode((string)$written, true));
		// The repaired store is usable again.
		self::assertSame([], $store->list('alice'));
	}

	public function testRepairStoreKeepsParseableChatsAndLeavesValidStoreAlone(): void {
		// Valid JSON but not a list (a single chat object): salvageable.
		$raw = json_encode(['id' => 'c9', 'title' => 'Survivor', 'messages' => [['role' => 'user', 'text' => 'q']]]);
		$written = null;
		$backup = null;
		[$store] = $this->repairHarness($raw, $written, $backup);

		$report = $store->repairStore('alice', true);
		self::assertSame('repaired', $report['status']);
		self::assertSame(1, $report['kept']);
		$recovered = json_decode((string)$written, true);
		self::assertCount(1, $recovered);
		self::assertSame('c9', $recovered[0]['id']);
		self::assertSame('Survivor', $recovered[0]['title']);

		// A valid store needs no repair.
		$written = null;
		$backup = null;
		[$store2] = $this->repairHarness($this->conversationSeed(), $written, $backup);
		$report = $store2->repairStore('alice', true);
		self::assertSame('ok', $report['status']);
		self::assertNull($written);
		self::assertNull($backup);
	}

	private function repairHarness(string $raw, ?string &$written, ?string &$backup): array {
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
		$userFolder->method('fileExists')->willReturnCallback(static fn(string $name): bool => $name === 'chats.json');
		$userFolder->method('getFile')->with('chats.json')->willReturn($file);
		$userFolder->method('newFile')->willReturnCallback(static function (string $name, string $content) use (&$backup, $file) {
			$backup = $content;
			return $file;
		});
		$file->method('getContent')->willReturnCallback(static function () use (&$written, $raw): string {
			return $written ?? $raw;
		});
		$file->method('putContent')->willReturnCallback(static function (string $content) use (&$written): void {
			$written = $content;
		});

		return [new ChatStore($factory, $logger, $lockingProvider), $file];
	}
}
