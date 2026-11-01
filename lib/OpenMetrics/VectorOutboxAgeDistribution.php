<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Aggregate outbox event ages without exposing tenants, generations or IDs. */
final class VectorOutboxAgeDistribution implements IMetricFamily {
	private const OPERATIONS = ['upsert', 'delete_chunk', 'delete_generation'];
	private const STATES = ['pending', 'processing', 'dead'];
	private const BUCKETS = ['under_1m', '1m_5m', '5m_1h', '1h_24h', 'over_24h'];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_vector_outbox_age_events'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'events'; }
	public function help(): string { return 'Vector outbox events by operation, state and queue-age bucket'; }

	public function metrics(): Generator {
		$now = time();
		$q = $this->db->getQueryBuilder();
		$bucket = 'CASE'
			. ' WHEN created_at >= ' . $q->createNamedParameter($now - 60, IQueryBuilder::PARAM_INT) . " THEN 'under_1m'"
			. ' WHEN created_at >= ' . $q->createNamedParameter($now - 300, IQueryBuilder::PARAM_INT) . " THEN '1m_5m'"
			. ' WHEN created_at >= ' . $q->createNamedParameter($now - 3600, IQueryBuilder::PARAM_INT) . " THEN '5m_1h'"
			. ' WHEN created_at >= ' . $q->createNamedParameter($now - 86400, IQueryBuilder::PARAM_INT) . " THEN '1h_24h'"
			. " ELSE 'over_24h' END";
		$q->select('operation', 'state')
			->selectAlias($q->createFunction($bucket), 'age_bucket')
			->selectAlias($q->createFunction('COUNT(*)'), 'events')
			->from('eva_ai_vec_outbox')
			->where($q->expr()->in('operation', $q->createNamedParameter(self::OPERATIONS, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($q->expr()->in('state', $q->createNamedParameter(self::STATES, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('operation', 'state')
			->addGroupBy($q->createFunction($bucket));
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }

		$counts = [];
		foreach ($rows as $row) {
			$operation = (string)$row['operation'];
			$state = (string)$row['state'];
			$ageBucket = (string)$row['age_bucket'];
			if (in_array($operation, self::OPERATIONS, true) && in_array($state, self::STATES, true) && in_array($ageBucket, self::BUCKETS, true)) {
				$counts[$operation][$state][$ageBucket] = max(0, (int)$row['events']);
			}
		}

		foreach (self::OPERATIONS as $operation) {
			foreach (self::STATES as $state) {
				foreach (self::BUCKETS as $ageBucket) {
					yield new Metric($counts[$operation][$state][$ageBucket] ?? 0, [
						'operation' => $operation,
						'state' => $state,
						'age_bucket' => $ageBucket,
					]);
				}
			}
		}
	}
}
