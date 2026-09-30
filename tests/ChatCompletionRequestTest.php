<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatCompletionRequest;
use PHPUnit\Framework\TestCase;

final class ChatCompletionRequestTest extends TestCase {
	public function testParsesChatInputAndJsonHistory(): void {
		$request = ChatCompletionRequest::fromArray([
			'message' => '  Summarize this  ',
			'history' => '[{"role":"user","content":"Earlier"}]',
			'chatId' => 'chat-1',
		]);
		self::assertSame('Summarize this', $request->message);
		self::assertSame([['role' => 'user', 'content' => 'Earlier']], $request->history);
		self::assertSame('chat-1', $request->chatId);
	}

	public function testRejectsInvalidMessageHistoryAndChatId(): void {
		foreach ([
			['message' => ' '],
			['message' => str_repeat('x', 50001)],
			['message' => 'hello', 'history' => ['not-a-message']],
			['message' => 'hello', 'history' => ['bad-key' => []]],
			['message' => 'hello', 'chatId' => ['unexpected']],
		] as $input) {
			try {
				ChatCompletionRequest::fromArray($input);
				self::fail('Expected invalid chat input to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
