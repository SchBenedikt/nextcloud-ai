<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated top-level input for importing an EVA JSON export. */
final class ChatImportRequest {
	private const MAX_TOTAL_TEXT_BYTES = 10_000_000;

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
		$totalTextBytes = 0;
		foreach ($chats as $chat) {
			if (!is_array($chat)) {
				throw new InvalidArgumentException('Each imported chat must be an object.');
			}
			$messages = $chat['messages'] ?? [];
			if (!is_array($messages)) continue;
			foreach (array_slice($messages, 0, 500) as $message) {
				if (!is_array($message) || !in_array($message['role'] ?? '', ['user', 'assistant'], true)) continue;
				$text = $message['text'] ?? '';
				if (!is_string($text)) continue;
				$totalTextBytes += strlen($text);
				if ($totalTextBytes > self::MAX_TOTAL_TEXT_BYTES) {
					throw new InvalidArgumentException('The combined chat text may not exceed 10 MB.');
				}
			}
		}
		return new self($chats);
	}
}
