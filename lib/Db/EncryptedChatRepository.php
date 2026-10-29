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

	/** Append a single encrypted message without rewriting prior message rows. */
	public function appendMessage(string $userId, string $chatId, int $index, array $message): void {
		if ($index < 0 || $chatId === '' || strlen($chatId) > 128) {
			throw new \InvalidArgumentException('Invalid chat message position.');
		}
		$this->insertMessage(hash('sha256', $userId), $chatId, $index, (int)($message['created'] ?? time()), $this->seal($message));
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

	/** @return list<array{created:int,updated:int,count:int,metadata:array}> */
	public function metadataPage(string $userId, int $limit = 50, ?int $beforeUpdated = null, ?string $beforeChatId = null): array {
		$limit = min(100, max(1, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'updated_at', 'message_count', 'encrypted_metadata')->from('eva_ai_chats')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(hash('sha256', $userId))));
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
				$out[] = ['created' => (int)$row['created_at'], 'updated' => (int)$row['updated_at'],
					'count' => (int)$row['message_count'], 'metadata' => $this->open((string)$row['encrypted_metadata'])];
			}
			return $out;
		} finally { $result->closeCursor(); }
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

	/** Account erasure is owner-scoped and also removes an interrupted migration checkpoint. */
	public function deleteUserData(string $userId): void {
		$owner = hash('sha256', $userId);
		foreach (['eva_ai_chat_messages', 'eva_ai_chats', 'eva_ai_chat_migrations'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))->executeStatement();
		}
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
