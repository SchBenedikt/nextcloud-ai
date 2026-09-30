<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\DocumentChunksQuery;
use PHPUnit\Framework\TestCase;

final class DocumentChunksQueryTest extends TestCase {
	public function testAppliesBoundedPaginationAndKeepsDocumentId(): void {
		$query = DocumentChunksQuery::fromArray(['id' => '12', 'limit' => '900', 'offset' => '-3']);

		self::assertSame(12, $query->id);
		self::assertSame(500, $query->limit);
		self::assertSame(0, $query->offset);
	}

	public function testUsesDefaultsAndRejectsInvalidNumbers(): void {
		$query = DocumentChunksQuery::fromArray([]);
		self::assertSame(0, $query->id);
		self::assertSame(200, $query->limit);
		self::assertSame(0, $query->offset);

		$this->expectException(InvalidArgumentException::class);
		DocumentChunksQuery::fromArray(['offset' => 'first']);
	}
}
