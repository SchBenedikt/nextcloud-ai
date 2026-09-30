<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated queue identifier for background chat lifecycle actions. */
final class BackgroundChatIdRequest {
	private function __construct(public readonly string $id) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$value = $input['id'] ?? '';
		if (!is_string($value)) {
			throw new InvalidArgumentException('id must be a string');
		}
		$id = trim($value);
		if ($id === '') {
			throw new InvalidArgumentException('Queue id required');
		}
		if (mb_strlen($id) > 80) {
			throw new InvalidArgumentException('id is too long');
		}
		return new self($id);
	}
}
