<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Search\EvaSearchProvider;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

final class EvaSearchProviderTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testSearchReturnsPermissionCheckedFileAndExcerpt(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $query = $this->query('budget', 10);

        $document = $this->document(7, 42, 'Budget.md', 'Documents/Budget.md');
        $documents = $this->createMock(DocumentMapper::class);
        $documents->expects(self::once())->method('findByUser')->willReturn([]);
        $documents->expects(self::once())->method('findByIds')->with([7])->willReturn([$document]);

        $chunks = $this->createMock(ChunkMapper::class);
        $chunks->expects(self::once())->method('filterChunksByTokens')->with(
            'alice', ['budget'], 200, Document::SOURCE_FILES
        )->willReturn([['document_id' => 7, 'content' => 'The approved budget is 2026.',]]);

        $root = $this->createMock(IRootFolder::class);
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->with(42)->willReturn([new \stdClass()]);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);

        $provider = $this->provider($documents, $chunks, $root);
        $result = $provider->search($user, $query)->jsonSerialize();
        $entries = array_map(static fn($entry): array => $entry->jsonSerialize(), $result['entries']);

        self::assertSame('EVA indexed files', $result['name']);
        self::assertCount(1, $entries);
        self::assertSame('Budget.md', $entries[0]['title']);
        self::assertStringContainsString('approved budget', $entries[0]['subline']);
        self::assertSame('42', $entries[0]['attributes']['fileId']);
        self::assertSame('https://cloud.test/index.php/f/42', $entries[0]['resourceUrl']);
    }

    public function testSearchExcludesTalkRowsAndInaccessibleFiles(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $documents = $this->createMock(DocumentMapper::class);
        $documents->method('findByUser')->willReturn([]);
        $documents->method('findByIds')->willReturn([
            $this->document(8, -8, 'Talk room', 'Talk/room', Document::SOURCE_TALK),
            $this->document(9, 43, 'Private.md', 'Private.md'),
        ]);
        $chunks = $this->createMock(ChunkMapper::class);
        $chunks->method('filterChunksByTokens')->willReturn([
            ['document_id' => 8, 'content' => 'budget'],
            ['document_id' => 9, 'content' => 'budget'],
        ]);
        $root = $this->createMock(IRootFolder::class);
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->with(43)->willReturn([]);
        $root->method('getUserFolder')->willReturn($folder);

        $result = $this->provider($documents, $chunks, $root)->search($user, $this->query('budget', 10))->jsonSerialize();
        self::assertSame([], $result['entries']);
    }

    private function provider(DocumentMapper $documents, ChunkMapper $chunks, IRootFolder $root): EvaSearchProvider {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text): string => $text);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/index.php/f/42');
        return new EvaSearchProvider($l10n, $urls, $root, $documents, $chunks);
    }

    private function query(string $term, int $limit): ISearchQuery {
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getTerm')->willReturn($term);
        $query->method('getLimit')->willReturn($limit);
        $query->method('getCursor')->willReturn(null);
        return $query;
    }

    private function document(int $id, int $fileId, string $name, string $path, string $source = Document::SOURCE_FILES): Document {
        $document = new Document();
        $document->setId($id);
        $document->setUserId('alice');
        $document->setFileId($fileId);
        $document->setName($name);
        $document->setPath($path);
        $document->setSource($source);
        return $document;
    }
}
