<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\EncryptedChatRepository;

/** Resumable, non-destructive copy from encrypted AppData into encrypted SQL. */
final class ChatStorageMigration {
	private const BATCH_SIZE = 25;

	public function __construct(private ChatStore $source, private EncryptedChatRepository $target) {}

	/** @return array{status:string,chats:int,sourceHash:string} */
	public function migrate(string $userId): array {
		if ($userId === '') throw new \InvalidArgumentException('A user ID is required.');
		$snapshot = $this->source->migrationSnapshot($userId);
		$chats = $snapshot['chats'];
		$sourceHash = $snapshot['sourceHash'];
		usort($chats, static fn(array $a, array $b): int => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

		$state = $this->target->migrationState($userId);
		if (($state['state'] ?? '') === 'complete' && hash_equals((string)$state['source_hash'], $sourceHash)) {
			return ['status' => 'already-current', 'chats' => count($chats), 'sourceHash' => $sourceHash];
		}
		if ($state === null || ($state['state'] ?? '') === 'failed'
			|| !hash_equals((string)($state['source_hash'] ?? ''), $sourceHash)) {
			$this->target->resetImportedChats($userId);
			$this->target->saveMigrationState($userId, 'running', null, $sourceHash);
			$cursor = null;
		} else {
			$cursor = is_string($state['cursor'] ?? null) ? $state['cursor'] : null;
			$this->target->saveMigrationState($userId, 'running', $cursor, $sourceHash);
		}

		$pending = array_values(array_filter($chats, static fn(array $chat): bool =>
			!is_string($cursor) || strcmp((string)($chat['id'] ?? ''), $cursor) > 0));
		$done = $cursor === null ? 0 : count($chats) - count($pending);
		foreach (array_chunk($pending, self::BATCH_SIZE) as $batch) {
			foreach ($batch as $chat) {
				$this->target->writeChat($userId, $chat);
				$cursor = (string)$chat['id'];
				$done++;
			}
			$this->target->saveMigrationState($userId, 'running', $cursor, $sourceHash);
		}

		// If a chat changed while the copy was running, never mark the old
		// snapshot complete. The next invocation resets and imports the new one.
		$latest = $this->source->migrationSnapshot($userId);
		if (!hash_equals($sourceHash, $latest['sourceHash'])) {
			$this->target->saveMigrationState($userId, 'failed', $cursor, $sourceHash, 'source_changed');
			return ['status' => 'source-changed-retry', 'chats' => $done, 'sourceHash' => $sourceHash];
		}
		foreach ($chats as $sourceChat) {
			$stored = $this->target->chat($userId, (string)$sourceChat['id']);
			if ($stored === null || !hash_equals($this->digest($sourceChat), $this->digest($stored))) {
				$this->target->saveMigrationState($userId, 'failed', $cursor, $sourceHash, 'verification_failed');
				return ['status' => 'verification-failed', 'chats' => $done, 'sourceHash' => $sourceHash];
			}
		}
		$this->target->saveMigrationState($userId, 'complete', $cursor, $sourceHash);
		return ['status' => 'complete', 'chats' => $done, 'sourceHash' => $sourceHash];
	}

	private function digest(array $value): string {
		$normalize = static function (mixed $item) use (&$normalize): mixed {
			if (!is_array($item)) return $item;
			if (!array_is_list($item)) ksort($item, SORT_STRING);
			foreach ($item as $key => $child) $item[$key] = $normalize($child);
			return $item;
		};
		return hash('sha256', json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}
}
