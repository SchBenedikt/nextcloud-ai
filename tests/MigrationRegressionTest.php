<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class MigrationRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
    }

    private function requireOcpForSchemaFixture(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available for schema fixtures');
        }
    }

    public function testLegacyTableMigrationDropsObsoleteDuplicateTables(): void {
        $source = (string)file_get_contents(__DIR__ . '/../lib/Migration/Version101000Date20260810000000.php');
        self::assertStringContainsString('$schema->dropTable($old)', $source);
        self::assertStringContainsString('$schema->hasTable($new)', $source);
        self::assertStringContainsString('$output->warning', $source);
    }

    public function testIndexRepairKeepsLiteralLegacyNamesAndAvoidsSelfRename(): void {
        $source = (string)file_get_contents(__DIR__ . '/../lib/Migration/Version104000Date20260812000000.php');

        self::assertStringContainsString("'eva-ai_doc_user_file'", $source);
        self::assertStringContainsString("'eva-ai_doc_user'", $source);
        self::assertStringContainsString("'eva-ai_chunk_doc'", $source);
        self::assertStringContainsString("'eva_ai_doc_user_file'", $source);
        self::assertStringContainsString("'eva_ai_doc_user'", $source);
        self::assertStringContainsString("'eva_ai_chunk_doc'", $source);
        self::assertStringNotContainsString("renameIndex('eva_ai_doc_user_file', 'eva_ai_doc_user_file')", $source);
        self::assertStringNotContainsString("renameIndex('eva_ai_doc_user', 'eva_ai_doc_user')", $source);
        self::assertStringNotContainsString("renameIndex('eva_ai_chunk_doc', 'eva_ai_chunk_doc')", $source);
    }

    public function testLegacyIndexFixtureExecutesRenameAndCreatesNoSelfRename(): void {
        $this->requireOcpForSchemaFixture();
        $documentIndexes = ['eva-ai_doc_user_file', 'eva-ai_doc_user', 'ragchat_doc_user_file', 'ragchat_doc_user'];
        $chunkIndexes = ['eva-ai_chunk_doc'];
        $documentRenames = [];
        $chunkRenames = [];
        $documentDrops = [];
        $documents = $this->createMock(ITable::class);
        $chunks = $this->createMock(ITable::class);
        $documents->method('hasIndex')->willReturnCallback(fn(string $name): bool => in_array($name, $documentIndexes, true));
        $chunks->method('hasIndex')->willReturnCallback(fn(string $name): bool => in_array($name, $chunkIndexes, true));
        $documents->method('renameIndex')->willReturnCallback(function (string $old, string $new) use (&$documentIndexes, &$documentRenames, $documents): ITable {
            $documentRenames[] = [$old, $new];
            $documentIndexes = array_values(array_diff($documentIndexes, [$old]));
            $documentIndexes[] = $new;
            return $documents;
        });
        $chunks->method('renameIndex')->willReturnCallback(function (string $old, string $new) use (&$chunkIndexes, &$chunkRenames, $chunks): ITable {
            $chunkRenames[] = [$old, $new];
            $chunkIndexes = array_values(array_diff($chunkIndexes, [$old]));
            $chunkIndexes[] = $new;
            return $chunks;
        });
        $documents->method('dropIndex')->willReturnCallback(function (string $name) use (&$documentIndexes, &$documentDrops, $documents): ITable {
            $documentDrops[] = $name;
            $documentIndexes = array_values(array_diff($documentIndexes, [$name]));
            return $documents;
        });
        $documents->method('addIndex')->willReturnSelf();
        $chunks->method('addIndex')->willReturnSelf();
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(true);
        $schema->method('getTable')->willReturnCallback(static fn(string $name): ITable => str_contains($name, 'chunks') ? $chunks : $documents);
        $migration = new \OCA\EvaAi\Migration\Version104000Date20260812000000();
        $migration->changeSchema($this->createMock(IOutput::class), static fn() => $schema, []);

        self::assertSame([
            ['eva-ai_doc_user_file', 'eva_ai_doc_user_file'],
            ['eva-ai_doc_user', 'eva_ai_doc_user'],
        ], $documentRenames);
        self::assertSame([['eva-ai_chunk_doc', 'eva_ai_chunk_doc']], $chunkRenames);
        self::assertSame(['ragchat_doc_user_file', 'ragchat_doc_user'], $documentDrops);
    }

    public function testLegacyAppIdRepairIsRegisteredAndCopiesBothScopes(): void {
        $info = (string)file_get_contents(__DIR__ . '/../appinfo/info.xml');
        $repair = (string)file_get_contents(__DIR__ . '/../lib/Migration/MigrateLegacyAppIdRepairStep.php');

        self::assertStringContainsString('MigrateLegacyAppIdRepairStep', $info);
        self::assertStringContainsString('getAppKeys(self::LEGACY)', $repair);
        self::assertStringContainsString('getUserKeys($userId, self::LEGACY)', $repair);
        self::assertStringNotContainsString('$users = []', $repair);
        self::assertStringContainsString('$userCount = 0', $repair);
        self::assertStringContainsString('setAppValue(self::CURRENT', $repair);
        self::assertStringContainsString('setUserValue($userId, self::CURRENT', $repair);
    }

    public function testCommittedFrontendBuildUsesTheCurrentAppId(): void {
        $package = json_decode((string)file_get_contents(__DIR__ . '/../package.json'), true);
        self::assertSame('eva-ai', $package['name']);
        self::assertFileExists(__DIR__ . '/../js/eva_ai-main.js');
        self::assertFileExists(__DIR__ . '/../js/eva_ai_filesaction.js');
    }
}
