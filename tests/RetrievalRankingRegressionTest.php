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
 * Regression coverage for Issue #143: hybrid retrieval must (a) be
 * deterministic for identical inputs and (b) bound repeated chunks per
 * document so one document cannot crowd out all other relevant evidence.
 */
final class RetrievalRankingRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Build a Searcher over a small (within-pool) index of synthetic chunks.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,Document> $docs documents returned by findByIds
     */
    private function searcherOverRows(array $rows, Ollama $ollama, array $docs = []): Searcher {
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $documentMapper = $this->createMock(DocumentMapper::class);
        $chunkMapper->method('countForUser')->willReturn(count($rows));
        $chunkMapper->method('chunksForUser')->willReturn($rows);
        $documentMapper->method('findByIds')->willReturn($docs);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        return new Searcher($ollama, $chunkMapper, $documentMapper, new AppConfig($config));
    }

    /**
     * A stored document row that findByIds returns for a given id.
     */
    private function doc(int $id, string $name, string $path): Document {
        $doc = new Document();
        $doc->setId($id);
        $doc->setName($name);
        $doc->setPath($path);
        return $doc;
    }

    /**
     * @param int $id
     * @param int $documentId
     * @param string $content
     * @return array<string,mixed>
     */
    private function row(int $id, int $documentId, string $content): array {
        return [
            'id' => $id,
            'document_id' => $documentId,
            'chunk_index' => $id,
            'content' => $content,
            'embedding' => json_encode([1.0, 0.0, 0.0]),
        ];
    }

    private function ollamaReturningVector(): Ollama {
        $ollama = $this->createMock(Ollama::class);
        $ollama->method('embedQuery')->willReturn([[ [1.0, 0.0, 0.0] ], null]);
        return $ollama;
    }

    public function testResultOrderingIsDeterministicForIdenticalInputs(): void {
        // Two chunks score identically (same tokens, same embedding).
        $rows = [
            $this->row(11, 1, 'The quarterly revenue grew by twenty percent.'),
            $this->row(7, 2, 'The quarterly revenue grew by twenty percent.'),
        ];
        $searcher = $this->searcherOverRows($rows, $this->ollamaReturningVector());

        $first = $searcher->search('alice', 'quarterly revenue', 6);
        $second = $searcher->search('alice', 'quarterly revenue', 6);

        self::assertSame(
            array_column($first, 'chunkId'),
            array_column($second, 'chunkId'),
            'identical queries must produce identical orderings'
        );
    }

    public function testPerDocumentChunkCapIsEnforced(): void {
        // Doc 10 has three strongly matching chunks followed by two weaker
        // ones; doc 20 has one strongly matching chunk. The cap (3 per
        // document) must keep the weaker tail of doc 10 out of the result so
        // the relevant evidence from doc 20 is not crowded out.
        $rows = [
            $this->row(1, 10, 'Alpha project budget overview for the year.'),
            $this->row(2, 10, 'Alpha project budget breakdown by quarter.'),
            $this->row(3, 10, 'Alpha project budget risks and assumptions.'),
            $this->row(4, 10, 'Unrelated notes about the office plants.'),
            $this->row(5, 10, 'More unrelated notes about the coffee machine.'),
            $this->row(6, 20, 'Alpha project budget committee notes.'),
        ];
        $searcher = $this->searcherOverRows($rows, $this->ollamaReturningVector());

        $results = $searcher->search('alice', 'alpha project budget', 6);

        // Doc 10 keeps its cap of 3, doc 20 contributes its one strong chunk,
        // and the weak tail of doc 10 (rows 4 and 5) is kept out entirely.
        $ids = array_map(static fn(array $r): int => $r['chunkId'], $results);
        sort($ids);
        self::assertSame([1, 2, 3, 6], $ids);
        $docCounts = [];
        foreach ($results as $r) {
            $docCounts[$r['documentId']] = ($docCounts[$r['documentId']] ?? 0) + 1;
        }
        self::assertLessThanOrEqual(3, $docCounts[10] ?? 0, 'no more than the cap of chunks from one document');
        self::assertArrayHasKey(20, $docCounts, 'diverse document evidence is preserved');
    }

    public function testFilenameMatchRanksTheNamedDocumentFirst(): void {
        // The query names a file, but the chunk bodies never contain the
        // name. The stored document name/path must still produce a lexical
        // signal so "budget xlsx" surfaces the right document.
        $rows = [
            $this->row(1, 10, 'Umsatzzahlen und Deckungsbeitraege nach Quartal.'),
            $this->row(2, 20, 'Protokoll der monatlichen Teamrunde.'),
        ];
        $docs = [
            $this->doc(10, 'Budget 2026.xlsx', 'Finanzen/Budget 2026.xlsx'),
            $this->doc(20, 'Teamrunde.md', 'Notizen/Teamrunde.md'),
        ];
        $searcher = $this->searcherOverRows($rows, $this->ollamaReturningVector(), $docs);

        $results = $searcher->search('alice', 'budget xlsx', 6);

        self::assertSame([1, 2], array_column($results, 'chunkId'), 'the file whose name matches must rank first');
        self::assertSame(10, $results[0]['documentId']);
        self::assertGreaterThan(0.0, $results[0]['lexical'], 'the filename bonus must produce a lexical score');
        self::assertSame(0.0, $results[1]['lexical']);
    }

    public function testFilenameBonusNeverHidesBodyMatches(): void {
        // A body match is always stronger than a pure name match: the lexical
        // contribution of one literal body occurrence must exceed the bonus a
        // document merely carrying the term in its name receives. (RRF rank
        // ties can reorder identical dense scores, so the invariant is
        // asserted on the lexical scores themselves.)
        $rows = [
            $this->row(1, 10, 'The office plants need more water.'),
            $this->row(2, 20, 'Der Quartalsbericht fasst das Budget zusammen.'),
        ];
        $docs = [
            $this->doc(10, 'Budget 2026.xlsx', 'Finanzen/Budget 2026.xlsx'),
            $this->doc(20, 'Bericht.md', 'Notizen/Bericht.md'),
        ];
        $searcher = $this->searcherOverRows($rows, $this->ollamaReturningVector(), $docs);

        $results = $searcher->search('alice', 'budget', 6);

        $lexical = [];
        foreach ($results as $r) {
            $lexical[$r['chunkId']] = $r['lexical'];
        }
        self::assertGreaterThan(
            $lexical[1],
            $lexical[2],
            'a literal body occurrence must outweigh the filename bonus'
        );
        self::assertArrayHasKey(2, $lexical, 'the body-matching chunk stays in the result set');
    }

    public function testSingleDocumentStillFillsTheFullWindow(): void {
        // With only one relevant document the diversity cap must not starve the
        // result: the deferred tail is refilled up to topK.
        $rows = [
            $this->row(1, 10, 'Storage migration plan phase one.'),
            $this->row(2, 10, 'Storage migration plan phase two.'),
            $this->row(3, 10, 'Storage migration plan phase three.'),
            $this->row(4, 10, 'Storage migration plan phase four.'),
            $this->row(5, 10, 'Storage migration plan phase five.'),
        ];
        $searcher = $this->searcherOverRows($rows, $this->ollamaReturningVector());

        $results = $searcher->search('alice', 'storage migration plan', 5);
        self::assertCount(5, $results);
        foreach ($results as $r) {
            self::assertSame(10, $r['documentId']);
        }
    }
}
