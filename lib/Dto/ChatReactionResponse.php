<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed response returned after setting or clearing a message reaction. */
final class ChatReactionResponse {
	/** @param array<string,mixed> $reactions */
	private function __construct(public readonly array $reactions, public readonly int $rev) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		$reactions = $data['reactions'] ?? null;
		if (!is_array($reactions)) {
			throw new InvalidArgumentException('reactions must be an object');
		}
		if (array_diff(array_keys($reactions), ['helpful', 'bookmarked', 'updated']) !== []) {
			throw new InvalidArgumentException('reactions contains an unknown field');
		}
		foreach (['helpful', 'bookmarked'] as $field) {
			if (array_key_exists($field, $reactions) && !is_bool($reactions[$field])) {
				throw new InvalidArgumentException($field . ' reaction must be a boolean');
			}
		}
		if (array_key_exists('updated', $reactions) && !is_int($reactions['updated'])) {
			throw new InvalidArgumentException('updated must be an integer');
		}
		$rev = $data['rev'] ?? null;
		if (!is_int($rev) || $rev < 0) {
			throw new InvalidArgumentException('rev must be a non-negative integer');
		}
		return new self($reactions, $rev);
	}

	/** @return array{ok:true,reactions:array<string,mixed>,rev:int} */
	public function toArray(): array {
		return ['ok' => true, 'reactions' => $this->reactions, 'rev' => $this->rev];
	}
}
