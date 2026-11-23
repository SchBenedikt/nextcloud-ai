<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

/**
 * Parse the JSON-encoded history format used by Nextcloud Assistant.
 *
 * TaskProcessing exposes history as a list of strings. The Assistant stores
 * each message as JSON with a role and content; keep plain-text entries for
 * compatibility with older callers, but never accept caller-provided system
 * or tool roles as model instructions.
 */
final class TaskProcessingHistory {
	private const MAX_MESSAGES = 24;
	private const MAX_HISTORY_ENTRY_BYTES = 20000;
	private const MAX_MESSAGE_CHARS = 4000;
	private const MAX_TOTAL_CHARS = 16000;

	/** @return list<array{role: 'user'|'assistant', content: string}> */
	public static function parse(mixed $rawHistory): array {
		if (!is_array($rawHistory)) {
			return [];
		}
		$count = count($rawHistory);
		$start = max(0, $count - self::MAX_MESSAGES);
		// Avoid copying an unbounded caller array just to keep its newest entries.
		$rawHistory = array_values(array_slice($rawHistory, -self::MAX_MESSAGES));
		$messages = [];
		$totalChars = 0;

		// Prefer recent context when the caller exceeds the bounded history.
		for ($i = count($rawHistory) - 1; $i >= 0; $i--) {
			$entry = $rawHistory[$i];
			if (!is_string($entry) || strlen($entry) > self::MAX_HISTORY_ENTRY_BYTES) {
				continue;
			}

			$decoded = json_decode($entry, true);
			if (is_array($decoded)) {
				$role = $decoded['role'] ?? null;
				$content = self::textContent($decoded['content'] ?? null);
				// Nextcloud Assistant persists the human side as "human"; map it
				// to the standard role expected by EVA's model adapters.
				if ($role === 'human') {
					$role = 'user';
				}
				if (!in_array($role, ['user', 'assistant'], true)) {
					continue;
			}
			} else {
				// Compatibility with callers that supplied plain alternating text.
				$role = (($start + $i) % 2 === 0) ? 'user' : 'assistant';
				$content = $entry;
			}

			$content = trim($content);
			if ($content === '' || $totalChars >= self::MAX_TOTAL_CHARS) {
				continue;
			}
			$remaining = self::MAX_TOTAL_CHARS - $totalChars;
			$content = mb_substr($content, 0, min(self::MAX_MESSAGE_CHARS, $remaining));
			$totalChars += mb_strlen($content);
			$messages[] = ['role' => $role, 'content' => $content];
		}

		return array_reverse($messages);
	}

	private static function textContent(mixed $content): string {
		if (is_string($content)) {
			return $content;
		}
		if (!is_array($content)) {
			return '';
		}

		$text = [];
		foreach ($content as $part) {
			if (is_string($part)) {
				$text[] = $part;
			} elseif (is_array($part) && is_string($part['text'] ?? null)) {
				$text[] = $part['text'];
			}
		}
		return implode("\n", $text);
	}
}
