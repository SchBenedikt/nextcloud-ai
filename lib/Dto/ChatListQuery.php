<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated search parameters for the user's chat list. */
final class ChatListQuery {
	private function __construct(public readonly ?string $search) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$value = $input['search'] ?? '';
		if (!is_string($value)) {
			throw new InvalidArgumentException('search must be a string');
		}
		$search = trim($value);
		return new self($search !== '' ? $search : null);
	}
}
