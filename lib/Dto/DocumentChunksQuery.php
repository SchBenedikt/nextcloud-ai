<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated document ID and pagination for indexed chunk retrieval. */
final class DocumentChunksQuery {
	private function __construct(
		public readonly int $id,
		public readonly int $limit,
		public readonly int $offset,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		return new self(
			self::integerValue($input, 'id', 0),
			max(1, min(500, self::integerValue($input, 'limit', 200))),
			max(0, self::integerValue($input, 'offset', 0)),
		);
	}

	/** @param array<string,mixed> $input */
	private static function integerValue(array $input, string $name, int $default): int {
		$value = $input[$name] ?? null;
		if ($value === null || $value === '') {
			return $default;
		}
		if (is_int($value)) {
			return $value;
		}
		if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
			throw new InvalidArgumentException($name . ' must be an integer');
		}
		$parsed = filter_var($value, FILTER_VALIDATE_INT);
		if ($parsed === false) {
			throw new InvalidArgumentException($name . ' is outside the supported range');
		}
		return $parsed;
	}
}
