<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatAppendResponse;
use PHPUnit\Framework\TestCase;

final class ChatAppendResponseTest extends TestCase {
	public function testSerializesTheAppendResult(): void {
		$response = ChatAppendResponse::fromArray(['ok' => true, 'rev' => 7]);

		self::assertTrue($response->ok);
		self::assertSame(7, $response->rev);
		self::assertSame(['ok' => true, 'rev' => 7], $response->toArray());
	}

	public function testRejectsAnInvalidResponseShape(): void {
		foreach ([[], ['ok' => 1, 'rev' => 0], ['ok' => true, 'rev' => -1], ['ok' => true, 'rev' => '2']] as $payload) {
			try {
				ChatAppendResponse::fromArray($payload);
				self::fail('Expected invalid response shape to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
