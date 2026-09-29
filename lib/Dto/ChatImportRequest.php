<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated top-level input for importing an EVA JSON export. */
final class ChatImportRequest {
	/** @param list<array<string,mixed>> $chats */
	private function __construct(public readonly array $chats) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$chats = $input['chats'] ?? null;
		if (!is_array($chats) || !array_is_list($chats)) {
			throw new InvalidArgumentException('A JSON export with a chats array is required.');
		}
		if (count($chats) > 500) {
			throw new InvalidArgumentException('An import may contain at most 500 chats.');
		}
		foreach ($chats as $chat) {
			if (!is_array($chat)) {
				throw new InvalidArgumentException('Each imported chat must be an object.');
			}
		}
		return new self($chats);
	}
}
