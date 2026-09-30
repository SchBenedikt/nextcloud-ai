<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatAppendRequest;
use PHPUnit\Framework\TestCase;

final class ChatAppendRequestTest extends TestCase {
	public function testParsesTypedAndLegacyJsonEncodedOptionalFields(): void {
		$request = ChatAppendRequest::fromArray([
			'role' => 'assistant',
			'text' => ' Answer ',
			'followups' => '["Next?", "Why?"]',
			'regenerateRev' => '12',
			'confirmation' => '{"name":"create_file","arguments":{"path":"Notes/a.md"}}',
			'tools' => '[{"name":"search","status":"ok"}]',
			'model' => 'llama3.3:70b',
		]);

		self::assertSame('assistant', $request->role);
		self::assertSame('Answer', $request->text);
		self::assertSame(['Next?', 'Why?'], $request->followups);
		self::assertSame(12, $request->regenerateRev);
		self::assertSame(['name' => 'create_file', 'arguments' => ['path' => 'Notes/a.md']], $request->confirmation);
		self::assertSame([['name' => 'search', 'status' => 'ok']], $request->tools);
		self::assertSame('llama3.3:70b', $request->model);
	}

	public function testDefaultsOptionalFieldsAndBoundsFollowups(): void {
		$request = ChatAppendRequest::fromArray([
			'role' => 'user',
			'text' => 'Question',
			'followups' => ['One', 'Two', 'Three', 'Four'],
		]);

		self::assertSame(['One', 'Two', 'Three'], $request->followups);
		self::assertNull($request->regenerateRev);
		self::assertNull($request->confirmation);
		self::assertSame([], $request->tools);
		self::assertNull($request->model);
	}

	public function testRejectsInvalidMessageFieldsAndNestedPayloads(): void {
		$valid = ['role' => 'user', 'text' => 'Question'];
		foreach ([
			['role' => 'system'],
			['role' => ['user']],
			['text' => '  '],
			['text' => str_repeat('x', 50001)],
			['followups' => 'not-json'],
			['followups' => [42]],
			['regenerateRev' => '-1'],
			['confirmation' => '[]'],
			['tools' => [['name' => 'ok'], 'bad']],
			['model' => str_repeat('x', 129)],
			['model' => 'bad' . "\n" . 'model'],
			['role' => 'user', 'model' => 'llama3.3'],
		] as $overrides) {
			try {
				ChatAppendRequest::fromArray(array_replace($valid, $overrides));
				self::fail('Expected invalid append payload to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
