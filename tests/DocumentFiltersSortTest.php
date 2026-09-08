<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\DocumentMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Issue #88: documents list filters (type/folder/date/size) and sorting.
 *
 * The mapper's WHERE and ORDER BY clauses are built from validated inputs;
 * these tests pin the validation logic (safe sort keys, LIKE escaping) that
 * the real query would otherwise only exercise against a database.
 */
final class DocumentFiltersSortTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function mapper(): DocumentMapper {
        $db = $this->createMock(IDBConnection::class);
        return new DocumentMapper($db);
    }

    /** @return mixed */
    private function invokePrivate(DocumentMapper $mapper, string $method, array $args) {
        $ref = new \ReflectionMethod(DocumentMapper::class, $method);
        return $ref->invokeArgs($mapper, $args);
    }

    public function testSortKeysAreWhitelistedAndUnknownKeysFallBack(): void {
        $mapper = $this->mapper();
        // Valid keys map to real columns.
        self::assertSame(['name', 'ASC'], $this->invokePrivate($mapper, 'resolveSort', ['name', 'asc']));
        self::assertSame(['size', 'DESC'], $this->invokePrivate($mapper, 'resolveSort', ['size', 'desc']));
        self::assertSame(['chunk_count', 'ASC'], $this->invokePrivate($mapper, 'resolveSort', ['chunks', 'ASC']));
        self::assertSame(['indexed_at', 'DESC'], $this->invokePrivate($mapper, 'resolveSort', ['date', 'DESC']));
        // Anything else falls back to the default — never reaches SQL.
        self::assertSame(['indexed_at', 'DESC'], $this->invokePrivate($mapper, 'resolveSort', ['id; DROP TABLE', 'desc']));
        self::assertSame(['indexed_at', 'DESC'], $this->invokePrivate($mapper, 'resolveSort', [null, 'desc']));
        // Unknown directions default to DESC.
        self::assertSame(['indexed_at', 'DESC'], $this->invokePrivate($mapper, 'resolveSort', ['date', 'sideways']));
    }

    public function testFolderValuesAreLikeEscaped(): void {
        $mapper = $this->mapper();
        // Folder names become a prefix match (the '/%' suffix is appended by
        // applyFilters); LIKE wildcards in the folder must not leak through.
        self::assertSame('Documents\\_Archive', $this->invokePrivate($mapper, 'escapeLike', ['Documents_Archive']));
        self::assertSame('a\\%b\\%c', $this->invokePrivate($mapper, 'escapeLike', ['a%b%c']));
        self::assertSame('Notes', $this->invokePrivate($mapper, 'escapeLike', ['Notes']));
    }
}