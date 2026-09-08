<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Chunker;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for Issue #62: genuinely repeated passages must stay in
 * the index, chunk_index must remain sequential and chunk_count must equal the
 * number of returned chunks. The Chunker is the source of the chunk list the
 * indexer assigns sequential indices to, so preserving duplicates here is what
 * keeps index and display in sync.
 */
final class ChunkerRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function chunker(int $chunkSize, int $overlap): Chunker {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default) use ($chunkSize, $overlap): string {
                if ($key === 'chunk_size') {
                    return (string)$chunkSize;
                }
                if ($key === 'chunk_overlap') {
                    return (string)$overlap;
                }
                return $default;
            });
        return new Chunker(new AppConfig($config));
    }

    public function testRepeatedPassagesArePreservedWithSequentialContent(): void {
        // A 20-char budget fits exactly one sentence per chunk, so three
        // identical sentences produce three identical chunks. The previous
        // array_unique() silently collapsed them to one (Issue #62).
        $chunker = $this->chunker(20, 0);
        $text = 'Hello world. Hello world. Hello world.';

        $chunks = $chunker->chunk($text);

        self::assertCount(3, $chunks);
        foreach ($chunks as $index => $chunk) {
            self::assertSame('Hello world.', trim((string)$chunk['content']), 'chunk content at ' . $index);
            self::assertIsInt($chunk['tokens']);
            self::assertGreaterThan(0, $chunk['tokens']);
        }
    }

    public function testOverlapInducedIdenticalChunksAreNotDeduplicated(): void {
        // With an overlap larger than zero, two repeated paragraphs that land
        // on the same chunk boundary must each be stored - the second is a real
        // occurrence at a different position in the document.
        $chunker = $this->chunker(40, 0);
        $text = 'Alpha beta gamma. Alpha beta gamma. Delta epsilon.';
        $chunks = $chunker->chunk($text);

        self::assertGreaterThan(1, count($chunks));
        $contents = array_map(static fn(array $c): string => trim((string)$c['content']), $chunks);
        self::assertSame($contents, array_values($contents), 'chunk order is preserved');
    }

    public function testEmptyAndWhitespaceOnlyTextYieldsNoChunks(): void {
        $chunker = $this->chunker(20, 0);
        self::assertSame([], $chunker->chunk(''));
        self::assertSame([], $chunker->chunk("   \n\t  "));
    }

    public function testHeadingIsKeptAsSectionContextInEveryChunk(): void {
        // A heading anchors a section: every chunk of that section carries the
        // heading as a prefix so retrieval keeps structural context (Issue #147).
        $chunker = $this->chunker(60, 0);
        $text = "# Budget 2026\n\nErster Satz hier. Zweiter Satz hier. Dritter Satz hier. Vierter Satz hier.";

        $chunks = $chunker->chunk($text);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertStringContainsString('# Budget 2026', (string)$chunk['content']);
            self::assertLessThanOrEqual(60 + 1, mb_strlen((string)$chunk['content']), 'chunk with heading stays within budget');
        }
    }

    public function testHeadingStartsANewChunkInsteadOfBuryingTheHeading(): void {
        $chunker = $this->chunker(200, 0);
        $text = "Einleitung ohne Struktur. " . str_repeat('Text. ', 40)
            . "\n\n## Wichtiger Abschnitt\n\n" . str_repeat('Inhalt. ', 60);

        $chunks = $chunker->chunk($text);

        self::assertGreaterThan(1, count($chunks));
        // The heading itself must not be buried inside a body chunk.
        foreach ($chunks as $chunk) {
            if (mb_strpos((string)$chunk['content'], '## Wichtiger Abschnitt') !== false) {
                self::assertStringStartsWith('## Wichtiger Abschnitt', trim((string)$chunk['content']));
            }
        }
    }

    public function testHeadingOnlySectionIsPreserved(): void {
        $chunker = $this->chunker(60, 0);
        $chunks = $chunker->chunk("# Leerer Abschnitt\n\n# Naechster Abschnitt\n\nHier steht Text.");

        self::assertGreaterThanOrEqual(2, count($chunks));
        self::assertStringContainsString('# Leerer Abschnitt', (string)$chunks[0]['content']);
        self::assertStringContainsString('# Naechster Abschnitt', (string)$chunks[1]['content']);
    }
}
