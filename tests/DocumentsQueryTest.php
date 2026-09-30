<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\DocumentsQuery;
use PHPUnit\Framework\TestCase;

final class DocumentsQueryTest extends TestCase {
	public function testNormalizesFiltersAndClampsPagination(): void {
		$query = DocumentsQuery::fromArray([
			'search' => 'budget',
			'limit' => '900',
			'offset' => '-5',
			'type' => 'application/pdf',
			'folder' => '/Reports/Q1/',
			'dateFrom' => '10',
			'sizeMax' => '2048',
			'sort' => 'name',
			'dir' => 'asc',
		]);

		self::assertSame('budget', $query->search);
		self::assertSame(500, $query->limit);
		self::assertSame(0, $query->offset);
		self::assertSame(['type' => 'application/pdf', 'folder' => 'Reports/Q1', 'dateFrom' => 10, 'sizeMax' => 2048], $query->filters);
		self::assertSame('name', $query->sort);
		self::assertSame('ASC', $query->direction);
	}

	public function testIgnoresUnsafeFilterValuesAndUsesDefaults(): void {
		$query = DocumentsQuery::fromArray(['folder' => '../secret', 'type' => 'application/pdf;DROP', 'dir' => 'sideways']);

		self::assertSame(100, $query->limit);
		self::assertSame(0, $query->offset);
		self::assertSame([], $query->filters);
		self::assertSame('DESC', $query->direction);
	}

	public function testRejectsUnexpectedTypes(): void {
		foreach ([['search' => []], ['limit' => 'lots'], ['dateTo' => '9223372036854775808']] as $input) {
			try {
				DocumentsQuery::fromArray($input);
				self::fail('Invalid document query should be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
