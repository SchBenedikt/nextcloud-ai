<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatFolderRequest;
use PHPUnit\Framework\TestCase;

final class ChatFolderRequestTest extends TestCase {
	public function testCreateAndRenameRequestsTrimNames(): void {
		self::assertSame('Projects', ChatFolderRequest::create(['name' => ' Projects '])->name);
		$request = ChatFolderRequest::rename(['from' => ' Old ', 'to' => ' New ']);
		self::assertSame('Old', $request->from);
		self::assertSame('New', $request->to);
	}

	public function testColorCanBeClearedOrSetToHex(): void {
		self::assertNull(ChatFolderRequest::setColor(['name' => 'Projects', 'color' => ''])->color);
		self::assertSame('#a0B1c2', ChatFolderRequest::setColor(['name' => 'Projects', 'color' => '#a0B1c2'])->color);
	}

	public function testInvalidFolderInputsAreRejected(): void {
		foreach ([
			static fn() => ChatFolderRequest::create(['name' => ['array']]),
			static fn() => ChatFolderRequest::rename(['from' => 'Old']),
			static fn() => ChatFolderRequest::setColor(['name' => 'Projects', 'color' => 'red']),
			static fn() => ChatFolderRequest::delete(['name' => '']),
		] as $parse) {
			try {
				$parse();
				self::fail('Expected invalid folder input to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
