<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed response for POST /api/chats/{id}/messages. */
final class ChatAppendResponse {
	private function __construct(
		public readonly bool $ok,
		public readonly int $rev,
	) {
	}

	/** @param array<string,mixed> $response */
	public static function fromArray(array $response): self {
		$ok = $response['ok'] ?? null;
		$rev = $response['rev'] ?? null;
		if (!is_bool($ok) || !is_int($rev) || $rev < 0) {
			throw new InvalidArgumentException('Chat append response requires a boolean ok and a non-negative integer rev');
		}
		return new self($ok, $rev);
	}

	/** @return array{ok:bool,rev:int} */
	public function toArray(): array {
		return ['ok' => $this->ok, 'rev' => $this->rev];
	}
}
