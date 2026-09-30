<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Public API key metadata returned by the key management endpoints. */
final class ApiKeyRecord {
	/** @param list<string> $ipWhitelist */
	private function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $keyPrefix,
		public readonly string $scope,
		public readonly int $createdAt,
		public readonly ?int $expiresAt,
		public readonly array $ipWhitelist,
		public readonly ?int $lastUsedAt,
		public readonly int $callCount,
	) {
	}

	/** @param array<string,mixed> $record */
	public static function fromArray(array $record): self {
		foreach (['id', 'name', 'key_prefix', 'scope'] as $field) {
			if (!is_string($record[$field] ?? null)) {
				throw new InvalidArgumentException($field . ' must be a string');
			}
		}
		if (!in_array($record['scope'], ['read', 'write', 'admin'], true)) {
			throw new InvalidArgumentException('scope must be read, write or admin');
		}
		foreach (['created_at', 'call_count'] as $field) {
			if (!is_int($record[$field] ?? null)) {
				throw new InvalidArgumentException($field . ' must be an integer');
			}
		}
		foreach (['expires_at', 'last_used_at'] as $field) {
			if (($record[$field] ?? null) !== null && !is_int($record[$field])) {
				throw new InvalidArgumentException($field . ' must be an integer or null');
			}
		}
		$ips = $record['ip_whitelist'] ?? null;
		if (!is_array($ips) || !array_is_list($ips)) {
			throw new InvalidArgumentException('ip_whitelist must be a list of strings');
		}
		foreach ($ips as $ip) {
			if (!is_string($ip)) {
				throw new InvalidArgumentException('ip_whitelist must contain only strings');
			}
		}
		return new self(
			$record['id'], $record['name'], $record['key_prefix'], $record['scope'],
			$record['created_at'], $record['expires_at'] ?? null, $ips,
			$record['last_used_at'] ?? null, $record['call_count'],
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id, 'name' => $this->name, 'key_prefix' => $this->keyPrefix,
			'scope' => $this->scope, 'created_at' => $this->createdAt,
			'expires_at' => $this->expiresAt, 'ip_whitelist' => $this->ipWhitelist,
			'last_used_at' => $this->lastUsedAt, 'call_count' => $this->callCount,
		];
	}
}
