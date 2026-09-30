<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\ChatReactionRequest;
use OCA\EvaAi\Dto\ChatReactionResponse;
use PHPUnit\Framework\TestCase;

final class ChatReactionDtoTest extends TestCase {
	public function testReactionRequestNormalizesFormValuesAndClearSemantics(): void {
		$request = ChatReactionRequest::fromArray(['index' => '4', 'type' => 'helpful', 'value' => 'false']);
		self::assertSame(4, $request->index);
		self::assertFalse($request->value);
		self::assertNull(ChatReactionRequest::fromArray(['index' => 0, 'type' => 'helpful'])->value);
		self::assertFalse(ChatReactionRequest::fromArray(['index' => 0, 'type' => 'bookmarked'])->value);
	}

	public function testReactionRequestRejectsInvalidIndexTypeAndValue(): void {
		foreach ([
			['index' => -1, 'type' => 'helpful'],
			['index' => '1.5', 'type' => 'helpful'],
			['index' => 0, 'type' => 'admin'],
			['index' => 0, 'type' => 'helpful', 'value' => 'yes'],
		] as $input) {
			try {
				ChatReactionRequest::fromArray($input);
				self::fail('Expected malformed reaction to be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testReactionResponsePreservesThePublicShape(): void {
		$data = ['reactions' => ['helpful' => true, 'bookmarked' => false, 'updated' => 100], 'rev' => 12];
		self::assertSame(['ok' => true] + $data, ChatReactionResponse::fromArray($data)->toArray());
	}

	public function testReactionResponseRejectsMalformedServiceData(): void {
		$this->expectException(InvalidArgumentException::class);
		ChatReactionResponse::fromArray(['reactions' => ['helpful' => 'yes'], 'rev' => 1]);
	}
}
