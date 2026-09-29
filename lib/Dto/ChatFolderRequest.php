<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated request data for chat-folder mutations. */
final class ChatFolderRequest {
	private function __construct(
		public readonly ?string $name = null,
		public readonly ?string $from = null,
		public readonly ?string $to = null,
		public readonly ?string $color = null,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function create(array $input): self {
		return new self(name: self::requiredString($input, 'name', 'Folder name required'));
	}

	/** @param array<string,mixed> $input */
	public static function rename(array $input): self {
		$from = self::requiredString($input, 'from', 'from and to are required');
		$to = self::requiredString($input, 'to', 'from and to are required');
		return new self(from: $from, to: $to);
	}

	/** @param array<string,mixed> $input */
	public static function setColor(array $input): self {
		$name = self::requiredString($input, 'name', 'A folder name and a valid hex color are required');
		$rawColor = $input['color'] ?? '';
		if (!is_string($rawColor)) {
			throw new InvalidArgumentException('A folder name and a valid hex color are required');
		}
		$color = trim($rawColor);
		if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
			throw new InvalidArgumentException('A folder name and a valid hex color are required');
		}
		return new self(name: $name, color: $color === '' ? null : $color);
	}

	/** @param array<string,mixed> $input */
	public static function delete(array $input): self {
		return new self(name: self::requiredString($input, 'name', 'Folder name required'));
	}

	/** @param array<string,mixed> $input */
	private static function requiredString(array $input, string $field, string $message): string {
		$value = $input[$field] ?? null;
		if (!is_string($value) || trim($value) === '') {
			throw new InvalidArgumentException($message);
		}
		return trim($value);
	}
}
