<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\EncryptedChatRepository;
use OCP\IUserManager;

/** Resumable, non-destructive copy from encrypted AppData into encrypted SQL. */
final class ChatStorageMigration {
	private const BATCH_SIZE = 25;

	public function __construct(private ChatStore $source, private EncryptedChatRepository $target, private IUserManager $users) {}

	/** @return array{status:string,chats:int,sourceHash:string} */
	public function migrate(string $userId): array {
		if ($userId === '') throw new \InvalidArgumentException('A user ID is required.');
		if (!$this->users->userExists($userId)) throw new \InvalidArgumentException('The Nextcloud user does not exist.');
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
			$this->source->withMigrationLock($userId, function () use ($userId, $sourceHash): void {
				if (!$this->users->userExists($userId)) return;
				$this->target->resetImportedChats($userId);
				$this->target->saveMigrationState($userId, 'running', null, $sourceHash);
			});
			if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => 0, 'sourceHash' => $sourceHash];
			$cursor = null;
		} else {
			$cursor = is_string($state['cursor'] ?? null) ? $state['cursor'] : null;
			$this->source->withMigrationLock($userId, function () use ($userId, $cursor, $sourceHash): void {
				if ($this->users->userExists($userId)) $this->target->saveMigrationState($userId, 'running', $cursor, $sourceHash);
			});
			if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => 0, 'sourceHash' => $sourceHash];
		}

		$pending = array_values(array_filter($chats, static fn(array $chat): bool =>
			!is_string($cursor) || strcmp((string)($chat['id'] ?? ''), $cursor) > 0));
		$done = $cursor === null ? 0 : count($chats) - count($pending);
		foreach (array_chunk($pending, self::BATCH_SIZE) as $batch) {
			$this->source->withMigrationLock($userId, function () use ($userId, $batch, &$cursor, &$done, $sourceHash): void {
				if (!$this->users->userExists($userId)) return;
				foreach ($batch as $chat) {
					$this->target->writeChat($userId, $chat);
					$cursor = (string)$chat['id'];
					$done++;
				}
				$this->target->saveMigrationState($userId, 'running', $cursor, $sourceHash);
			});
			if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => $done, 'sourceHash' => $sourceHash];
		}

		// If a chat changed while the copy was running, never mark the old
		// snapshot complete. The next invocation resets and imports the new one.
		$latest = $this->source->migrationSnapshot($userId);
		if (!hash_equals($sourceHash, $latest['sourceHash'])) {
			$this->source->withMigrationLock($userId, function () use ($userId, $cursor, $sourceHash): void {
				if ($this->users->userExists($userId)) $this->target->saveMigrationState($userId, 'failed', $cursor, $sourceHash, 'source_changed');
			});
			if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => $done, 'sourceHash' => $sourceHash];
			return ['status' => 'source-changed-retry', 'chats' => $done, 'sourceHash' => $sourceHash];
		}
		foreach ($chats as $sourceChat) {
			$stored = $this->target->chat($userId, (string)$sourceChat['id']);
			if ($stored === null || !hash_equals($this->digest($sourceChat), $this->digest($stored))) {
				$this->source->withMigrationLock($userId, function () use ($userId, $cursor, $sourceHash): void {
					if ($this->users->userExists($userId)) $this->target->saveMigrationState($userId, 'failed', $cursor, $sourceHash, 'verification_failed');
				});
				if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => $done, 'sourceHash' => $sourceHash];
				return ['status' => 'verification-failed', 'chats' => $done, 'sourceHash' => $sourceHash];
			}
		}
		$this->source->withMigrationLock($userId, function () use ($userId, $cursor, $sourceHash): void {
			if ($this->users->userExists($userId)) $this->target->saveMigrationState($userId, 'complete', $cursor, $sourceHash);
		});
		if (!$this->users->userExists($userId)) return ['status' => 'account-removed', 'chats' => $done, 'sourceHash' => $sourceHash];
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
