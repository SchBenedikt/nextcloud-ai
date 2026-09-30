<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated prompt for one bounded image-generation request. */
final class ImageGenerationRequest {
	private function __construct(
		public readonly string $prompt,
		public readonly int $count,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$prompt = $input['prompt'] ?? null;
		if (!is_string($prompt) || trim($prompt) === '' || mb_strlen(trim($prompt)) > 1000) {
			throw new InvalidArgumentException('Enter an image prompt between 1 and 1,000 characters.');
		}
		$count = $input['count'] ?? 1;
		if (filter_var($count, FILTER_VALIDATE_INT) === false || (int)$count < 1 || (int)$count > 4) {
			throw new InvalidArgumentException('Generate between one and four images at a time.');
		}
		return new self(trim($prompt), (int)$count);
	}
}
