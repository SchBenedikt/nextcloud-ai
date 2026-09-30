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
			'model' => 'gemma4:cloud',
		]);
		self::assertSame('Summarize this', $request->message);
		self::assertSame([['role' => 'user', 'content' => 'Earlier']], $request->history);
		self::assertSame('chat-1', $request->chatId);
		self::assertSame('gemma4:cloud', $request->model);
		self::assertSame([], $request->images);
	}

	public function testRejectsInvalidMessageHistoryAndChatId(): void {
		foreach ([
			['message' => ' '],
			['message' => str_repeat('x', 50001)],
			['message' => 'hello', 'history' => ['not-a-message']],
			['message' => 'hello', 'history' => ['bad-key' => []]],
			['message' => 'hello', 'chatId' => ['unexpected']],
			['message' => 'hello', 'model' => str_repeat('a', 129)],
			['message' => 'hello', 'model' => 'bad model; ignore validation'],
		] as $input) {
			try {
				ChatCompletionRequest::fromArray($input);
				self::fail('Expected invalid chat input to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testAcceptsBoundedValidatedImageAttachments(): void {
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
		$request = ChatCompletionRequest::fromArray([
			'message' => 'What is in this picture?',
			'images' => [['name' => '../picture.png', 'mime' => 'image/png', 'data' => $png]],
		]);
		self::assertSame([['name' => 'picture.png', 'mime' => 'image/png', 'data' => $png]], $request->images);
	}

	public function testRejectsUnsupportedAndForgedImageAttachments(): void {
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
		foreach ([
			[['mime' => 'image/svg+xml', 'data' => $png]],
			[['mime' => 'image/jpeg', 'data' => $png]],
			[['mime' => 'image/png', 'data' => 'not-base64!']],
			[['mime' => 'image/png', 'data' => str_repeat('A', 5592409)]],
			array_fill(0, 5, ['mime' => 'image/png', 'data' => $png]),
		] as $images) {
			try {
				ChatCompletionRequest::fromArray(['message' => 'Inspect', 'images' => $images]);
				self::fail('Expected invalid image attachments to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
