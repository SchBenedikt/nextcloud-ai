<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated filters and pagination for the user's indexed documents. */
final class DocumentsQuery {
	/** @param array<string,string|int> $filters */
	private function __construct(
		public readonly string $search,
		public readonly int $limit,
		public readonly int $offset,
		public readonly array $filters,
		public readonly ?string $sort,
		public readonly string $direction,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$search = self::stringValue($input, 'search', '');
		$limit = max(1, min(500, self::integerValue($input, 'limit', 100)));
		$offset = max(0, self::integerValue($input, 'offset', 0));
		$type = trim(self::stringValue($input, 'type', ''));
		$folder = trim(self::stringValue($input, 'folder', ''));
		$sort = self::stringValue($input, 'sort', '');
		$direction = strtolower(self::stringValue($input, 'dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
		$filters = [];

		if ($type !== '' && preg_match('/^[a-z0-9.+-]+(?:\/[a-z0-9.+-]+)?$/i', $type)) {
			$filters['type'] = $type;
		}
		if ($folder !== '') {
			$folder = trim($folder, '/');
			if (preg_match('#^(?:[^/\\\\]{1,120}/)*[^/\\\\]{1,120}$#', $folder) && !str_contains($folder, '..')) {
				$filters['folder'] = $folder;
			}
		}
		foreach (['dateFrom', 'dateTo', 'sizeMin', 'sizeMax'] as $name) {
			$value = self::integerValue($input, $name, 0);
			if ($value > 0) {
				$filters[$name] = $value;
			}
		}

		return new self($search, $limit, $offset, $filters, $sort !== '' ? $sort : null, $direction);
	}

	/** @param array<string,mixed> $input */
	private static function stringValue(array $input, string $name, string $default): string {
		$value = $input[$name] ?? $default;
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			throw new InvalidArgumentException($name . ' must be a scalar value');
		}
		return (string)$value;
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
