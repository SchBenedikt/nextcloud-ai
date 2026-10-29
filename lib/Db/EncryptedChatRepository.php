<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** Encrypted persistence primitives for the staged AppData-to-SQL chat cutover. */
final class EncryptedChatRepository {
	private const PREFIX = "EVA-CHAT-1\n";

	public function __construct(private IDBConnection $db, private ICrypto $crypto) {}

	/** Write chat metadata and one message atomically; content stays encrypted at rest. */
	public function writeChat(string $userId, array $chat): void {
		$id = $chat['id'] ?? null;
		if (!is_string($id) || $id === '' || strlen($id) > 128) {
			throw new \InvalidArgumentException('Invalid chat identifier.');
		}
		$messages = $chat['messages'] ?? [];
		if (!is_array($messages) || !array_is_list($messages)) {
			throw new \InvalidArgumentException('Invalid chat messages.');
		}
		$metadata = $chat;
		unset($metadata['messages']);
		$owner = hash('sha256', $userId);
		$now = time();
		$this->db->beginTransaction();
		try {
			$this->upsertMetadata($owner, $id, (int)($chat['created'] ?? $now), (int)($chat['updated'] ?? $now), count($messages), $this->seal($metadata));
			$this->deleteMessagesFrom($owner, $id, 0);
			foreach ($messages as $index => $message) {
				if (!is_array($message)) {
					throw new \InvalidArgumentException('Invalid chat message.');
				}
				$this->insertMessage($owner, $id, $index, (int)($message['created'] ?? $now), $this->seal($message));
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Mirror a ChatStore change using message-level inserts/replacements/deletes. */
	public function syncChats(string $userId, array $previous, array $current): void {
		$owner = hash('sha256', $userId);
		$before = [];
		$after = [];
		foreach ($previous as $chat) if (is_array($chat) && is_string($chat['id'] ?? null)) $before[$chat['id']] = $chat;
		foreach ($current as $chat) if (is_array($chat) && is_string($chat['id'] ?? null)) $after[$chat['id']] = $chat;
		$this->db->beginTransaction();
		try {
			foreach (array_diff_key($before, $after) as $id => $_) {
				$this->deleteMessagesFrom($owner, $id, 0);
				$qb = $this->db->getQueryBuilder();
				$qb->delete('eva_ai_chats')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
					->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($id)))->executeStatement();
			}
			foreach ($after as $id => $chat) {
				$old = $before[$id] ?? [];
				$messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
				$oldMessages = is_array($old['messages'] ?? null) ? $old['messages'] : [];
				$metadata = $chat;
				unset($metadata['messages']);
				$oldMetadata = $old;
				unset($oldMetadata['messages']);
				if (!isset($before[$id]) || $this->digest($metadata) !== $this->digest($oldMetadata)
					|| count($messages) !== count($oldMessages)) {
					$this->upsertMetadata($owner, $id, (int)($chat['created'] ?? time()), (int)($chat['updated'] ?? time()), count($messages), $this->seal($metadata));
				}
				$firstChanged = min(count($messages), count($oldMessages));
				for ($i = 0; $i < min(count($messages), count($oldMessages)); $i++) {
					if ($this->digest($messages[$i]) !== $this->digest($oldMessages[$i])) { $firstChanged = $i; break; }
				}
				if ($firstChanged < count($oldMessages)) $this->deleteMessagesFrom($owner, $id, $firstChanged);
				for ($i = $firstChanged; $i < count($messages); $i++) {
					$this->insertMessage($owner, $id, $i, (int)($messages[$i]['created'] ?? time()), $this->seal($messages[$i]));
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}

	public function metadata(string $userId, string $chatId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'updated_at', 'message_count', 'encrypted_metadata')->from('eva_ai_chats')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))))
			->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)));
		$result = $qb->executeQuery();
		try { $row = $result->fetch(); } finally { $result->closeCursor(); }
		if ($row === false) return null;
		return [
			'created' => (int)$row['created_at'], 'updated' => (int)$row['updated_at'],
			'count' => (int)$row['message_count'],
			'metadata' => $this->open((string)$row['encrypted_metadata']),
		];
	}

	public function chat(string $userId, string $chatId): ?array {
		$record = $this->metadata($userId, $chatId);
		if ($record === null) return null;
		$chat = $record['metadata'];
		$chat['created'] = $record['created'];
		$chat['updated'] = $record['updated'];
		$chat['messages'] = $this->messages($userId, $chatId, 0, 1000);
		if (count($chat['messages']) !== $record['count']) return null;
		return $chat;
	}

	/** Update encrypted conversation metadata without loading its messages. */
	public function updateMetadata(string $userId, string $chatId, array $metadata): bool {
		$owner = hash('sha256', $userId);
		unset($metadata['messages']);
		$record = $this->metadata($userId, $chatId);
		if ($record === null) return false;
		$created = $record['created'];
		$updated = (int)($metadata['updated'] ?? time());
		$qb = $this->db->getQueryBuilder();
		$qb->update('eva_ai_chats')->set('created_at', $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($updated, IQueryBuilder::PARAM_INT))
			->set('message_count', $qb->createNamedParameter($record['count'], IQueryBuilder::PARAM_INT))
			->set('encrypted_metadata', $qb->createNamedParameter($this->seal($metadata)))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)));
		$qb->executeStatement();
		return true;
	}

	/** Replace one encrypted message and update its conversation metadata atomically. */
	public function replaceMessage(string $userId, string $chatId, int $index, string $text): bool {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			$record = $this->metadata($userId, $chatId);
			if ($record === null || $index < 0 || $index >= $record['count']) {
				$this->db->commit();
				return false;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('encrypted_message')->from('eva_ai_chat_messages')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
				->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)))
				->andWhere($qb->expr()->eq('message_index', $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			try { $raw = $result->fetchOne(); } finally { $result->closeCursor(); }
			if (!is_string($raw)) {
				$this->db->commit();
				return false;
			}
			$message = $this->open($raw);
			$message['text'] = $text;
			$update = $this->db->getQueryBuilder();
			$update->update('eva_ai_chat_messages')->set('encrypted_message', $update->createNamedParameter($this->seal($message)))
				->where($update->expr()->eq('user_id', $update->createNamedParameter($owner)))
				->andWhere($update->expr()->eq('chat_id', $update->createNamedParameter($chatId)))
				->andWhere($update->expr()->eq('message_index', $update->createNamedParameter($index, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$metadata = $record['metadata'];
			$metadata['updated'] = time();
			$metadata['rev'] = (int)($metadata['rev'] ?? 0) + 1;
			$this->updateMetadata($userId, $chatId, $metadata);
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Remove a message tail while preserving the encrypted per-chat model. */
	public function truncateAfter(string $userId, string $chatId, int $fromIndex): bool {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			$record = $this->metadata($userId, $chatId);
			if ($record === null || $fromIndex < 0 || $fromIndex >= $record['count']) {
				$this->db->commit();
				return false;
			}
			$this->deleteMessagesFrom($owner, $chatId, $fromIndex);
			$metadata = $record['metadata'];
			$metadata['updated'] = time();
			$metadata['rev'] = (int)($metadata['rev'] ?? 0) + 1;
			$qb = $this->db->getQueryBuilder();
			$qb->update('eva_ai_chats')->set('updated_at', $qb->createNamedParameter($metadata['updated'], IQueryBuilder::PARAM_INT))
				->set('message_count', $qb->createNamedParameter($fromIndex, IQueryBuilder::PARAM_INT))
				->set('encrypted_metadata', $qb->createNamedParameter($this->seal($metadata)))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
				->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)))->executeStatement();
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Mark one encrypted conversation for regeneration without loading account history. */
	public function beginRegenerate(string $userId, string $chatId, int $messageIndex, ?string $newText, ?int $expectedRev): array {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			$record = $this->metadata($userId, $chatId);
			if ($record === null) {
				$this->db->commit();
				return ['ok' => false, 'error' => 'not_found'];
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('encrypted_message')->from('eva_ai_chat_messages')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
				->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)))
				->andWhere($qb->expr()->eq('message_index', $qb->createNamedParameter($messageIndex, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			try { $raw = $result->fetchOne(); } finally { $result->closeCursor(); }
			$message = is_string($raw) ? $this->open($raw) : [];
			$currentRev = (int)($record['metadata']['rev'] ?? 0);
			$validTarget = $messageIndex >= 0 && $messageIndex < $record['count']
				&& ($message['role'] ?? '') === 'user'
				&& ($newText === null || trim($newText) !== '')
				&& ($newText !== null || trim((string)($message['text'] ?? '')) !== '');
			if (!$validTarget) {
				$this->db->commit();
				return ['ok' => false, 'error' => 'invalid'];
			}
			$pending = $record['metadata']['regenerate'] ?? null;
			if (is_array($pending)) {
				if ($expectedRev === null || ((int)($pending['baseRev'] ?? -1) !== $expectedRev && $currentRev !== $expectedRev)) {
					$this->db->commit();
					return ['ok' => false, 'error' => 'conflict'];
				}
			} elseif ($expectedRev !== null && $currentRev !== $expectedRev) {
				$this->db->commit();
				return ['ok' => false, 'error' => 'conflict'];
			}
			$rev = $currentRev + 1;
			$baseRev = is_array($pending) ? (int)($pending['baseRev'] ?? $currentRev) : $currentRev;
			$metadata = $record['metadata'];
			$metadata['regenerate'] = ['messageIndex' => $messageIndex, 'newText' => $newText, 'rev' => $rev, 'baseRev' => $baseRev];
			$metadata['rev'] = $rev;
			$metadata['updated'] = time();
			$update = $this->db->getQueryBuilder();
			$update->update('eva_ai_chats')->set('updated_at', $update->createNamedParameter($metadata['updated'], IQueryBuilder::PARAM_INT))
				->set('encrypted_metadata', $update->createNamedParameter($this->seal($metadata)))
				->where($update->expr()->eq('user_id', $update->createNamedParameter($owner)))
				->andWhere($update->expr()->eq('chat_id', $update->createNamedParameter($chatId)))->executeStatement();
			$this->db->commit();
			return ['ok' => true, 'rev' => $rev, 'messageIndex' => $messageIndex,
				'targetText' => $newText ?? (string)($message['text'] ?? '')];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @return list<array{id:string,created:int,updated:int,count:int,metadata:array}> */
	public function metadataPage(string $userId, int $limit = 50, ?int $beforeUpdated = null, ?string $beforeChatId = null, bool $emptyOnly = false): array {
		$limit = min(101, max(1, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('chat_id', 'created_at', 'updated_at', 'message_count', 'encrypted_metadata')->from('eva_ai_chats')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))));
		if ($emptyOnly) {
			$qb->andWhere($qb->expr()->eq('message_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		if ($beforeUpdated !== null && $beforeChatId !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->lt('updated_at', $qb->createNamedParameter($beforeUpdated, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq('updated_at', $qb->createNamedParameter($beforeUpdated, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lt('chat_id', $qb->createNamedParameter($beforeChatId))
				)
			));
		}
		$qb->orderBy('updated_at', 'DESC')->addOrderBy('chat_id', 'DESC')->setMaxResults($limit);
		$result = $qb->executeQuery();
		try {
			$out = [];
			while (($row = $result->fetch()) !== false) {
				$out[] = ['id' => (string)$row['chat_id'], 'created' => (int)$row['created_at'], 'updated' => (int)$row['updated_at'],
					'count' => (int)$row['message_count'], 'metadata' => $this->open((string)$row['encrypted_metadata'])];
			}
			return $out;
		} finally { $result->closeCursor(); }
	}

	/** @return list<array<string,mixed>> */
	public function allChats(string $userId): array {
		$owner = hash('sha256', $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.chat_id', 'c.created_at', 'c.updated_at', 'c.message_count', 'c.encrypted_metadata', 'm.message_index', 'm.encrypted_message')
			->from('eva_ai_chats', 'c')
			->leftJoin('c', 'eva_ai_chat_messages', 'm', $qb->expr()->andX(
				$qb->expr()->eq('c.user_id', 'm.user_id'), $qb->expr()->eq('c.chat_id', 'm.chat_id')))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($owner)))
			->orderBy('c.updated_at', 'DESC')->addOrderBy('c.chat_id', 'DESC')->addOrderBy('m.message_index', 'ASC');
		$result = $qb->executeQuery();
		try {
			$chats = [];
			$expected = [];
			while (($row = $result->fetch()) !== false) {
				$id = (string)$row['chat_id'];
				if (!isset($chats[$id])) {
					$chat = $this->open((string)$row['encrypted_metadata']);
					$chat['created'] = (int)$row['created_at'];
					$chat['updated'] = (int)$row['updated_at'];
					$chat['messages'] = [];
					$chats[$id] = $chat;
					$expected[$id] = (int)$row['message_count'];
				}
				if ($row['encrypted_message'] !== null) $chats[$id]['messages'][] = $this->open((string)$row['encrypted_message']);
			}
			foreach ($chats as $id => $chat) {
				if (count($chat['messages']) !== $expected[$id]) throw new \RuntimeException('Stored chat message count is inconsistent.');
			}
			return array_values($chats);
		} finally { $result->closeCursor(); }
	}

	public function sqlReadsEnabled(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state', 'sql_read_enabled')->from('eva_ai_chat_migrations')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))));
		$result = $qb->executeQuery();
		try { $row = $result->fetch(); } finally { $result->closeCursor(); }
		return $row !== false && $row['state'] === 'complete' && (int)$row['sql_read_enabled'] === 1;
	}

	public function chatCount(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'chat_count')->from('eva_ai_chats')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))));
		$result = $qb->executeQuery();
		try { return (int)$result->fetchOne(); } finally { $result->closeCursor(); }
	}

	public function setSqlReadsEnabled(string $userId, bool $enabled): void {
		$owner = hash('sha256', $userId);
		if ($enabled) {
			$state = $this->migrationState($userId);
			if (($state['state'] ?? '') !== 'complete') throw new \RuntimeException('Chat SQL import must be complete before enabling SQL reads.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('eva_ai_chat_migrations')->set('sql_read_enabled', $qb->createNamedParameter($enabled ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))->executeStatement();
	}

	public function deleteChat(string $userId, string $chatId): bool {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			$this->deleteMessagesFrom($owner, $chatId, 0);
			$qb = $this->db->getQueryBuilder();
			$qb->delete('eva_ai_chats')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
				->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)));
			$deleted = $qb->executeStatement() > 0;
			$this->db->commit();
			return $deleted;
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}

	public function deleteOlderThan(string $userId, int $cutoff): int {
		$owner = hash('sha256', $userId);
		$deleted = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('chat_id')->from('eva_ai_chats')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
				->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
				->setMaxResults(100);
			$result = $qb->executeQuery();
			try { $ids = array_map(static fn(array $row): string => (string)$row['chat_id'], $result->fetchAll()); }
			finally { $result->closeCursor(); }
			foreach ($ids as $id) if ($this->deleteChat($userId, $id)) $deleted++;
		} while (count($ids) === 100);
		return $deleted;
	}

	/** Clear only imported chat rows, retaining the resumable migration marker. */
	public function resetImportedChats(string $userId): void {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			foreach (['eva_ai_chat_messages', 'eva_ai_chats'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}

	/** Account erasure is owner-scoped and also removes an interrupted migration checkpoint. */
	public function deleteUserData(string $userId): void {
		$owner = hash('sha256', $userId);
		$this->db->beginTransaction();
		try {
			foreach (['eva_ai_chat_messages', 'eva_ai_chats', 'eva_ai_chat_migrations'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}

	public function migrationState(string $userId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('eva_ai_chat_migrations')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))));
		$result = $qb->executeQuery();
		try { $row = $result->fetch(); } finally { $result->closeCursor(); }
		return $row === false ? null : $row;
	}

	public function saveMigrationState(string $userId, string $state, ?string $cursor, ?string $sourceHash, ?string $errorCode = null): void {
		if (!in_array($state, ['pending', 'running', 'complete', 'failed'], true)) throw new \InvalidArgumentException('Invalid chat migration state.');
		$owner = hash('sha256', $userId);
		$this->db->insertIgnoreConflict('eva_ai_chat_migrations', ['user_id' => $owner, 'state' => $state,
			'cursor' => $cursor, 'source_hash' => $sourceHash, 'updated_at' => time(), 'error_code' => $errorCode]);
		$qb = $this->db->getQueryBuilder();
		$qb->update('eva_ai_chat_migrations')->set('state', $qb->createNamedParameter($state))
			->set('cursor', $qb->createNamedParameter($cursor))->set('source_hash', $qb->createNamedParameter($sourceHash))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->set('error_code', $qb->createNamedParameter($errorCode))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))->executeStatement();
	}

	/** @return list<array> */
	public function messages(string $userId, string $chatId, int $offset = 0, int $limit = 1000): array {
		$offset = max(0, $offset);
		$limit = min(1000, max(1, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('encrypted_message')->from('eva_ai_chat_messages')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))))
			->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)))
			->orderBy('message_index', 'ASC')->setFirstResult($offset)->setMaxResults($limit);
		$result = $qb->executeQuery();
		try {
			$out = [];
			while (($row = $result->fetch()) !== false) {
				$out[] = $this->open((string)$row['encrypted_message']);
			}
			return $out;
		} finally {
			$result->closeCursor();
		}
	}

	public function deleteMessagesFrom(string $owner, string $chatId, int $index): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('eva_ai_chat_messages')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($chatId)))
			->andWhere($qb->expr()->gte('message_index', $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function upsertMetadata(string $owner, string $id, int $created, int $updated, int $count, string $encrypted): void {
		$this->db->insertIgnoreConflict('eva_ai_chats', [
			'user_id' => $owner, 'chat_id' => $id, 'created_at' => $created,
			'updated_at' => $updated, 'message_count' => $count, 'encrypted_metadata' => $encrypted,
		]);
		$qb = $this->db->getQueryBuilder();
		$qb->update('eva_ai_chats')->set('created_at', $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($updated, IQueryBuilder::PARAM_INT))
			->set('message_count', $qb->createNamedParameter($count, IQueryBuilder::PARAM_INT))
			->set('encrypted_metadata', $qb->createNamedParameter($encrypted))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	private function insertMessage(string $owner, string $chatId, int $index, int $created, string $encrypted): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('eva_ai_chat_messages')->values([
			'user_id' => $qb->createNamedParameter($owner),
			'chat_id' => $qb->createNamedParameter($chatId),
			'message_index' => $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT),
			'encrypted_message' => $qb->createNamedParameter($encrypted),
		]);
		$qb->executeStatement();
	}

	private function seal(array $value): string {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		return self::PREFIX . $this->crypto->encrypt($json);
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

	private function open(string $value): array {
		if (!str_starts_with($value, self::PREFIX)) {
			throw new \RuntimeException('Stored chat payload has an unsupported encryption format.');
		}
		$json = $this->crypto->decrypt(substr($value, strlen(self::PREFIX)));
		$decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new \RuntimeException('Stored chat payload is invalid.');
		}
		return $decoded;
	}
}
