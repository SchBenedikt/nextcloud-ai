<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Instance-wide generation counts; owner IDs and model/endpoint data stay private. */
class VectorGenerations implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_vector_generations'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'generations'; }
	public function help(): string { return 'EVA vector generations by backend and lifecycle state'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('backend', 'status')->selectAlias($q->createFunction('COUNT(*)'), 'generations')
			->from('eva_ai_vec_generations')->groupBy('backend', 'status');
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
		$counts = [];
		foreach ($rows as $row) {
			$backend = (string)$row['backend'];
			$status = (string)$row['status'];
			if (!in_array($backend, ['sql', 'qdrant'], true) || !in_array($status, ['building', 'active', 'retired', 'failed'], true)) continue;
			$counts[$backend][$status] = (int)$row['generations'];
		}
		foreach (['sql', 'qdrant'] as $backend) {
			foreach (['building', 'active', 'retired', 'failed'] as $status) {
				yield new Metric($counts[$backend][$status] ?? 0, ['backend' => $backend, 'status' => $status]);
			}
		}
	}
}
