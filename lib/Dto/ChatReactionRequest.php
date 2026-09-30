<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for setting or clearing feedback on an assistant message. */
final class ChatReactionRequest {
	private function __construct(
		public readonly int $index,
		public readonly string $type,
		public readonly ?bool $value,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$rawIndex = $input['index'] ?? null;
		if (is_int($rawIndex)) {
			$index = $rawIndex;
		} elseif (is_string($rawIndex) && preg_match('/^[0-9]+$/D', $rawIndex) === 1) {
			$index = filter_var($rawIndex, FILTER_VALIDATE_INT);
			if ($index === false) {
				throw new InvalidArgumentException('A valid message index and reaction type are required');
			}
		} else {
			throw new InvalidArgumentException('A valid message index and reaction type are required');
		}
		if ($index < 0) {
			throw new InvalidArgumentException('A valid message index and reaction type are required');
		}

		$type = $input['type'] ?? '';
		if (!is_string($type) || !in_array($type, ['helpful', 'bookmarked'], true)) {
			throw new InvalidArgumentException('A valid message index and reaction type are required');
		}

		$rawValue = $input['value'] ?? null;
		if (is_bool($rawValue)) {
			$value = $rawValue;
		} elseif ($rawValue === 'true' || $rawValue === '1') {
			$value = true;
		} elseif ($rawValue === 'false' || $rawValue === '0') {
			$value = false;
		} elseif ($rawValue === null) {
			$value = null;
		} else {
			throw new InvalidArgumentException('Reaction value must be boolean or null');
		}

		if ($type === 'bookmarked' && $value === null) {
			$value = false;
		}
		return new self($index, $type, $value);
	}
}
