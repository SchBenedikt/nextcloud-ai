<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for an explicitly confirmed mutating tool action. */
final class ConfirmToolRequest {
	/** @param array<string,mixed> $arguments */
	private function __construct(
		public readonly string $name,
		public readonly array $arguments,
		public readonly ?string $chatId,
		public readonly ?string $confirmationToken,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$name = $input['name'] ?? null;
		if (!is_string($name) || trim($name) === '') {
			throw new InvalidArgumentException('A tool name and argument object are required.');
		}

		$arguments = $input['arguments'] ?? $input['args'] ?? [];
		if (is_string($arguments)) {
			$decoded = json_decode($arguments);
			if (!$decoded instanceof \stdClass) {
				throw new InvalidArgumentException('Tool arguments must be a JSON object.');
			}
			$arguments = get_object_vars($decoded);
		}
		if (!is_array($arguments) || array_is_list($arguments) && $arguments !== []) {
			throw new InvalidArgumentException('A tool name and argument object are required.');
		}

		$chatId = self::optionalString($input['chatId'] ?? null, 'chatId');
		$confirmationToken = self::optionalString($input['confirmationToken'] ?? null, 'confirmationToken');
		return new self(trim($name), $arguments, $chatId, $confirmationToken);
	}

	private static function optionalString(mixed $value, string $name): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException($name . ' must be a string');
		}
		return $value;
	}
}
