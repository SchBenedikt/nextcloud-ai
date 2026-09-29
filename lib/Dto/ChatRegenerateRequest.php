<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for replaying a user message in an existing chat. */
final class ChatRegenerateRequest {
	private function __construct(
		public readonly int $messageIndex,
		public readonly ?string $message,
		public readonly ?int $revision,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$rawIndex = $input['messageIndex'] ?? -1;
		$messageIndex = is_int($rawIndex) ? $rawIndex : -1;

		$rawMessage = $input['message'] ?? null;
		if ($rawMessage !== null && !is_string($rawMessage)) {
			throw new InvalidArgumentException('A valid user message index and non-empty message are required');
		}
		$message = $rawMessage === null ? null : trim($rawMessage);
		if ($message !== null && mb_strlen($message) > 50000) {
			throw new InvalidArgumentException('Message exceeds the maximum length of 50,000 characters.');
		}

		$rawRevision = $input['rev'] ?? null;
		$revision = null;
		if (is_int($rawRevision)) {
			$revision = $rawRevision;
		} elseif (is_string($rawRevision) && $rawRevision !== '' && ctype_digit($rawRevision)) {
			$revision = (int)$rawRevision;
		}

		return new self($messageIndex, $message, $revision);
	}
}
