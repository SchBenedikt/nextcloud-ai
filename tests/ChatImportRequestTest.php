<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatImportRequest;
use PHPUnit\Framework\TestCase;

final class ChatImportRequestTest extends TestCase {
	public function testAcceptsAListOfChatObjects(): void {
		$request = ChatImportRequest::fromArray(['chats' => [['title' => 'Research', 'messages' => []]]]);
		self::assertSame('Research', $request->chats[0]['title']);
	}

	public function testRejectsNonListAndOversizedImports(): void {
		foreach ([['chats' => ['named' => []]], ['chats' => array_fill(0, 501, [])]] as $input) {
			try {
				ChatImportRequest::fromArray($input);
				self::fail('Expected malformed import to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testRejectsArchivesWithMoreThanTenMegabytesOfChatText(): void {
		try {
			ChatImportRequest::fromArray(['chats' => [[
				'messages' => [['role' => 'user', 'text' => str_repeat('x', 10_000_001)]],
			]]]);
			self::fail('Expected oversized chat text to be rejected.');
		} catch (\InvalidArgumentException $error) {
			self::assertSame('The combined chat text may not exceed 10 MB.', $error->getMessage());
		}
	}
}
