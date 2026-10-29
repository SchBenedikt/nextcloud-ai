<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Durable vector work queue depth with bounded, non-sensitive labels. */
class VectorOutbox implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_vector_outbox_events'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'events'; }
	public function help(): string { return 'EVA vector outbox events by operation and queue state'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('operation', 'state')->selectAlias($q->createFunction('COUNT(*)'), 'events')
			->from('eva_ai_vec_outbox')
			->where($q->expr()->in('state', $q->createNamedParameter(['pending', 'processing', 'dead'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('operation', 'state');
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
		$counts = [];
		foreach ($rows as $row) {
			$operation = (string)$row['operation'];
			$state = (string)$row['state'];
			if (!in_array($operation, ['upsert', 'delete_chunk', 'delete_generation'], true)
				|| !in_array($state, ['pending', 'processing', 'dead'], true)) continue;
			$counts[$operation][$state] = (int)$row['events'];
		}
		foreach (['upsert', 'delete_chunk', 'delete_generation'] as $operation) {
			foreach (['pending', 'processing', 'dead'] as $state) {
				yield new Metric($counts[$operation][$state] ?? 0, ['operation' => $operation, 'state' => $state]);
			}
		}
	}
}
