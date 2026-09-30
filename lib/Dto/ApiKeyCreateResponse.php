<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed one-time response containing a newly created secret and its metadata. */
final class ApiKeyCreateResponse {
	private function __construct(public readonly string $key, public readonly ApiKeyRecord $record) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		if (!is_string($data['key'] ?? null) || !str_starts_with($data['key'], 'eva_sk_')) {
			throw new InvalidArgumentException('key must be a generated EVA API key');
		}
		if (!is_array($data['record'] ?? null)) {
			throw new InvalidArgumentException('record must be an API key object');
		}
		return new self($data['key'], ApiKeyRecord::fromArray($data['record']));
	}

	/** @return array{key:string,record:array<string,mixed>} */
	public function toArray(): array {
		return ['key' => $this->key, 'record' => $this->record->toArray()];
	}
}
