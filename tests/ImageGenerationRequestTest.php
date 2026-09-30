<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ImageGenerationRequest;
use PHPUnit\Framework\TestCase;

final class ImageGenerationRequestTest extends TestCase {
	public function testAcceptsTrimmedPromptAndBoundedCount(): void {
		$request = ImageGenerationRequest::fromArray(['prompt' => '  a fox in the snow  ', 'count' => '4']);
		self::assertSame('a fox in the snow', $request->prompt);
		self::assertSame(4, $request->count);
	}

	public function testUsesOneImageByDefault(): void {
		$request = ImageGenerationRequest::fromArray(['prompt' => 'A red bicycle']);
		self::assertSame(1, $request->count);
	}

	public function testRejectsEmptyLongAndInvalidImageRequests(): void {
		foreach ([
			['prompt' => ' '],
			['prompt' => str_repeat('x', 1001)],
			['prompt' => 'image', 'count' => 0],
			['prompt' => 'image', 'count' => 5],
			['prompt' => 'image', 'count' => 'many'],
		] as $input) {
			try {
				ImageGenerationRequest::fromArray($input);
				self::fail('Expected invalid image-generation request to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
