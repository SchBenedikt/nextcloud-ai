<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed response for listing the current user's API keys. */
final class ApiKeyListResponse {
	/** @param list<ApiKeyRecord> $keys */
	private function __construct(public readonly array $keys) {
	}

	/** @param array<mixed> $rows */
	public static function fromArray(array $rows): self {
		if (!array_is_list($rows)) {
			throw new InvalidArgumentException('keys must be a list');
		}
		$keys = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				throw new InvalidArgumentException('each key must be an object');
			}
			$keys[] = ApiKeyRecord::fromArray($row);
		}
		return new self($keys);
	}

	/** @return array{keys:list<array<string,mixed>>} */
	public function toArray(): array {
		return ['keys' => array_map(static fn(ApiKeyRecord $key): array => $key->toArray(), $this->keys)];
	}
}
