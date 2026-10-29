<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Uses Nextcloud's shared locking cache first and portable SQL during outages. */
final class RequestRateLimiter {
	public function __construct(
		private ICacheFactory $cacheFactory,
		private IDBConnection $db,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Increment a subject's fixed-window counter.
	 *
	 * Returns null only when both the shared cache and SQL fallback fail. Raw
	 * subjects are never persisted; only an instance-keyed HMAC of the namespace,
	 * bucket and caller identifier is stored.
	 */
	public function consume(string $subject, string $bucket, int $windowSeconds, string $cachePrefix): ?int {
		if ($subject === '' || preg_match('/^[a-z0-9_-]{1,48}$/D', $bucket) !== 1
			|| $windowSeconds < 1 || $windowSeconds > 86400
			|| preg_match('/^[a-z0-9_-]{1,64}$/D', $cachePrefix) !== 1) {
			throw new \InvalidArgumentException('Invalid rate-limit counter identity.');
		}
		$window = intdiv(time(), $windowSeconds);
		$key = hash('sha256', $cachePrefix . "\0" . $bucket . "\0" . $subject) . ':' . $window;
		try {
			$cache = $this->cacheFactory->createLocking($cachePrefix);
			$cache->add($key, 0, $windowSeconds + 1);
			$count = $cache->inc($key);
			if (is_int($count)) return $count;
			throw new \RuntimeException('The shared rate-limit cache returned no counter.');
		} catch (\Throwable $e) {
			try {
				$this->logger->warning('eva_ai: shared rate-limit cache unavailable; using SQL fallback', [
					'exception' => $e::class,
				]);
			} catch (\Throwable) {
				// Logging must not prevent the SQL fallback.
			}
		}

		try {
			$now = time();
			$instanceSecret = $this->config->getSystemValue('secret', '');
			if (!is_string($instanceSecret) || strlen($instanceSecret) < 32) {
				throw new \RuntimeException('The instance secret is unavailable for rate-limit keying.');
			}
			$rateKey = hash_hmac('sha256', $cachePrefix . "\0" . $bucket . "\0" . $subject, $instanceSecret);
			$expiresAt = ($window + 1) * $windowSeconds + 60;
			$this->db->insertIgnoreConflict('eva_ai_rate_limits', [
				'rate_key' => $rateKey,
				'window_start' => $window,
				'request_count' => 0,
				'expires_at' => $expiresAt,
			]);
			$update = $this->db->getQueryBuilder();
			$update->update('eva_ai_rate_limits')
				->set('request_count', $update->createFunction('request_count + 1'))
				->where($update->expr()->eq('rate_key', $update->createNamedParameter($rateKey)))
				->andWhere($update->expr()->eq('window_start', $update->createNamedParameter($window, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$read = $this->db->getQueryBuilder();
			$read->select('request_count')->from('eva_ai_rate_limits')
				->where($read->expr()->eq('rate_key', $read->createNamedParameter($rateKey)))
				->andWhere($read->expr()->eq('window_start', $read->createNamedParameter($window, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$result = $read->executeQuery();
			try {
				$count = $result->fetchOne();
			} finally {
				$result->closeCursor();
			}
			if (!is_numeric($count) || (int)$count < 1) throw new \RuntimeException('The SQL rate-limit counter could not be read.');
			return (int)$count;
		} catch (\Throwable $e) {
			try {
				$this->logger->error('eva_ai: shared cache and SQL rate-limit fallback unavailable', [
					'exception' => $e::class,
					'error' => $e->getMessage(),
				]);
			} catch (\Throwable) {
				// Rate limiting failure policy is decided by the caller.
			}
			return null;
		}
	}

	/** Remove one bounded batch of expired fallback counters. */
	public function deleteExpired(int $now, int $limit = 200): int {
		$limit = max(1, min(200, $limit));
		$select = $this->db->getQueryBuilder();
		$select->select('rate_key', 'window_start')->from('eva_ai_rate_limits')
			->where($select->expr()->lt('expires_at', $select->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('expires_at', 'ASC')
			->setMaxResults($limit);
		$result = $select->executeQuery();
		try {
			$expired = $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
		if ($expired === []) return 0;

		$delete = $this->db->getQueryBuilder();
		$conditions = [];
		foreach ($expired as $row) {
			$conditions[] = $delete->expr()->andX(
				$delete->expr()->eq('rate_key', $delete->createNamedParameter((string)$row['rate_key'])),
				$delete->expr()->eq('window_start', $delete->createNamedParameter((int)$row['window_start'], IQueryBuilder::PARAM_INT)),
			);
		}
		$delete->delete('eva_ai_rate_limits')->where($delete->expr()->orX(...$conditions));
		return $delete->executeStatement();
	}
}
