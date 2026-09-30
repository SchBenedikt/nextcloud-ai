<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\ApiKeyCreateRequest;
use OCA\EvaAi\Dto\ApiKeyCreateResponse;
use OCA\EvaAi\Dto\ApiKeyListResponse;
use PHPUnit\Framework\TestCase;

final class ApiKeyDtoTest extends TestCase {
	public function testCreateRequestNormalizesInputAndAppliesSafeDefaults(): void {
		$request = ApiKeyCreateRequest::fromArray([
			'name' => '  Build bot  ', 'scope' => 'write', 'expiresAt' => '1900000000',
			'ipWhitelist' => "127.0.0.1, 2001:db8::1",
		]);

		self::assertSame('Build bot', $request->name);
		self::assertSame('write', $request->scope);
		self::assertSame(1900000000, $request->expiresAt);
		self::assertSame(['127.0.0.1', '2001:db8::1'], $request->ipWhitelist);
		self::assertSame('read', ApiKeyCreateRequest::fromArray(['name' => 'reader'])->scope);
	}

	public function testCreateRequestRejectsInvalidNamesScopesExpiryAndIpShapes(): void {
		foreach ([
			['name' => ' '], ['name' => ['invalid']], ['name' => 'Reader', 'scope' => 'owner'],
			['name' => 'Reader', 'expiresAt' => 1.5], ['name' => 'Reader', 'ipWhitelist' => ['127.0.0.1' => true]],
		] as $input) {
			try {
				ApiKeyCreateRequest::fromArray($input);
				self::fail('Expected invalid input to be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testTypedResponsesKeepTheExistingApiShape(): void {
		$key = [
			'id' => 'abc123', 'name' => 'Build bot', 'key_prefix' => 'eva_sk_abc', 'scope' => 'write',
			'created_at' => 100, 'expires_at' => null, 'ip_whitelist' => ['127.0.0.1'],
			'last_used_at' => 120, 'call_count' => 3,
		];
		$response = ApiKeyListResponse::fromArray([$key]);
		self::assertSame(['keys' => [$key]], $response->toArray());

		$created = ApiKeyCreateResponse::fromArray(['key' => 'eva_sk_' . str_repeat('A', 43), 'record' => $key]);
		self::assertSame($key, $created->toArray()['record']);
	}

	public function testTypedKeyResponsesRejectMalformedRecords(): void {
		$this->expectException(InvalidArgumentException::class);
		ApiKeyListResponse::fromArray([['id' => 'missing fields']]);
	}
}
