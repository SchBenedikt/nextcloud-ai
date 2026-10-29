<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** Encrypted, retryable account-erasure work; raw user IDs are never stored. */
final class AccountCleanupRepository {
	private const PREFIX = "EVA-DELETE-1\n";
	private const LEASE_SECONDS = 900;

	public function __construct(private IDBConnection $db, private ICrypto $crypto) {}

	public function enqueue(string $userId, int $now): string {
		if ($userId === '' || strlen($userId) > 4096) throw new \InvalidArgumentException('Invalid account cleanup owner.');
		$owner = self::ownerHash($userId);
		$encrypted = self::PREFIX . $this->crypto->encrypt($userId);
		if (strlen($encrypted) > 8192) throw new \InvalidArgumentException('Encrypted account cleanup owner is oversized.');
		$this->db->insertIgnoreConflict('eva_ai_account_cleanup', [
			'owner_hash' => $owner, 'encrypted_user' => $encrypted, 'attempts' => 0,
			'created_at' => $now, 'available_at' => $now, 'lease_token' => null, 'lease_until' => null,
			'last_error' => null, 'updated_at' => $now,
		]);
		return $owner;
	}

	public function claimForUser(string $userId, int $now): ?array {
		return $this->claim(self::ownerHash($userId), $now);
	}

	public function claimNext(int $now): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('owner_hash')->from('eva_ai_account_cleanup')
			->where($q->expr()->lte('available_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->orX($q->expr()->isNull('lease_until'), $q->expr()->lte('lease_until', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))))
			->orderBy('available_at', 'ASC')->setMaxResults(1);
		$r = $q->executeQuery();
		try { $row = $r->fetch(); } finally { $r->closeCursor(); }
		return $row ? $this->claim((string)$row['owner_hash'], $now) : null;
	}

	public function decryptOwner(array $claim): string {
		if (!is_string($claim['owner_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $claim['owner_hash']) !== 1
			|| !is_string($claim['encrypted_user'] ?? null) || !str_starts_with($claim['encrypted_user'], self::PREFIX)
			|| strlen($claim['encrypted_user']) > 8192) throw new \UnexpectedValueException('Invalid account cleanup record.');
		$userId = $this->crypto->decrypt(substr($claim['encrypted_user'], strlen(self::PREFIX)));
		if ($userId === '' || !hash_equals($claim['owner_hash'], self::ownerHash($userId))) {
			throw new \UnexpectedValueException('Account cleanup owner authentication failed.');
		}
		return $userId;
	}

	public function renew(array $claim, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('eva_ai_account_cleanup')->set('lease_until', $q->createNamedParameter($now + self::LEASE_SECONDS, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('owner_hash', $q->createNamedParameter($claim['owner_hash'])))
			->andWhere($q->expr()->eq('lease_token', $q->createNamedParameter($claim['lease_token'])))
			->andWhere($q->expr()->gt('lease_until', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	public function complete(array $claim): bool {
		$q = $this->db->getQueryBuilder();
		$q->delete('eva_ai_account_cleanup')->where($q->expr()->eq('owner_hash', $q->createNamedParameter($claim['owner_hash'])))
			->andWhere($q->expr()->eq('lease_token', $q->createNamedParameter($claim['lease_token'])))
			->andWhere($q->expr()->gt('lease_until', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	public function retry(array $claim, string $reason, int $now): bool {
		if (preg_match('/^[a-z0-9_]{1,48}$/D', $reason) !== 1) $reason = 'cleanup_failed';
		$attempts = max(1, (int)($claim['attempts'] ?? 1));
		$delay = min(86400, 30 * (2 ** min(11, $attempts - 1))) + random_int(0, 15);
		$q = $this->db->getQueryBuilder();
		$q->update('eva_ai_account_cleanup')->set('available_at', $q->createNamedParameter($now + $delay, IQueryBuilder::PARAM_INT))
			->set('lease_token', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('lease_until', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('last_error', $q->createNamedParameter($reason))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('owner_hash', $q->createNamedParameter($claim['owner_hash'])))
			->andWhere($q->expr()->eq('lease_token', $q->createNamedParameter($claim['lease_token'])))
			->andWhere($q->expr()->gt('lease_until', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @return array{pending:int,oldest:int} */
	public function summary(int $now): array {
		$q = $this->db->getQueryBuilder();
		$q->selectAlias($q->createFunction('COUNT(*)'), 'pending')
			->selectAlias($q->createFunction('MIN(created_at)'), 'oldest')->from('eva_ai_account_cleanup');
		$r = $q->executeQuery();
		try { $row = $r->fetch(); } finally { $r->closeCursor(); }
		$oldest = $row['oldest'] ?? null;
		return ['pending' => (int)($row['pending'] ?? 0), 'oldest' => $oldest === null ? 0 : max(0, $now - (int)$oldest)];
	}

	private function claim(string $ownerHash, int $now): ?array {
		if (preg_match('/^[a-f0-9]{64}$/D', $ownerHash) !== 1) throw new \InvalidArgumentException('Invalid account cleanup owner.');
		$token = bin2hex(random_bytes(16));
		$update = $this->db->getQueryBuilder();
		$update->update('eva_ai_account_cleanup')
			->set('lease_token', $update->createNamedParameter($token))
			->set('lease_until', $update->createNamedParameter($now + self::LEASE_SECONDS, IQueryBuilder::PARAM_INT))
			->set('attempts', $update->func()->add('attempts', $update->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('owner_hash', $update->createNamedParameter($ownerHash)))
			->andWhere($update->expr()->lte('available_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($update->expr()->orX($update->expr()->isNull('lease_until'), $update->expr()->lte('lease_until', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))));
		if ($update->executeStatement() !== 1) return null;
		$q = $this->db->getQueryBuilder();
		$q->select('owner_hash', 'encrypted_user', 'attempts', 'lease_token', 'lease_until')->from('eva_ai_account_cleanup')
			->where($q->expr()->eq('owner_hash', $q->createNamedParameter($ownerHash)))
			->andWhere($q->expr()->eq('lease_token', $q->createNamedParameter($token)));
		$r = $q->executeQuery();
		try { $row = $r->fetch(); } finally { $r->closeCursor(); }
		return $row ?: null;
	}

	/** Keep erasure queue identifiers unlinkable from vector-owner fence hashes. */
	private static function ownerHash(string $userId): string {
		return hash('sha256', "eva_ai:account-cleanup:\0" . $userId);
	}
}
