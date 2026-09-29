<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\DiffService;
use PHPUnit\Framework\TestCase;

class DiffServiceTest extends TestCase {
	public function testGenerateDiffShowsLineChangesAndCounts(): void {
		$preview = (new DiffService())->generateDiff("name=eva\nversion=1\n", "name=eva\nversion=2\nnew=true\n");

		self::assertTrue($preview['previewable']);
		self::assertSame(2, $preview['added']);
		self::assertSame(1, $preview['removed']);
		self::assertStringContainsString(' name=eva', $preview['diff']);
		self::assertStringContainsString('-version=1', $preview['diff']);
		self::assertStringContainsString('+version=2', $preview['diff']);
		self::assertStringContainsString('+new=true', $preview['diff']);
	}

	public function testGenerateDiffRejectsBinaryAndOversizedContent(): void {
		$service = new DiffService();

		self::assertFalse($service->generateDiff("old\0data", 'new')['previewable']);
		self::assertFalse($service->generateDiff(str_repeat('a', 131073), 'new')['previewable']);
	}

	public function testGenerateDiffCanPreviewNewAndDeletedFiles(): void {
		$service = new DiffService();

		$created = $service->generateDiff('', "first\nsecond\n");
		self::assertSame(2, $created['added']);
		self::assertSame(0, $created['removed']);

		$deleted = $service->generateDiff("first\nsecond\n", '');
		self::assertSame(0, $deleted['added']);
		self::assertSame(2, $deleted['removed']);
	}
}
