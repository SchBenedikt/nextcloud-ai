<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Chunker;
use OCA\EvaAi\Service\EmailService;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCP\Comments\IComment;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Talk chat histories can be indexed, and the answer uses them.
 *
 * The feature is only safe when three things hold: a room's text reads like a
 * conversation, a room is stored where it cannot be confused with a mail
 * message, and only rooms the user is a member of are ever retrieved.
 */
final class TalkIndexingTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function comment(string $actor, string $message, string $at = '2026-09-01 10:00'): IComment {
        $comment = $this->createMock(IComment::class);
        $comment->method('getActorId')->willReturn($actor);
        $comment->method('getMessage')->willReturn($message);
        $comment->method('getCreationDateTime')->willReturn(new \DateTime($at));
        return $comment;
    }

    /** A transcript is readable: dated, attributed and in the order given. */
    public function testTranscriptReadsForwardsWithAuthorsAndDates(): void {
        $rendered = TalkTranscriptService::renderMessages([
            $this->comment('alice', 'Frage um eins', '2026-09-01 13:00'),
            $this->comment('bob', 'Antwort um zwei', '2026-09-01 14:00'),
        ], 'Projekt Alpha');

        self::assertSame(2, $rendered['count']);
        $lines = explode("\n", $rendered['text']);
        self::assertSame('Talk chat: Projekt Alpha', $lines[0]);
        self::assertStringContainsString('alice: Frage um eins', $lines[1]);
        self::assertStringContainsString('2026-09-01 13:00', $lines[1]);
        self::assertStringContainsString('bob: Antwort um zwei', $lines[2]);
    }

    /** System noise must not become searchable chat content. */
    public function testSystemMessagesCommandsAndJsonAreExcluded(): void {
        $rendered = TalkTranscriptService::renderMessages([
            $this->comment('changelog', 'Alice hat den Raum erstellt'),
            $this->comment('bots/eva', 'Meine Antwort'),
            $this->comment('alice', '/me winkt'),
            $this->comment('alice', '{"system":true}'),
            $this->comment('alice', ''),
            $this->comment('alice', 'Echte Nachricht'),
        ]);

        self::assertSame(1, $rendered['count']);
        self::assertStringContainsString('Echte Nachricht', $rendered['text']);
        self::assertStringNotContainsString('Alice hat den Raum erstellt', $rendered['text']);
        self::assertStringNotContainsString('Meine Antwort', $rendered['text']);
        self::assertStringNotContainsString('{', $rendered['text']);
    }

    /**
     * A room and a mail message must never share a synthetic file id: the mail
     * reconciliation deletes rows by id, so a collision would delete the wrong
     * document.
     */
    public function testATalkRoomIdCannotCollideWithAMailMessageId(): void {
        self::assertSame(7, Indexer::talkRoomId(Indexer::talkFileId(7)));
        self::assertSame(0, Indexer::talkRoomId(-7), 'a mail message id is not a Talk room');
        self::assertSame(0, Indexer::talkRoomId(42), 'a real file id is not a Talk room');
        self::assertLessThan(-1000000000, Indexer::talkFileId(1));
    }

    /** An indexing pass stores the room as a talk document under its room path. */
    public function testATalkPassStoresRoomsAsTalkDocuments(): void {
        [$indexer, $docMapper] = $this->harness(rooms: [
            ['id' => 12, 'token' => 'abc', 'name' => 'Projekt Alpha'],
        ], roomTranscripts: [
            12 => ['roomId' => 12, 'name' => 'Projekt Alpha', 'text' => 'Talk chat: Projekt Alpha' . "\n" . '[2026-09-01 13:00] alice: Frage um eins', 'messages' => 2],
        ]);

        $inserted = [];
        $docMapper->method('insert')->willReturnCallback(static function (Document $doc) use (&$inserted): Document {
            $inserted[] = $doc;
            $doc->setId(count($inserted));
            return $doc;
        });

        $result = $indexer->run('alice', 40, 'talk');

        self::assertSame(1, $result['processed'], json_encode($result));
        self::assertCount(1, $inserted);
        self::assertSame(Document::SOURCE_TALK, $inserted[0]->getSource());
        self::assertSame('talk://12', $inserted[0]->getPath());
        self::assertSame('Talk: Projekt Alpha', $inserted[0]->getName());
        self::assertSame(Indexer::talkFileId(12), $inserted[0]->getFileId());
    }

    /** The automatic pass leaves Talk alone unless the user enabled it. */
    public function testTalkIsNotIndexedUnlessEnabled(): void {
        [$indexer, $docMapper] = $this->harness(
            rooms: [['id' => 3, 'token' => 't', 'name' => 'Room']],
            roomTranscripts: [],
            enabled: false,
        );
        $docMapper->expects(self::never())->method('insert');

        $result = $indexer->run('alice', 40, 'all');
        self::assertSame(0, $result['processed']);
    }

    /** A room the user left must stop being searchable. */
    public function testARoomTheUserLeftIsReconciledAway(): void {
        // The room the user left still has a stored document; the room they are
        // in is re-indexed in the same pass and must be kept.
        $stale = new Document();
        $stale->setId(99);
        $stale->setFileId(Indexer::talkFileId(99));

        $removed = [];
        [$indexer, $docMapper] = $this->harness(
            rooms: [['id' => 4, 'token' => 't', 'name' => 'Aktueller Raum']],
            roomTranscripts: [
                4 => ['roomId' => 4, 'name' => 'Aktueller Raum', 'text' => 'Neu', 'messages' => 1],
            ],
            isMember: static fn(string $user, int $roomId): bool => $roomId === 4,
            storedTalkIds: [Indexer::talkFileId(4), Indexer::talkFileId(99)],
            staleDocument: $stale,
            onDelete: static function (Document $doc) use (&$removed): void {
                $removed[] = $doc->getFileId();
            },
        );

        $indexer->run('alice', 40, 'talk');

        self::assertSame([Indexer::talkFileId(99)], $removed, 'the room the user is no longer in is removed');
        unset($docMapper);
    }

    /** An unconfirmed membership yields no history at all (fails closed). */
    public function testRecallReturnsNothingWithoutAConfirmedMembership(): void {
        $searcher = $this->createMock(\OCA\EvaAi\Service\Searcher::class);
        $searcher->expects(self::never())->method('search');

        $service = new TalkTranscriptService(
            $this->createMock(AppConfig::class),
            $this->createMock(\OCP\App\IAppManager::class),
            $this->createMock(\OCP\Comments\ICommentsManager::class),
            $searcher,
            $this->createMock(LoggerInterface::class),
        );

        // Talk is not resolvable here, so membership cannot be confirmed and the
        // search must not run.
        self::assertSame([], $service->recall('alice', 5, 'Was war das Budget?'));
    }

    /**
     * @param list<array{id:int,token:string,name:string}> $rooms
     * @param array<int,array<string,mixed>> $roomTranscripts
     * @return array{0:Indexer,1:DocumentMapper,2:TalkTranscriptService}
     */
    private function harness(
        array $rooms,
        array $roomTranscripts,
        bool $enabled = true,
        ?callable $isMember = null,
        array $storedTalkIds = [],
        ?Document $staleDocument = null,
        ?callable $onDelete = null,
    ): array {
        $isMember ??= static fn(string $user, int $roomId): bool => true;
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $default = null) use ($enabled): string {
            return match ($key) {
                'talk_index_enabled' => $enabled ? '1' : '0',
                'scope_path', 'exclude_paths', 'index_run_id' => '',
                'index_cancel_requested' => '0',
                'index_running' => '0',
                default => $default ?? '',
            };
        });
        $config->method('getInt')->willReturnCallback(static function (string $key, ?int $default = null): int {
            return match ($key) {
                'talk_index_max_rooms' => 20,
                'talk_index_max_messages' => 200,
                'embed_batch_size' => 100,
                default => $default ?? 0,
            };
        });
        $config->method('setUserId');
        $config->method('set');
        $config->method('tryClaimIndex')->willReturn(true);
        $config->method('isIndexEnrolled')->willReturn(true);

        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isAvailable')->willReturn(true);
        $transcripts->method('roomsForUser')->willReturn($rooms);
        $transcripts->method('transcript')->willReturnCallback(
            static fn(string $user, int $roomId): ?array => $roomTranscripts[$roomId] ?? null,
        );
        $transcripts->method('isMember')->willReturnCallback($isMember);

        $rootFolder = $this->createMock(IRootFolder::class);
        $docMapper = $this->createMock(DocumentMapper::class);
        $docMapper->method('hashesForUser')->willReturn([]);
        $docMapper->method('stateForUser')->willReturn([]);
        $docMapper->method('findByUserAndFile')->willReturnCallback(
            static fn(string $user, int $fileId): ?Document => ($staleDocument !== null && $staleDocument->getFileId() === $fileId)
                ? $staleDocument
                : null
        );
        $docMapper->method('fileIdsForSource')->willReturn($storedTalkIds);
        $docMapper->method('findById')->willReturn(null);
        if ($onDelete !== null) {
            $docMapper->method('delete')->willReturnCallback($onDelete);
        }

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunker = $this->createMock(Chunker::class);
        $chunker->method('chunk')->willReturn([
            ['content' => 'talk chunk', 'tokens' => 3],
        ]);
        $ollama = $this->createMock(Ollama::class);
        $ollama->method('embedBatch')->willReturnCallback(
            static fn(array $texts): array => [array_map(static fn() => [1.0, 0.0], $texts), null]
        );
        $ollama->method('lastEmbeddingStats')->willReturn(['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0]);

        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('acquireSlot')->willReturn(['state' => 'running', 'position' => 0]);

        $indexer = new Indexer(
            $config,
            $rootFolder,
            $docMapper,
            $chunkMapper,
            $chunker,
            $ollama,
            $this->createMock(EmbeddingCache::class),
            $this->createMock(EmailService::class),
            $transcripts,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->createMock(LockGuard::class),
            $scheduler,
        );

        return [$indexer, $docMapper, $transcripts];
    }
}
