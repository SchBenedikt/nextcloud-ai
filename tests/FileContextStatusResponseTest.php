<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\FileContextStatusResponse;
use PHPUnit\Framework\TestCase;

final class FileContextStatusResponseTest extends TestCase {
	public function testPreservesIndexedMissingAndAccessibleFileData(): void {
		$data = [
			'indexed' => [4],
			'missing' => [9],
			'files' => [['fileId' => 4, 'name' => 'Notes.md', 'path' => 'Projects/Notes.md']],
		];
		self::assertSame($data, FileContextStatusResponse::fromArray($data)->toArray());
	}

	public function testBuildsMissingIdsFromRequestedFiles(): void {
		$response = FileContextStatusResponse::forRequestedFiles([4, 9], [
			['fileId' => 4, 'name' => 'Notes.md', 'path' => 'Notes.md'],
		]);
		self::assertSame([4], $response->indexed);
		self::assertSame([9], $response->missing);
	}

	public function testRejectsMalformedFileEntries(): void {
		$this->expectException(InvalidArgumentException::class);
		FileContextStatusResponse::fromArray(['indexed' => [], 'missing' => [], 'files' => [['fileId' => '4']]]);
	}
}
