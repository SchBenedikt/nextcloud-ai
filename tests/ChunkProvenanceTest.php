<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests;
use OCA\EvaAi\Service\Chunker;
use OCA\EvaAi\Service\AppConfig;
use PHPUnit\Framework\TestCase;

final class ChunkProvenanceTest extends TestCase {
    public function testStructuredLocationsAndHeadingPathSurviveChunking(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('getInt')->willReturnMap([['chunk_size', 900, 128], ['chunk_overlap', 120, 20]]);
        $chunker = new Chunker($config);
        $text = "# Report\nIntro.\n## Budget\nDetails.\n[Page 2]\nSecond page.\n[Sheet: Umsatz]\nA1: 100\nA2: 200\n[Slide 3]\nConclusion.";
        $chunks = $chunker->chunk($text);
        self::assertSame(['Report', 'Budget'], $chunks[1]['provenance']['heading_path']);
        self::assertSame(2, $chunks[2]['provenance']['page']);
        self::assertSame('Umsatz', $chunks[3]['provenance']['sheet']);
        self::assertStringContainsString("A1: 100\nA2: 200", $chunks[3]['content']);
        self::assertSame(3, $chunks[4]['provenance']['slide']);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(4096, strlen(json_encode($chunk['provenance'])));
            self::assertSame($chunk['provenance']['section'], mb_substr($text, $chunk['provenance']['section_offset'], mb_strlen($chunk['provenance']['section'])));
        }
    }
    public function testMigrationAddsNullableMetadataOnlyOnce(): void {
        if (!EVA_AI_OCP_AVAILABLE) self::markTestSkipped('OCP required');
        $present = false;
        $table = $this->createMock(\OCP\DB\Schema\ITable::class);
        $table->method('hasColumn')->with('provenance')->willReturnCallback(static function () use (&$present) { return $present; });
        $table->expects(self::once())->method('addColumn')->with('provenance', \OCP\DB\Types::TEXT, ['notnull' => false])->willReturnCallback(function () use (&$present) { $present = true; return $this->createMock(\OCP\DB\Schema\IColumn::class); });
        $schema = $this->createMock(\OCP\DB\ISchemaWrapper::class);
        $schema->method('hasTable')->with('eva_ai_chunks')->willReturn(true);
        $schema->method('getTable')->willReturn($table);
        $migration = new \OCA\EvaAi\Migration\Version104008Date20260909000000();
        $migration->changeSchema($this->createMock(\OCP\Migration\IOutput::class), static fn() => $schema, []);
        $migration->changeSchema($this->createMock(\OCP\Migration\IOutput::class), static fn() => $schema, []);
    }
}
