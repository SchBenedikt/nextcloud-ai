<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Oldest actionable queue age; never exports event IDs or error details. */
class VectorOutboxAge implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_vector_outbox_oldest_age_seconds'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'seconds'; }
	public function help(): string { return 'Age of the oldest actionable EVA vector outbox event'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('state')->selectAlias($q->createFunction('MIN(created_at)'), 'oldest')
			->from('eva_ai_vec_outbox')
			->where($q->expr()->in('state', $q->createNamedParameter(['pending', 'processing', 'dead'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('state');
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
		$ages = [];
		foreach ($rows as $row) {
			$state = (string)$row['state'];
			if (in_array($state, ['pending', 'processing', 'dead'], true) && $row['oldest'] !== null) {
				$ages[$state] = max(0, time() - (int)$row['oldest']);
			}
		}
		foreach (['pending', 'processing', 'dead'] as $state) {
			yield new Metric($ages[$state] ?? 0, ['state' => $state]);
		}
	}
}
