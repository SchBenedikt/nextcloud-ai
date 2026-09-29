<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for creating a chat or changing its title. */
final class ChatTitleRequest {
	private function __construct(public readonly string $title) {
	}

	/** @param array<string,mixed> $input */
	public static function create(array $input): self {
		$title = $input['title'] ?? '';
		if (!is_string($title)) {
			throw new InvalidArgumentException('title must be a string');
		}
		return new self(trim($title));
	}

	/** @param array<string,mixed> $input */
	public static function update(array $input): self {
		$request = self::create($input);
		if ($request->title === '') {
			throw new InvalidArgumentException('title required');
		}
		return $request;
	}
}
