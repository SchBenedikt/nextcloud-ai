<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IDBConnection;
use OCP\ICacheFactory;

/** Hash-only user API keys and their per-key request budget. */
class ApiKeyService {
	public function __construct(private IDBConnection $db, private ICacheFactory $cacheFactory) {
	}

	/** @return array{key: string, record: array<string, mixed>} */
	public function create(string $userId, string $name, string $scope, ?int $expiresAt, array $ips): array {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 80) {
			throw new \InvalidArgumentException('Key name must contain 1 to 80 characters.');
		}
		if (!in_array($scope, ['read', 'write', 'admin'], true)) {
			throw new \InvalidArgumentException('Scope must be read, write or admin.');
		}
		if ($expiresAt !== null && ($expiresAt <= time() || $expiresAt > time() + 5 * 365 * 86400)) {
			throw new \InvalidArgumentException('Expiration must be in the future and within five years.');
		}
		$ips = array_values(array_unique(array_filter(array_map(static function (mixed $ip): string {
			if (!is_string($ip)) throw new \InvalidArgumentException('IP restrictions must be text IP addresses.');
			return trim($ip);
		}, $ips), static fn(string $ip): bool => $ip !== '')));
		foreach ($ips as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
				throw new \InvalidArgumentException('IP restrictions must contain exact IPv4 or IPv6 addresses.');
			}
		}
		$id = bin2hex(random_bytes(16));
		$secret = 'eva_sk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$now = time();
		$whitelist = $ips === [] ? null : json_encode($ips, JSON_THROW_ON_ERROR);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('eva_ai_api_keys')->values([
			'id' => $qb->createNamedParameter($id),
			'user_id' => $qb->createNamedParameter($userId),
			'name' => $qb->createNamedParameter($name),
			'key_hash' => $qb->createNamedParameter(hash('sha256', $secret)),
			'key_prefix' => $qb->createNamedParameter(substr($secret, 0, 15)),
			'scope' => $qb->createNamedParameter($scope),
			'created_at' => $qb->createNamedParameter($now),
			'expires_at' => $qb->createNamedParameter($expiresAt),
			'ip_whitelist' => $qb->createNamedParameter($whitelist),
			'last_used_at' => $qb->createNamedParameter(null),
			'call_count' => $qb->createNamedParameter(0),
		])->executeStatement();
		return ['key' => $secret, 'record' => $this->publicRecord([
			'id' => $id, 'name' => $name, 'key_prefix' => substr($secret, 0, 15), 'scope' => $scope,
			'created_at' => $now, 'expires_at' => $expiresAt, 'ip_whitelist' => $whitelist,
			'last_used_at' => null, 'call_count' => 0,
		])];
	}

	/** @return list<array<string, mixed>> */
	public function listForUser(string $userId): array {
		$rows = $this->db->getQueryBuilder()->select('*')->from('eva_ai_api_keys')->where('user_id = :uid')->setParameter('uid', $userId)->orderBy('created_at', 'DESC')->executeQuery()->fetchAll();
		return array_map(fn(array $row): array => $this->publicRecord($row), $rows);
	}

	public function revoke(string $userId, string $id): bool {
		$qb = $this->db->getQueryBuilder()->delete('eva_ai_api_keys')->where('user_id = :uid')->andWhere('id = :id')->setParameter('uid', $userId)->setParameter('id', $id);
		return $qb->executeStatement() > 0;
	}

	/** Validate token, scope, expiry, IP and request budget; increment usage only on success. */
	public function authenticate(string $secret, string $ip): ?array {
		if (!str_starts_with($secret, 'eva_sk_') || strlen($secret) > 100) return null;
		$row = $this->db->getQueryBuilder()->select('*')->from('eva_ai_api_keys')->where('key_hash = :hash')->setParameter('hash', hash('sha256', $secret))->executeQuery()->fetchAssociative();
		if (!is_array($row) || ($row['expires_at'] !== null && (int)$row['expires_at'] <= time())) return null;
		$allowedIps = json_decode((string)($row['ip_whitelist'] ?? ''), true);
		if (is_array($allowedIps) && $allowedIps !== [] && !in_array($ip, $allowedIps, true)) return null;
		$limits = ['read' => 100, 'write' => 30, 'admin' => 10];
		$limit = $limits[(string)$row['scope']] ?? 0;
		$cache = $this->cacheFactory->createLocking('eva_ai_key_rate');
		$window = intdiv(time(), 60);
		$rateKey = $row['id'] . ':' . $window;
		$cache->add($rateKey, 0, 61);
		$count = $cache->inc($rateKey);
		if (!is_int($count)) throw new \RuntimeException('API key rate limiter did not return a counter.');
		if ($count > $limit) throw new ApiKeyRateLimitException('Per-key request limit exceeded.');
		$this->db->getQueryBuilder()->update('eva_ai_api_keys')->set('last_used_at', ':now')->set('call_count', 'call_count + 1')->where('id = :id')->setParameter('now', time())->setParameter('id', $row['id'])->executeStatement();
		$row['last_used_at'] = time();
		$row['call_count'] = (int)$row['call_count'] + 1;
		return $row;
	}

	private function publicRecord(array $row): array {
		return [
			'id' => (string)$row['id'], 'name' => (string)$row['name'], 'key_prefix' => (string)$row['key_prefix'],
			'scope' => (string)$row['scope'], 'created_at' => (int)$row['created_at'],
			'expires_at' => $row['expires_at'] === null ? null : (int)$row['expires_at'],
			'ip_whitelist' => json_decode((string)($row['ip_whitelist'] ?? ''), true) ?: [],
			'last_used_at' => $row['last_used_at'] === null ? null : (int)$row['last_used_at'],
			'call_count' => (int)$row['call_count'],
		];
	}
}
