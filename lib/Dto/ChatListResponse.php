<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed list response for GET /api/chats. */
final class ChatListResponse {
	/** @param list<ChatSummary> $chats */
	private function __construct(public readonly array $chats) {
	}

	/** @param array<mixed> $rows */
	public static function fromArray(array $rows): self {
		if (!array_is_list($rows)) {
			throw new InvalidArgumentException('chats must be a list');
		}
		$chats = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				throw new InvalidArgumentException('each chat must be an object');
			}
			$chats[] = ChatSummary::fromArray($row);
		}
		return new self($chats);
	}

	/** @return list<array<string,mixed>> */
	public function toArray(): array {
		return array_map(static fn (ChatSummary $chat): array => $chat->toArray(), $this->chats);
	}
}
