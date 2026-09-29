<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatMetadataRequest;
use PHPUnit\Framework\TestCase;

final class ChatMetadataRequestTest extends TestCase {
	public function testMetadataIsNormalizedAndInstructionsAreBounded(): void {
		$request = ChatMetadataRequest::fromArray([
			'pinned' => false,
			'folder' => '  Research  ',
			'instructions' => '  ' . str_repeat('x', 2100) . '  ',
			'tags' => ['alpha', 'beta'],
		]);

		self::assertFalse($request->metadata['pinned']);
		self::assertSame('Research', $request->metadata['folder']);
		self::assertSame(2000, mb_strlen($request->metadata['instructions']));
		self::assertSame(['alpha', 'beta'], $request->metadata['tags']);
	}

	public function testMalformedMetadataIsRejected(): void {
		foreach ([['pinned' => 'false'], ['folder' => 3], ['tags' => ['ok', 4]], []] as $input) {
			try {
				ChatMetadataRequest::fromArray($input);
				self::fail('Expected malformed metadata to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
