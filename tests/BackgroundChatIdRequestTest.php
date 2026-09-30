<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\BackgroundChatIdRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackgroundChatIdRequestTest extends TestCase {
	public function testTrimsQueueId(): void {
		self::assertSame('bg_12345678', BackgroundChatIdRequest::fromArray(['id' => '  bg_12345678  '])->id);
	}

	public static function invalidIds(): array {
		return [
			'missing' => [[]],
			'empty' => [['id' => '  ']],
			'non-string' => [['id' => []]],
			'overlong' => [['id' => str_repeat('x', 81)]],
		];
	}

	#[DataProvider('invalidIds')]
	public function testRejectsInvalidIds(array $input): void {
		$this->expectException(InvalidArgumentException::class);
		BackgroundChatIdRequest::fromArray($input);
	}
}
