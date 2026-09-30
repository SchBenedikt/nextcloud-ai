<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for saving the user's personal knowledge file. */
final class KnowledgeContentRequest {
	private function __construct(public readonly string $content) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$content = $input['content'] ?? '';
		if (!is_string($content)) {
			throw new InvalidArgumentException('content must be a string');
		}
		if (mb_strlen($content) > 60000) {
			throw new InvalidArgumentException('Content exceeds 60,000 characters.');
		}
		return new self($content);
	}
}
