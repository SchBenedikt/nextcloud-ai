<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\{AppConfig, Chunker, EmailService, IndexDocumentText, IndexTextExtractor, TalkTranscriptService};
use OCA\EvaAi\Vector\{GenerationDocumentPreparer, GenerationSourceReader, VectorDocumentSnapshot, VectorGeneration, VectorSourceAccess, VectorStoreException};
use OCP\Files\{File, Folder, IRootFolder};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerationSourcePreparationTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }

    private function fixture(int $maxBytes = GenerationSourceReader::MAX_FILE_BYTES): array {
        $config = $this->createMock(AppConfig::class);
        $config->method('userId')->willReturn('alice');
        $config->method('getInt')->willReturnCallback(static fn($key, $default) => $key === 'max_file_size' ? $maxBytes : $default);
        $access = $this->createMock(VectorSourceAccess::class);
        $files = $this->createMock(IRootFolder::class);
        $mail = $this->createMock(EmailService::class);
        $talk = $this->createMock(TalkTranscriptService::class);
        $text = new IndexDocumentText(new IndexTextExtractor($config, $this->createMock(LoggerInterface::class)), $mail);
        $reader = new GenerationSourceReader($config, $access, $files, $text, $mail, $talk);
        return compact('config', 'access', 'files', 'mail', 'talk', 'text', 'reader');
    }

    private function snapshot(string $source, string $text, array $overrides = []): VectorDocumentSnapshot {
        return VectorDocumentSnapshot::fromRow($overrides + ['id' => 10, 'user_id' => 'alice', 'file_id' => match ($source) { 'mail' => -7, 'talk' => \OCA\EvaAi\Service\Indexer::talkFileId(7), default => 9 },
            'path' => $source === 'files' ? 'Notes/source.txt' : $source . '://7', 'name' => 'source',
            'source' => $source, 'content_hash' => md5($text), 'file_mtime' => 123, 'size' => strlen($text), 'mime' => 'text/plain']);
    }

    private function file(array $fixture, string $text, int $mtime = 123, ?int $reportedSize = null): File {
        $home = $this->createMock(Folder::class);
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('source.txt');
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getPath')->willReturn('/alice/files/Notes/source.txt');
        $file->method('isReadable')->willReturn(true);
        $file->method('getSize')->willReturn($reportedSize ?? strlen($text));
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getContent')->willReturn($text);
        $file->method('fopen')->with('r')->willReturnCallback(static function () use ($text) {
            $stream = fopen('php://temp', 'w+'); fwrite($stream, $text); rewind($stream); return $stream;
        });
        $home->method('getById')->with(9)->willReturn([$file]);
        $home->method('getRelativePath')->willReturn('Notes/source.txt');
        $fixture['files']->method('getUserFolder')->with('alice')->willReturn($home);
        return $file;
    }

    public function testNativeFilePreparationKeepsTextBeyondTheAgentExcerptAndPinsSettings(): void {
        $fixture = $this->fixture();
        $text = str_repeat('Public source. ', 8000) . 'Final source marker.';
        $this->file($fixture, $text);
        $fixture['access']->expects(self::exactly(4))->method('assertAccessible');
        $source = $this->snapshot('files', trim($text));
        $generation = new VectorGeneration(str_repeat('a', 32), 'alice', 'qdrant', 'ollama', null, 'embed', str_repeat('b', 64), 3, 512, 50);
        $preparer = new GenerationDocumentPreparer($fixture['reader'], new Chunker($fixture['config']));
        $first = $preparer->prepare($generation, $source);
        $second = $preparer->prepare($generation, $source);
        self::assertSame($first->planHash, $second->planHash);
        self::assertStringContainsString('Final source marker.', implode(' ', array_column($first->chunks, 'content')));
        foreach ($first->chunks as $chunk) self::assertLessThanOrEqual(512, mb_strlen($chunk['content']));
        $otherGeneration = new VectorGeneration(str_repeat('c', 32), 'alice', 'qdrant', 'ollama', null, 'embed', str_repeat('b', 64), 3, 256, 0);
        // Plan hashes also bind the immutable generation, not just the source.
        $otherReader = $this->createMock(GenerationSourceReader::class);
        $otherReader->method('read')->willReturn($text);
        $other = (new GenerationDocumentPreparer($otherReader, new Chunker($fixture['config'])))->prepare($otherGeneration, $source);
        self::assertNotSame($first->planHash, $other->planHash);
    }

    public function testSameSizeNativeFileEditIsRejectedByTheCanonicalHash(): void {
        $fixture = $this->fixture();
        $this->file($fixture, 'Changed source');
        $fixture['access']->expects(self::once())->method('assertAccessible');
        $this->expectExceptionObject(new VectorStoreException('source_changed'));
        $fixture['reader']->read($this->snapshot('files', 'Initial source'));
    }

    public function testOversizedFileIsRejectedBeforeNativeContentAccess(): void {
        $fixture = $this->fixture();
        $fixture['files']->expects(self::never())->method('getUserFolder');
        $this->expectExceptionObject(new VectorStoreException('source_too_large'));
        $fixture['reader']->read($this->snapshot('files', 'public', ['size' => GenerationSourceReader::MAX_FILE_BYTES + 1]));
    }

    public function testAFileGrowingAfterMetadataCaptureCannotBypassTheStreamLimit(): void {
        $fixture = $this->fixture(16);
        $file = $this->file($fixture, 'Content grew beyond sixteen bytes.', 123, 6);
        $file->expects(self::never())->method('getContent');
        $this->expectExceptionObject(new VectorStoreException('source_too_large'));
        $fixture['reader']->read($this->snapshot('files', 'public'));
    }

    public function testChunkBudgetFailureDoesNotReturnAnIncompleteSourcePlan(): void {
        $reader = $this->createMock(GenerationSourceReader::class);
        $reader->method('read')->willReturn(str_repeat('x', 10000));
        $generation = new VectorGeneration(str_repeat('a', 32), 'alice', 'qdrant', 'ollama', null, 'embed', str_repeat('b', 64), 3, 128, 127);
        $this->expectExceptionObject(new VectorStoreException('source_chunk_limit'));
        (new GenerationDocumentPreparer($reader, new Chunker($this->createMock(AppConfig::class))))->prepare($generation, $this->snapshot('files', 'public'));
    }

    public function testMailUsesTheSharedEnvelopeAttachmentsAndExactOwnedId(): void {
        $fixture = $this->fixture();
        $message = ['id' => 7, 'from' => 'sender@example.org', 'to' => ['alice@example.org'], 'sent' => 0, 'subject' => 'Public fixture'];
        $fixture['files']->expects(self::never())->method('getUserFolder');
        $fixture['mail']->expects(self::once())->method('messageForIndexing')->with('alice', 7)->willReturn($message);
        $fixture['mail']->method('bodyText')->with(7, 'alice')->willReturn('Public body.');
        $fixture['mail']->method('fetchAttachments')->with('alice', 7)->willReturn([['name' => 'public.txt', 'mime' => 'text/plain', 'content' => 'Public attachment.']]);
        $canonical = "EMAIL\nFrom: sender@example.org\nTo: alice@example.org\nDate: " . date('Y-m-d H:i', 0)
            . "\nSubject: Public fixture\n\nPublic body.\n\nATTACHMENT public.txt\nPublic attachment.";
        self::assertSame($canonical, $fixture['reader']->read($this->snapshot('mail', $canonical)));
    }

    public function testTalkIsReconstructedWithTheSameNormalizationAndFreshAccessCheck(): void {
        $fixture = $this->fixture();
        $fixture['files']->expects(self::never())->method('getUserFolder');
        $fixture['talk']->expects(self::once())->method('transcript')->with('alice', 7)->willReturn(['text' => "Room\r\n\r\n\r\nPublic   decision. "]);
        $fixture['access']->expects(self::exactly(2))->method('assertAccessible');
        self::assertSame("Room\n\nPublic decision.", $fixture['reader']->read($this->snapshot('talk', "Room\n\nPublic decision.")));
    }

    public function testChangedMailFallbackCannotBeMistakenForTheIndexedFullMessage(): void {
        $fixture = $this->fixture();
        $message = ['id' => 7, 'from' => 'public', 'to' => [], 'sent' => 0, 'subject' => 'Public'];
        $fixture['mail']->method('messageForIndexing')->willReturn($message);
        $fixture['mail']->method('bodyText')->willReturn('Short preview.');
        $fixture['mail']->method('fetchAttachments')->willReturn([]);
        $this->expectExceptionObject(new VectorStoreException('source_changed'));
        $fixture['reader']->read($this->snapshot('mail', 'Originally indexed full message.'));
    }

    public function testRevocationAfterExtractionRejectsThePreparedText(): void {
        $fixture = $this->fixture();
        $this->file($fixture, 'Public source.');
        $calls = 0;
        $fixture['access']->method('assertAccessible')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 2) throw new VectorStoreException('source_unavailable');
        });
        $this->expectExceptionObject(new VectorStoreException('source_unavailable'));
        $fixture['reader']->read($this->snapshot('files', 'Public source.'));
    }

    public function testAnotherOwnerIsRejectedBeforeReadingSourceText(): void {
        $fixture = $this->fixture();
        $fixture['files']->expects(self::never())->method('getUserFolder');
        $fixture['access']->expects(self::never())->method('assertAccessible');
        $this->expectExceptionObject(new VectorStoreException('embedding_owner_mismatch'));
        $fixture['reader']->read($this->snapshot('files', 'public', ['user_id' => 'bob']));
    }
}
