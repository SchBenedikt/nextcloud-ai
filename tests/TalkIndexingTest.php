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
     * A room must stay readable when one of its messages is longer than the
     * Comments API allows.
     *
     * Core validates a comment's message against a 1000-character limit while it
     * reads the rows, and Talk accepts much longer messages - EVA's own Talk
     * answers are longer than that. Hydrating through the API therefore threw for
     * the whole room, which is how a room silently indexed as nothing. The rows
     * are read directly for exactly this reason, so this case is the guarantee
     * that the room keeps its content.
     */
    public function testARoomStaysReadableWhenAMessageExceedsTheCommentsLimit(): void
    {
        $long = str_repeat('Sehr ausführliche Zusammenfassung. ', 60);
        self::assertGreaterThan(1000, mb_strlen($long), 'the fixture must exceed the Comments API limit');

        $service = $this->transcriptService($this->dbWithRows([
            ['actor_type' => 'users', 'actor_id' => 'admin', 'message' => 'Was war das Budget?', 'creation_timestamp' => '2026-09-11 08:08:27'],
            ['actor_type' => 'bots', 'actor_id' => 'bot-3357ec60', 'message' => $long, 'creation_timestamp' => '2026-09-11 08:08:29'],
            ['actor_type' => 'users', 'actor_id' => 'admin', 'message' => 'Danke, das reicht.', 'creation_timestamp' => '2026-09-11 08:09:00'],
        ], $maxResults));

        $transcript = $service->transcript('admin', 4);

        self::assertNotNull($transcript, 'the room became unreadable again');
        self::assertSame(2, $transcript['messages']);
        self::assertStringContainsString('Was war das Budget?', $transcript['text']);
        self::assertStringContainsString('Danke, das reicht.', $transcript['text']);
        // The bot's own answer is machinery, not conversation: indexing it would
        // double the index and let the bot quote itself.
        self::assertStringNotContainsString('Sehr ausführliche Zusammenfassung', $transcript['text']);
    }

    /**
     * The message budget has to come from the configuration when the caller does
     * not name one.
     *
     * This reads the setting through the service's own configuration object; the
     * earlier code read it from a property that does not exist, so every
     * transcript threw and no Talk room was ever indexed. Nothing caught it
     * because only the formatting was tested, never the read path.
     */
    public function testTheConfiguredBudgetIsUsedWhenTheCallerNamesNone(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => $key === 'talk_index_max_messages' ? 20 : (int)($default ?? 0)
        );

        $service = $this->transcriptService(
            $this->dbWithRows([
                ['actor_type' => 'users', 'actor_id' => 'admin', 'message' => 'Hallo', 'creation_timestamp' => '2026-09-11 08:08:27'],
            ], $maxResults),
            $config,
        );

        $transcript = $service->transcript('admin', 4);

        self::assertNotNull($transcript);
        self::assertSame(20, $maxResults, 'the configured budget was not applied');
    }

    /** Talk's own machinery is filtered by actor type, not only by its name. */
    public function testChangelogAndBotRowsAreDroppedByActorType(): void
    {
        $service = $this->transcriptService($this->dbWithRows([
            ['actor_type' => 'changelog', 'actor_id' => '', 'message' => '## Neu in Talk 25', 'creation_timestamp' => '2026-09-11 08:00:00'],
            ['actor_type' => 'bots', 'actor_id' => 'bot-3357ec60', 'message' => 'Antwort des Bots', 'creation_timestamp' => '2026-09-11 08:01:00'],
            ['actor_type' => 'users', 'actor_id' => 'admin', 'message' => 'Termin steht.', 'creation_timestamp' => '2026-09-11 08:02:00'],
        ], $maxResults));

        $transcript = $service->transcript('admin', 4);

        self::assertNotNull($transcript);
        self::assertSame(1, $transcript['messages']);
        self::assertStringContainsString('Termin steht.', $transcript['text']);
        self::assertStringNotContainsString('Neu in Talk 25', $transcript['text']);
        self::assertStringNotContainsString('Antwort des Bots', $transcript['text']);
    }

    /**
     * A room whose messages are all machinery is not indexed at all, rather than
     * stored as an empty document.
     */
    public function testARoomWithNothingWorthIndexingYieldsNoTranscript(): void
    {
        $service = $this->transcriptService($this->dbWithRows([
            ['actor_type' => 'changelog', 'actor_id' => '', 'message' => '## Neu in Talk 25', 'creation_timestamp' => '2026-09-11 08:00:00'],
            ['actor_type' => 'users', 'actor_id' => 'admin', 'message' => '{"message":"conversation_created"}', 'creation_timestamp' => '2026-09-11 08:01:00'],
        ], $maxResults));

        self::assertNull($service->transcript('admin', 2));
    }

    /** A service whose membership check is satisfied, with the given database. */
    private function transcriptService(\OCP\IDBConnection $db, ?AppConfig $config = null): TalkTranscriptService
    {
        $config ??= $this->createMock(AppConfig::class);
        return new class($config, $this->createMock(\OCP\App\IAppManager::class), $db, $this->createMock(\OCA\EvaAi\Service\Searcher::class), $this->createMock(LoggerInterface::class)) extends TalkTranscriptService {
            public function isMember(string $userId, int $roomId): bool {
                return true;
            }
        };
    }

    /**
     * A database whose chat query returns the given rows.
     *
     * `$maxResults` receives the page size the service asked for, which is how
     * the configured budget becomes observable without touching Talk.
     *
     * @param list<array<string,string>> $rows newest first, as the query returns them
     */
    private function dbWithRows(array $rows, ?int &$maxResults = null): \OCP\IDBConnection
    {
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchAll')->willReturn($rows);

        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        foreach (['eq', 'isNull', 'gt'] as $method) {
            $expr->method($method)->willReturn('1=1');
        }
        // Composite expressions have their own type, so they are mocked as one.
        $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
        $expr->method('orX')->willReturn($composite);
        $expr->method('andX')->willReturn($composite);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);
        $qb->expects(self::once())->method('setMaxResults')->willReturnCallback(
            function (int $limit) use (&$maxResults, $qb) {
                $maxResults = $limit;
                return $qb;
            }
        );

        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return $db;
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

    /**
     * A manual run must reclaim the claim of a worker that died.
     *
     * A failed Talk pass used to leave its run claimed (a killed worker never
     * reaches its cleanup), and every later manual run and cron pass then
     * answered "Indexing is already running for this user" and did nothing - the
     * index looked permanently stuck even though nothing was running.
     */
    public function testAManualRunReclaimsTheClaimOfADeadWorker(): void
    {
        [$indexer] = $this->harness(
            rooms: [['id' => 7, 'token' => 't', 'name' => 'Raum']],
            roomTranscripts: [7 => ['roomId' => 7, 'name' => 'Raum', 'text' => 'Talk chat: Raum' . "\n" . '[2026-09-01 10:00] alice: Hallo', 'messages' => 1]],
            claimHeld: true,
            claimRecovered: true,
        );

        $result = $indexer->run('alice', 40, 'talk');

        self::assertNull($result['error'], 'the stale claim still blocked the run');
        self::assertSame(1, $result['processed']);
    }

    /** A live run keeps its claim: a second worker must not steal it. */
    public function testAManualRunDoesNotStealALiveClaim(): void
    {
        [$indexer, $docMapper] = $this->harness(
            rooms: [['id' => 7, 'token' => 't', 'name' => 'Raum']],
            roomTranscripts: [7 => ['roomId' => 7, 'name' => 'Raum', 'text' => 'Talk chat: Raum', 'messages' => 1]],
            claimHeld: true,
            claimRecovered: false,
        );
        $docMapper->expects(self::never())->method('insert');

        $result = $indexer->run('alice', 40, 'talk');

        self::assertSame('Indexing is already running for this user.', $result['error']);
        self::assertSame(0, $result['processed']);
    }

    /** An unconfirmed membership yields no history at all (fails closed). */
    public function testRecallReturnsNothingWithoutAConfirmedMembership(): void {
        $searcher = $this->createMock(\OCA\EvaAi\Service\Searcher::class);
        $searcher->expects(self::never())->method('search');

        $service = new TalkTranscriptService(
            $this->createMock(AppConfig::class),
            $this->createMock(\OCP\App\IAppManager::class),
            $this->createMock(\OCP\IDBConnection::class),
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
        bool $claimHeld = false,
        bool $claimRecovered = false,
    ): array {
        $isMember ??= static fn(string $user, int $roomId): bool => true;
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $default = null) use ($enabled, $claimHeld): string {
            return match ($key) {
                'talk_index_enabled' => $enabled ? '1' : '0',
                'scope_path', 'exclude_paths', 'index_run_id' => '',
                'index_cancel_requested' => '0',
                'index_running' => $claimHeld ? '1' : '0',
                default => $default ?? '',
            };
        });
        $config->method('recoverAbandonedRun')->willReturn($claimRecovered);
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
