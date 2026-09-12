<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An indexed Talk transcript is a cache of what a user was allowed to read.
 *
 * Two things must hold when a room is served into an answer. The transcript must
 * be re-checked against the room's current membership, not against the state at
 * index time - leaving a conversation has to stop it being quoted immediately,
 * exactly like revoked file access. And the answer's source list must show what
 * the source is: a room has no file path and no file URL, so printing the
 * internal `talk://12` marker as a link would send the user to a dead dav URL.
 */
final class TalkSourceAccessTest extends TestCase {
    /** @param array<string,mixed> $overrides */
    private function service(?TalkTranscriptService $transcripts, array &$removed = []): RagService
    {
        $reflect = new \ReflectionClass(RagService::class);
        $service = $reflect->newInstanceWithoutConstructor();
        $reflect->getProperty('talkTranscripts')->setValue($service, $transcripts);
        $reflect->getProperty('logger')->setValue($service, $this->createMock(LoggerInterface::class));
        $rootFolder = $this->createMock(\OCP\Files\IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($this->createMock(\OCP\Files\Folder::class));
        $reflect->getProperty('rootFolder')->setValue($service, $rootFolder);

        $documentMapper = $this->createMock(DocumentMapper::class);
        $doc = new Document();
        $doc->setId(99);
        $doc->setFileId(-1000000012);
        $doc->setPath('talk://12');
        $documentMapper->method('findById')->willReturnCallback(static fn(int $id): ?Document => $id === 99 ? $doc : null);
        $documentMapper->method('delete')->willReturnCallback(static function (Document $d) use (&$removed): void {
            $removed[] = $d->getId();
        });
        $reflect->getProperty('documentMapper')->setValue($service, $documentMapper);

        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunkMapper->method('deleteByDocument')->willReturnCallback(static function (int $id) use (&$removed): void {
            $removed[] = 'chunks:' . $id;
        });
        $reflect->getProperty('chunkMapper')->setValue($service, $chunkMapper);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn(string $path): string => 'https://cloud.example' . $path);
        $reflect->getProperty('urlGenerator')->setValue($service, $urlGenerator);

        return $service;
    }

    /** @return array<int,array<string,mixed>> */
    private function talkResult(string $path = 'talk://12'): array
    {
        return [[
            'chunkId' => 1,
            'chunkIndex' => 0,
            'content' => 'Wir sollten das Budget erhöhen.',
            'documentId' => 99,
            'fileId' => -1000000012,
            'docPath' => $path,
            'docName' => 'Talk: Projekt Alpha',
        ]];
    }

    /** @param array<int,array<string,mixed>> $results */
    private function filter(RagService $service, array $results): array
    {
        return (new \ReflectionClass(RagService::class))
            ->getMethod('filterAccessible')
            ->invoke($service, 'alice', $results);
    }

    public function testATalkTranscriptIsServedOnlyWhileTheUserIsStillInTheRoom(): void {
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isMember')->willReturn(true);

        $kept = $this->filter($this->service($transcripts), $this->talkResult());
        self::assertCount(1, $kept, 'a member keeps seeing the room');
    }

    public function testLeavingTheRoomStopsItBeingServedAndPurgesTheTranscript(): void {
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->expects(self::once())->method('isMember')->willReturn(false);

        $removed = [];
        $kept = $this->filter($this->service($transcripts, $removed), $this->talkResult());

        self::assertSame([], $kept, 'a room the user left must not be quoted');
        // Chunks first, then the document row - the same order a revoked file is purged in.
        self::assertSame(['chunks:99', 99], $removed, 'the stale transcript is purged like a revoked file');
    }

    /**
     * Without a confirmed membership nothing is served (fail closed): a Talk
     * lookup that fails must not turn into "this room is fine".
     */
    public function testAnUnverifiableMembershipIsNeverServed(): void {
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isMember')->willThrowException(new \RuntimeException('Talk unavailable'));

        $removed = [];
        $kept = $this->filter($this->service($transcripts, $removed), $this->talkResult());

        self::assertSame([], $kept);
        self::assertSame(['chunks:99', 99], $removed);
    }

    /** Mail documents are reconciled by their own pass and stay untouched here. */
    public function testAMailDocumentIsNotTreatedAsATalkRoom(): void {
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->expects(self::never())->method('isMember');

        $results = $this->talkResult('mail://7');
        $results[0]['docName'] = 'mail 7 - Betreff';
        $kept = $this->filter($this->service($transcripts), $results);

        self::assertCount(1, $kept);
    }

    /** The source list must name the source, not print its internal marker. */
    public function testANonFileSourceIsLabelledAndCarriesNoFileLink(): void {
        $service = $this->service($this->createMock(TalkTranscriptService::class));
        [$context, $byDoc] = (new \ReflectionClass(RagService::class))
            ->getMethod('buildContext')
            ->invoke($service, 'alice', $this->talkResult());

        self::assertStringContainsString('talk://12', $context, 'the model still sees where the text came from');
        $source = array_values($byDoc)[0];
        self::assertSame('Talk: Projekt Alpha', $source['path'], 'the user sees the readable name');
        self::assertSame('', $source['url'], 'a room has no file to open');
    }

    /** A real file keeps its path and its file link. */
    public function testAFileSourceIsUnchanged(): void {
        $service = $this->service($this->createMock(TalkTranscriptService::class));
        $results = [[
            'chunkId' => 2, 'chunkIndex' => 0, 'content' => 'Text', 'documentId' => 5,
            'fileId' => 42, 'docPath' => 'Documents/Plan.md', 'docName' => 'Plan.md',
        ]];
        [$context, $byDoc] = (new \ReflectionClass(RagService::class))
            ->getMethod('buildContext')
            ->invoke($service, 'alice', $results);

        $source = array_values($byDoc)[0];
        self::assertSame('Documents/Plan.md', $source['path']);
        self::assertStringContainsString('/remote.php/dav/files/alice/Documents/Plan.md', $source['url']);
    }
}
