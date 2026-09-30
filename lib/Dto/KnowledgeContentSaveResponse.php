<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed payload returned by POST /api/knowledge. */
final class KnowledgeContentSaveResponse {
	private function __construct(
		public readonly bool $ok,
		public readonly int $length,
	) {
	}

	public static function saved(string $content): self {
		return new self(true, mb_strlen($content));
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		$ok = $data['ok'] ?? null;
		$length = $data['length'] ?? null;
		if (!is_bool($ok) || !is_int($length) || $length < 0) {
			throw new InvalidArgumentException('Knowledge save response requires a boolean ok and a non-negative integer length');
		}
		return new self($ok, $length);
	}

	/** @return array{ok:bool,length:int} */
	public function toArray(): array {
		return ['ok' => $this->ok, 'length' => $this->length];
	}
}
