<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for POST /api/chats/{id}/messages. */
final class ChatAppendRequest {
	/** @param list<string> $followups @param array<string,mixed>|null $confirmation @param list<array<string,mixed>> $tools */
	private function __construct(
		public readonly string $role,
		public readonly string $text,
		public readonly array $followups,
		public readonly ?int $regenerateRev,
		public readonly ?array $confirmation,
		public readonly array $tools,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$role = $input['role'] ?? null;
		if (!is_string($role) || !in_array($role, ['user', 'assistant'], true)) {
			throw new InvalidArgumentException('role must be user or assistant');
		}

		$text = $input['text'] ?? null;
		if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 50000) {
			throw new InvalidArgumentException('text must contain 1 to 50000 characters');
		}

		$followups = self::arrayValue($input['followups'] ?? null, 'followups');
		if (count($followups) > 3) {
			$followups = array_slice($followups, 0, 3);
		}
		foreach ($followups as $followup) {
			if (!is_string($followup)) {
				throw new InvalidArgumentException('followups must contain only strings');
			}
		}

		$regenerateRev = self::optionalNonNegativeInt($input['regenerateRev'] ?? null);
		$confirmation = self::optionalObject($input['confirmation'] ?? null, 'confirmation');
		$tools = self::arrayValue($input['tools'] ?? null, 'tools');
		foreach ($tools as $tool) {
			if (!is_array($tool)) {
				throw new InvalidArgumentException('tools must contain only objects');
			}
		}

		return new self($role, trim($text), array_values($followups), $regenerateRev, $confirmation, array_values($tools));
	}

	/** @return array<mixed> */
	private static function arrayValue(mixed $value, string $field): array {
		if ($value === null || $value === '') {
			return [];
		}
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		if (is_array($value)) {
			return $value;
		}
		throw new InvalidArgumentException($field . ' must be an array');
	}

	/** @return array<string,mixed>|null */
	private static function optionalObject(mixed $value, string $field): ?array {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value)) {
			$value = json_decode($value, true);
		}
		if (!is_array($value) || array_is_list($value)) {
			throw new InvalidArgumentException($field . ' must be an object');
		}
		return $value;
	}

	private static function optionalNonNegativeInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value) && $value >= 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
			$parsed = filter_var($value, FILTER_VALIDATE_INT);
			if ($parsed !== false && $parsed >= 0) {
				return $parsed;
			}
		}
		throw new InvalidArgumentException('regenerateRev must be a non-negative integer');
	}
}
