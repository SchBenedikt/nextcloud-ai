<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatRegenerateRequest;
use PHPUnit\Framework\TestCase;

final class ChatRegenerateRequestTest extends TestCase {
	public function testNormalizesOptionalMessageAndRevision(): void {
		$request = ChatRegenerateRequest::fromArray(['messageIndex' => 4, 'message' => ' Rewrite this ', 'rev' => '12']);
		self::assertSame(4, $request->messageIndex);
		self::assertSame('Rewrite this', $request->message);
		self::assertSame(12, $request->revision);
	}

	public function testInvalidIndexesAndRevisionKeepCompatibleFallbacks(): void {
		$request = ChatRegenerateRequest::fromArray(['messageIndex' => '4', 'rev' => 'invalid']);
		self::assertSame(-1, $request->messageIndex);
		self::assertNull($request->revision);
	}

	public function testInvalidMessageAndOversizedMessageAreRejected(): void {
		foreach ([['message' => ['not', 'text']], ['message' => str_repeat('x', 50001)]] as $input) {
			try {
				ChatRegenerateRequest::fromArray($input);
				self::fail('Expected invalid regenerate input to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
