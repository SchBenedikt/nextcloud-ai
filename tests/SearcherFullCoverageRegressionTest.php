<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\Searcher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for Issue #61: once an index exceeds the candidate pool,
 * the dense (semantic) candidate set must be built by scanning the WHOLE index
 * in deterministic pages - not by sampling one query-derived random window that
 * can silently skip semantic-only matches.
 */
final class SearcherFullCoverageRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function searcher(ChunkMapper $chunkMapper, DocumentMapper $documentMapper, Ollama $ollama): Searcher {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        return new Searcher($ollama, $chunkMapper, $documentMapper, new AppConfig($config));
    }

    public function testIndexAbovePoolScansEveryPageInsteadOfSampling(): void {
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $documentMapper = $this->createMock(DocumentMapper::class);
        $ollama = $this->createMock(Ollama::class);

        $totalChunks = 11000; // above the 8000 chunk pool
        $chunkMapper->method('countForUser')->with('alice')->willReturn($totalChunks);
        // No query token overlaps, so the lexical prefilter contributes nothing.
        $chunkMapper->method('filterChunksByTokens')->willReturn([]);

        $pageCalls = [];
        $chunkMapper->method('chunksForUserPage')
            ->willReturnCallback(static function (string $userId, int $limit, int $offset) use (&$pageCalls): array {
                $pageCalls[] = $offset;
                return [];
            });
        // chunky id fetch is only used for ids that scored well; empty pages never score
        $chunkMapper->method('findChunksByIds')->willReturn([]);
        $chunkMapper->expects(self::never())->method('chunksForUser');

        $documentMapper->method('findByIds')->willReturn([]);
        $ollama->method('embedQuery')->willReturn([[ [1.0, 0.0, 0.0] ], null]);

        $searcher = $this->searcher($chunkMapper, $documentMapper, $ollama);
        $results = $searcher->search('alice', 'something completely unrelated to any token', 6);

        self::assertSame([], $results);
        // Every chunk must have been visited in fixed 2000-row pages: 0..10000,
        // never a random query-derived offset.
        self::assertSame([0, 2000, 4000, 6000, 8000, 10000], $pageCalls);
    }

    public function testLexicalCandidatesSurviveAndDenseIdsAreFetchedByBatches(): void {
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $documentMapper = $this->createMock(DocumentMapper::class);
        $ollama = $this->createMock(Ollama::class);

        $chunkMapper->method('countForUser')->with('alice')->willReturn(9000);
        $lexical = [
            ['id' => 1, 'document_id' => 10, 'chunk_index' => 0, 'content' => 'The project plan is stored in the roadmap file.', 'embedding' => json_encode([1.0, 0.0, 0.0])],
        ];
        $chunkMapper->method('filterChunksByTokens')->willReturn($lexical);

        $pageCalls = [];
        $chunkMapper->method('chunksForUserPage')
            ->willReturnCallback(static function (string $userId, int $limit, int $offset) use (&$pageCalls): array {
                $pageCalls[] = $offset;
                // A semantic-only match living in the middle of the index.
                return $offset === 4000
                    ? [['id' => 2, 'document_id' => 20, 'chunk_index' => 3, 'content' => 'Quarterly figures exceed all targets.', 'embedding' => json_encode([0.95, 0.0, 0.0])]]
                    : [];
            });
        $chunkMapper->method('findChunksByIds')
            ->willReturnCallback(static function (array $ids) use ($pageCalls): array {
                return in_array(2, $ids, true)
                    ? [['id' => 2, 'document_id' => 20, 'chunk_index' => 3, 'content' => 'Quarterly figures exceed all targets.', 'embedding' => json_encode([0.95, 0.0, 0.0])]]
                    : [];
            });
        $chunkMapper->expects(self::never())->method('chunksForUser');

        $documentMapper->method('findByIds')->willReturn([]);
        $ollama->method('embedQuery')->willReturn([[ [1.0, 0.0, 0.0] ], null]);

        $searcher = $this->searcher($chunkMapper, $documentMapper, $ollama);
        $results = $searcher->search('alice', 'semantic question about quarterly targets', 6);

        // Both the lexical hit and the semantic-only hit from the middle of the
        // index must be candidates again.
        $ids = array_map(static fn(array $r): int => $r['chunkId'], $results);
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
        // The dense scan still covered the full index deterministically.
        self::assertSame([0, 2000, 4000, 6000, 8000], $pageCalls);
    }	public function testFolderScopeRestrictsRetrievalToTheChosenPath(): void {
		$chunkMapper = $this->createMock(ChunkMapper::class);
		$documentMapper = $this->createMock(DocumentMapper::class);
		$ollama = $this->createMock(Ollama::class);

		$chunkMapper->method('countForUser')->with('alice')->willReturn(3);
		$chunkMapper->method('chunksForUser')->willReturn([
			['id' => 1, 'document_id' => 10, 'chunk_index' => 0, 'content' => 'Project notes about the budget.', 'embedding' => json_encode([1.0, 0.0])],
			['id' => 2, 'document_id' => 11, 'chunk_index' => 0, 'content' => 'Project notes about the budget.', 'embedding' => json_encode([1.0, 0.0])],
			['id' => 3, 'document_id' => 12, 'chunk_index' => 0, 'content' => 'Project notes about the budget.', 'embedding' => json_encode([1.0, 0.0])],
		]);

		// One doc inside the scoped folder, one directly in its parent, one
		// completely outside — the scope is the folder AND everything below it.
		$inside = new Document();
		$inside->setId(10);
		$inside->setPath('Documents/Projekte/Plan.md');
		$sibling = new Document();
		$sibling->setId(11);
		$sibling->setPath('Documents/Notes.md');
		$outside = new Document();
		$outside->setId(12);
		$outside->setPath('Private/Ideas.md');
		$documentMapper->method('findByIds')->willReturn([$inside, $sibling, $outside]);
		$ollama->method('embedQuery')->willReturn([[ [1.0, 0.0] ], null]);

		$searcher = $this->searcher($chunkMapper, $documentMapper, $ollama);
		$results = $searcher->search('alice', 'budget', 6, 'Documents/Projekte');

		// Only the chunk from Documents/Projekte/Plan.md survives the scope.
		self::assertCount(1, $results);
		self::assertSame(1, $results[0]['chunkId']);
		self::assertSame('Documents/Projekte/Plan.md', $results[0]['docPath']);

		// Without a scope the same query sees all three documents.
		$all = $searcher->search('alice', 'budget', 6);
		self::assertCount(3, $all);
	}

	public function testIndexWithinPoolKeepsFullChunkPath(): void {
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $documentMapper = $this->createMock(DocumentMapper::class);
        $ollama = $this->createMock(Ollama::class);

        $chunkMapper->method('countForUser')->with('alice')->willReturn(50);
        $row = ['id' => 7, 'document_id' => 10, 'chunk_index' => 0, 'content' => 'Small index content about storage.', 'embedding' => json_encode([1.0, 0.0])];
        $chunkMapper->method('chunksForUser')->willReturn([$row]);
        $chunkMapper->expects(self::never())->method('chunksForUserPage');
        $chunkMapper->expects(self::never())->method('filterChunksByTokens');

        $documentMapper->method('findByIds')->willReturn([]);
        $ollama->method('embedQuery')->willReturn([[ [1.0, 0.0] ], null]);

        $searcher = $this->searcher($chunkMapper, $documentMapper, $ollama);
        $results = $searcher->search('alice', 'storage', 6);

        self::assertCount(1, $results);
        self::assertSame(7, $results[0]['chunkId']);
    }
}
