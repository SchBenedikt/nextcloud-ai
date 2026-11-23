<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller\Traits;

/** Resolves owner-scoped chat retrieval scope and generation instructions. */
trait ChatContextResolver {
	/** Empty string when the chat is missing or has no file scope. */
	private function scopePathFor(?string $user, mixed $chatId): string {
		if ($user === null || !is_string($chatId) || trim($chatId) === '') {
			return '';
		}
		$chat = $this->chatStore->get($user, trim($chatId));
		return $chat !== null ? trim((string)($chat['scopePath'] ?? '')) : '';
	}

	/** @return array{instructions:string,persona:string} */
	private function customFor(?string $user, mixed $chatId): array {
		if ($user === null || !is_string($chatId) || trim($chatId) === '') {
			return ['instructions' => '', 'persona' => ''];
		}
		$chat = $this->chatStore->get($user, trim($chatId));
		if ($chat === null) {
			return ['instructions' => '', 'persona' => ''];
		}
		return [
			'instructions' => trim((string)($chat['instructions'] ?? '')),
			'persona' => trim((string)($chat['persona'] ?? '')),
		];
	}
}
