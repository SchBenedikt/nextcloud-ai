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

	public function testSelectedDiffBlocksApplyOnlyApprovedChanges(): void {
		$service = new DiffService();
		$old = "one\nkeep\nthree\n";
		$new = "ONE\nkeep\nTHREE\n";
		$preview = $service->generateDiff($old, $new);

		self::assertCount(2, $preview['hunks']);
		self::assertSame("ONE\nkeep\nthree\n", $service->applySelectedHunks($old, $preview['hunks'], [0], true, true));
		self::assertSame($old, $service->applySelectedHunks($old, $preview['hunks'], [], true, true));
		self::assertSame($new, $service->applySelectedHunks($old, $preview['hunks'], [0, 1], true, true));

		$noFinalNewline = "one\nkeep\nthree";
		$withFinalNewline = "ONE\nkeep\nTHREE\n";
		$endingPreview = $service->generateDiff($noFinalNewline, $withFinalNewline);
		self::assertSame("ONE\nkeep\nthree", $service->applySelectedHunks($noFinalNewline, $endingPreview['hunks'], [0], true, false));

		$appendPreview = $service->generateDiff("one\n", "one\ntwo\n");
		self::assertSame("one\ntwo\n", $service->applySelectedHunks("one\n", $appendPreview['hunks'], [0], true, true));
		self::assertSame("one\n", $service->applySelectedHunks("one\n", $appendPreview['hunks'], [], true, true));
	}

	public function testSelectedDiffBlocksRejectUnknownOrDuplicateIds(): void {
		$service = new DiffService();
		$preview = $service->generateDiff("old\n", "new\n");

		foreach ([[4], [0, 0], ['0']] as $selected) {
			try {
				$service->applySelectedHunks("old\n", $preview['hunks'], $selected, true, true);
				self::fail('Invalid diff block IDs must be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
