<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\ConfirmToolRequest;
use PHPUnit\Framework\TestCase;

final class ConfirmToolRequestTest extends TestCase {
	public function testAcceptsArgumentObjectAndOptionalConfirmationMetadata(): void {
		$request = ConfirmToolRequest::fromArray([
			'name' => 'create_task',
			'arguments' => ['title' => 'Review the plan'],
			'chatId' => 'chat-123',
			'confirmationToken' => 'token-456',
		]);

		self::assertSame('create_task', $request->name);
		self::assertSame(['title' => 'Review the plan'], $request->arguments);
		self::assertSame('chat-123', $request->chatId);
		self::assertSame('token-456', $request->confirmationToken);
	}

	public function testAcceptsLegacyArgsAliasAndJsonEncodedArguments(): void {
		$request = ConfirmToolRequest::fromArray([
			'name' => 'create_task',
			'args' => '{"title":"Review the plan"}',
		]);

		self::assertSame(['title' => 'Review the plan'], $request->arguments);
		self::assertNull($request->chatId);
		self::assertNull($request->confirmationToken);
	}

	public function testRejectsInvalidNamesArgumentsAndMetadata(): void {
		$invalidInputs = [
			['name' => '', 'arguments' => []],
			['name' => ['create_task'], 'arguments' => []],
			['name' => 'create_task', 'arguments' => 'not-json'],
			['name' => 'create_task', 'arguments' => '[]'],
			['name' => 'create_task', 'arguments' => ['title'], 'chatId' => []],
			['name' => 'create_task', 'arguments' => [], 'confirmationToken' => []],
		];

		foreach ($invalidInputs as $input) {
			try {
				ConfirmToolRequest::fromArray($input);
				self::fail('Invalid confirmation input should be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
