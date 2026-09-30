<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for a chat scoped to explicitly selected files. */
final class FileContextChatRequest {
	/** @param list<int> $fileIds @param list<array{role:string,content:string}> $history */
	private function __construct(
		public readonly array $fileIds,
		public readonly string $message,
		public readonly array $history,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$fileIds = $input['fileIds'] ?? [];
		if (!is_array($fileIds) || !array_is_list($fileIds)) {
			throw new InvalidArgumentException('fileIds must be a list of positive integers');
		}
		$cleanFileIds = [];
		foreach ($fileIds as $fileId) {
			if (is_string($fileId) && preg_match('/^[0-9]+$/D', $fileId) === 1) {
				$fileId = (int)$fileId;
			}
			if (!is_int($fileId) || $fileId < 0) {
				throw new InvalidArgumentException('fileIds must contain positive integers');
			}
			if ($fileId > 0) {
				$cleanFileIds[] = $fileId;
			}
		}

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

		return new self($cleanFileIds, $message, $cleanHistory);
	}
}
