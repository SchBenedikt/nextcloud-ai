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
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mail messages and Talk rooms share one synthetic negative file-id space.
 *
 * Both are indexed as "not a file", and both reconcilers delete stored rows when
 * their origin disappears - a deleted mail message, a room the user left. A
 * reconciler that selects "every negative id" therefore hands the other
 * producer's rows to the wrong pass, and the mail cleanup would delete the Talk
 * rooms (and vice versa). The `source` column splits them, and these tests drive
 * both passes against a store that already holds a file, a mail message and a
 * Talk room, asserting what each pass is allowed to look at and to delete.
 */
final class NegativeIdSourceSeparationTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * The separation is a property of the indexer, not of one caller: the old
     * "negative id means mail" query must not come back.
     */
    public function testTheOldSourceLessQueryIsGone(): void {
        foreach (['lib/Service/Indexer.php', 'lib/Db/DocumentMapper.php'] as $file) {
            $source = (string)file_get_contents(__DIR__ . '/../' . $file);
            self::assertStringNotContainsString('mailFileIdsForUser', $source, $file);
        }
        $mapper = (string)file_get_contents(__DIR__ . '/../lib/Db/DocumentMapper.php');
        self::assertStringContainsString('fileIdsForSource', $mapper);
    }

    /**
     * Data in place: a file, a mail message and a Talk room are indexed. A mail
     * pass runs (one live message), and the stored message that is gone must be
     * the only row it removes.
     */
    public function testAMailPassDeletesOnlyMailRows(): void {
        $staleMailId = 7;
        $talkId = Indexer::talkFileId(12);
        [$indexer, $documentMapper, $deleted, $sourceQueries] = $this->harness(
            documents: [
                $this->document(1, 5, 'Documents/Plan.md', Document::SOURCE_FILES),
                $this->document(2, -$staleMailId, 'mail://' . $staleMailId, Document::SOURCE_MAIL),
                $this->document(3, $talkId, 'talk://12', Document::SOURCE_TALK),
            ],
            storedBySource: [
                'mail' => [-$staleMailId],
                'talk' => [$talkId],
            ],
            listMails: [$this->mail(8, 'Serverwartung')],
            messageIds: [8],
            isMember: static fn(string $user, int $roomId): bool => true,
        );

        $result = $indexer->run('alice', 40, 'mail');

        self::assertNull($result['error'], json_encode($result));
        self::assertSame([- $staleMailId], $deleted->getArrayCopy(), 'only the deleted message is removed');
        self::assertSame([['alice', 'mail']], $sourceQueries->getArrayCopy(), 'the mail pass asks for mail rows only');
        self::assertNotContains($talkId, $deleted->getArrayCopy(), 'a Talk room must never be deleted by the mail pass');
        self::assertNotContains(5, $deleted->getArrayCopy(), 'a file must never be deleted by the mail pass');
    }

    /**
     * An empty message list is ambiguous (no mail, or Mail unreachable), so a
     * mail pass must not treat it as "everything was deleted" and wipe the index.
     */
    public function testAnEmptyMailboxDoesNotWipeTheMailIndex(): void {
        [$indexer, , $deleted, $sourceQueries] = $this->harness(
            documents: [$this->document(2, -7, 'mail://7', Document::SOURCE_MAIL)],
            storedBySource: ['mail' => [-7], 'talk' => []],
            listMails: [],
            messageIds: [],
        );

        $indexer->run('alice', 40, 'mail');

        self::assertSame([], $deleted->getArrayCopy(), 'an empty list must not be read as "all messages deleted"');
        self::assertSame([], $sourceQueries->getArrayCopy(), 'no reconciliation without a confirmed message list');
    }

    /**
     * The reverse direction: a Talk pass must not touch mail or files when the
     * user has left a room.
     */
    public function testATalkPassDeletesOnlyTalkRows(): void {
        $mailId = 7;
        $talkId = Indexer::talkFileId(12);
        [$indexer, $documentMapper, $deleted, $sourceQueries] = $this->harness(
            documents: [
                $this->document(1, 5, 'Documents/Plan.md', Document::SOURCE_FILES),
                $this->document(2, -$mailId, 'mail://' . $mailId, Document::SOURCE_MAIL),
                $this->document(3, $talkId, 'talk://12', Document::SOURCE_TALK),
            ],
            storedBySource: [
                'mail' => [-$mailId],
                'talk' => [$talkId],
            ],
            listMails: [],
            messageIds: [-$mailId],
            rooms: [],
            isMember: static fn(string $user, int $roomId): bool => false,
        );

        $result = $indexer->run('alice', 40, 'talk');

        self::assertNull($result['error'], json_encode($result));
        self::assertSame([$talkId], $deleted->getArrayCopy(), 'only the room the user left is removed');
        self::assertSame([['alice', 'talk']], $sourceQueries->getArrayCopy(), 'the Talk pass asks for Talk rows only');
        self::assertNotContains(-$mailId, $deleted->getArrayCopy(), 'a mail message must survive a Talk pass');
        self::assertNotContains(5, $deleted->getArrayCopy(), 'a file must survive a Talk pass');
    }

    /**
     * A room the user is still in is kept even when it was not indexed in this
     * pass - the pass may have been bounded by `talk_index_max_rooms`, and
     * dropping the rest would silently shrink the knowledge base.
     */
    public function testARoomOutsideABoundedPassIsKept(): void {
        $talkId = Indexer::talkFileId(12);
        [$indexer, $documentMapper, $deleted, $sourceQueries] = $this->harness(
            documents: [$this->document(3, $talkId, 'talk://12', Document::SOURCE_TALK)],
            storedBySource: ['mail' => [], 'talk' => [$talkId]],
            listMails: [],
            messageIds: [],
            rooms: [],
            isMember: static fn(string $user, int $roomId): bool => true,
        );

        $indexer->run('alice', 40, 'talk');

        self::assertSame([], $deleted->getArrayCopy(), 'a room the user is still in must be kept');
    }

    /** @return array{id:int,subject:string,from:string,to:array,sent:int} */
    private function mail(int $id, string $subject): array
    {
        return ['id' => $id, 'subject' => $subject, 'from' => 'bob@example.org', 'to' => ['alice@example.org'], 'sent' => 1750000000];
    }

    private function document(int $id, int $fileId, string $path, string $source): Document
    {
        $doc = new Document();
        $doc->setId($id);
        $doc->setFileId($fileId);
        $doc->setPath($path);
        $doc->setName(basename($path));
        $doc->setSource($source);
        return $doc;
    }

    /**
     * An Indexer whose document store already holds rows, with every deletion
     * and every per-source query recorded.
     *
     * @param list<Document> $documents
     * @param array<string,int[]> $storedBySource
     * @param list<array{id:int,subject:string,from:string,to:array,sent:int}> $listMails
     * @param list<string|int> $rooms
     * @return array{0:Indexer,1:DocumentMapper,2:\ArrayObject<int, int>,3:\ArrayObject<int, array{0:string,1:string}>}
     */
    private function harness(
        array $documents,
        array $storedBySource,
        array $listMails,
        array $messageIds,
        array $rooms = [],
        ?callable $isMember = null,
    ): array {
        $byFileId = [];
        foreach ($documents as $doc) {
            $byFileId[$doc->getFileId()] = $doc;
        }

        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $default = null): string {
            return match ($key) {
                'mail_index_enabled' => '1',
                'talk_index_enabled' => '1',
                'scope_path', 'exclude_paths', 'index_run_id' => '',
                'index_cancel_requested' => '0',
                'index_running' => '0',
                default => $default ?? '',
            };
        });
        $config->method('getInt')->willReturnCallback(static function (string $key, ?int $default = null): int {
            return match ($key) {
                'mail_index_max' => 25,
                'talk_index_max_rooms' => 20,
                'talk_index_max_messages' => 200,
                'embed_batch_size' => 100,
                default => $default ?? 0,
            };
        });
        $config->method('setUserId');
        $config->method('set');
        $config->method('tryClaimIndex')->willReturn(true);

        // ArrayObject, not an array: PHP arrays are value types, so a callback
        // appending to a captured array would mutate a copy and every later
        // assertion would see the empty original.
        $deleted = new \ArrayObject();
        $sourceQueries = new \ArrayObject();
        $documentMapper = $this->createMock(DocumentMapper::class);
        $documentMapper->method('hashesForUser')->willReturn([]);
        $documentMapper->method('stateForUser')->willReturn([]);
        $documentMapper->method('findById')->willReturn(null);
        $documentMapper->method('delete')->willReturnCallback(static function (Document $doc) use (&$deleted): Document {
            $deleted[] = (int)$doc->getFileId();
            return $doc;
        });
        $documentMapper->method('findByUserAndFile')->willReturnCallback(
            static fn(string $user, int $fileId): ?Document => $byFileId[$fileId] ?? null,
        );
        $documentMapper->method('fileIdsForSource')->willReturnCallback(
            static function (string $user, string $source) use (&$sourceQueries, $storedBySource): array {
                $sourceQueries[] = [$user, $source];
                return $storedBySource[$source] ?? [];
            },
        );

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunker = $this->createMock(Chunker::class);
        $chunker->method('chunk')->willReturn([['content' => 'text', 'tokens' => 2]]);

        $ollama = $this->createMock(Ollama::class);
        $ollama->method('embedBatch')->willReturnCallback(
            static fn(array $texts): array => [array_map(static fn() => [1.0, 0.0], $texts), null]
        );
        $ollama->method('lastEmbeddingStats')->willReturn(['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0]);

        $email = $this->createMock(EmailService::class);
        $email->method('listMessages')->willReturn($listMails);
        $email->method('allMessageIds')->willReturn($messageIds);
        $email->method('bodyText')->willReturn('Bitte den Server am Freitag warten.');
        $email->method('fetchAttachments')->willReturn([]);

        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isAvailable')->willReturn(true);
        $transcripts->method('roomsForUser')->willReturn($rooms);
        $transcripts->method('transcript')->willReturn(null);
        $transcripts->method('isMember')->willReturnCallback($isMember ?? static fn(string $user, int $roomId): bool => true);

        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('acquireSlot')->willReturn(['state' => 'running', 'position' => 0]);

        $indexer = new Indexer(
            $config,
            $this->createMock(IRootFolder::class),
            $documentMapper,
            $chunkMapper,
            $chunker,
            $ollama,
            $this->createMock(EmbeddingCache::class),
            $email,
            $transcripts,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->createMock(LockGuard::class),
            $scheduler,
        );

        return [$indexer, $documentMapper, $deleted, $sourceQueries];
    }
}
