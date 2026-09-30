<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input shared by synchronous and streamed chat endpoints. */
final class ChatCompletionRequest {
	/** @param list<array{role:string,content:string}> $history */
	private function __construct(
		public readonly string $message,
		public readonly array $history,
		public readonly ?string $chatId,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$message = $input['message'] ?? null;
		if (!is_string($message) || trim($message) === '') {
			throw new InvalidArgumentException('Empty message');
		}
		$message = trim($message);
		if (mb_strlen($message) > 50000) {
			throw new InvalidArgumentException('Message exceeds the maximum length of 50,000 characters.');
		}

		$history = $input['history'] ?? [];
		if (is_string($history)) {
			$decoded = json_decode($history, true);
			if (!is_array($decoded)) {
				throw new InvalidArgumentException('history must be a list of chat messages');
			}
			$history = $decoded;
		}
		if (!is_array($history) || !array_is_list($history)) {
			throw new InvalidArgumentException('history must be a list of chat messages');
		}
		$cleanHistory = [];
		foreach ($history as $item) {
			if (!is_array($item)
				|| !in_array($item['role'] ?? null, ['user', 'assistant'], true)
				|| !is_string($item['content'] ?? null)) {
				throw new InvalidArgumentException('history contains an invalid chat message');
			}
			$cleanHistory[] = ['role' => $item['role'], 'content' => $item['content']];
		}

		$chatId = $input['chatId'] ?? null;
		if ($chatId !== null && !is_string($chatId)) {
			throw new InvalidArgumentException('chatId must be a string');
		}
		return new self($message, $cleanHistory, $chatId);
	}
}
