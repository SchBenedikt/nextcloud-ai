<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contract test for bounded chunk inspection (Issues #91/#140): a huge
 * document must never be returned in a single unbounded response.
 */
final class DocumentChunksPaginationTest extends TestCase {
    public function testChunkMapperFindByDocumentSupportsLimitAndOffset(): void {
        $mapper = (string)file_get_contents(__DIR__ . '/../lib/Db/ChunkMapper.php');
        self::assertStringContainsString(
            'public function findByDocument(int $documentId, ?int $limit = null, ?int $offset = null): array',
            $mapper
        );
        self::assertStringContainsString('$qb->setMaxResults($limit);', $mapper);
        self::assertStringContainsString('$qb->setFirstResult($offset);', $mapper);
        self::assertStringContainsString('Bounded pagination', $mapper);
        self::assertStringContainsString('#91/#140', $mapper);
    }

    public function testDocumentChunksEndpointClampsLimitAndPassesOffset(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $start = strpos($controller, 'public function documentChunks(): DataResponse');
        self::assertNotFalse($start);
        $end = strpos($controller, 'public function chat(): DataResponse', $start);
        self::assertNotFalse($end);
        $method = substr($controller, $start, $end - $start);

        self::assertStringContainsString("'limit'", $method);
        self::assertStringContainsString("'offset'", $method);
        self::assertStringContainsString('max(1, min(500, (int)($this->requestParam(\'limit\') ?? 200)))', $method);
        self::assertStringContainsString('max(0, (int)($this->requestParam(\'offset\') ?? 0))', $method);
        self::assertStringContainsString('$this->chunkMapper->findByDocument($id, $limit, $offset)', $method);
        // The response must still expose the total so the client can page.
        self::assertStringContainsString("'chunks' => (int)\$doc->getChunkCount()", $method);
    }

    public function testFrontendLoadsChunksInBoundedPages(): void {
        $view = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString("documentChunks', { id, limit: 200, offset: 0 })", $view);
        self::assertStringContainsString("documentChunks', { id, limit: 200, offset: state.chunks.length })", $view);
        self::assertStringContainsString('loadMoreChunks', $view);
        self::assertStringContainsString("Load {count} more chunks", $view);
    }
}