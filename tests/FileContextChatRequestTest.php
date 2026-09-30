<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\FileContextChatRequest;
use PHPUnit\Framework\TestCase;

final class FileContextChatRequestTest extends TestCase {
	public function testNormalizesIdsAndValidatesMessageAndHistory(): void {
		$request = FileContextChatRequest::fromArray([
			'fileIds' => ['12', 14, 0],
			'message' => '  Summarize these files  ',
			'history' => '[{"role":"user","content":"Earlier"}]',
		]);

		self::assertSame([12, 14], $request->fileIds);
		self::assertSame('Summarize these files', $request->message);
		self::assertSame([['role' => 'user', 'content' => 'Earlier']], $request->history);
	}

	public function testRejectsMalformedFileIdsMessageAndHistory(): void {
		foreach ([
			['fileIds' => '12', 'message' => 'Summarize'],
			['fileIds' => ['not-an-id'], 'message' => 'Summarize'],
			['fileIds' => [], 'message' => '  '],
			['fileIds' => [], 'message' => 'Summarize', 'history' => '[broken'],
			['fileIds' => [], 'message' => 'Summarize', 'history' => [['role' => 'system', 'content' => 'hidden']]],
		] as $input) {
			try {
				FileContextChatRequest::fromArray($input);
				self::fail('Malformed file context input should be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testRejectsOversizedMessage(): void {
		$this->expectException(InvalidArgumentException::class);
		FileContextChatRequest::fromArray(['message' => str_repeat('x', 50001)]);
	}
}
