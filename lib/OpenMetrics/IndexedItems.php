<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Aggregate index totals only; never export account, file, path or content labels. */
class IndexedItems implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_indexed_items'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'items'; }
	public function help(): string { return 'Indexed EVA documents and chunks across the instance'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->selectAlias($q->createFunction('COUNT(*)'), 'documents')
			->selectAlias($q->createFunction('COALESCE(SUM(chunk_count), 0)'), 'chunks')
			->from('eva_ai_documents');
		$r = $q->executeQuery();
		try { $row = $r->fetch(); } finally { $r->closeCursor(); }
		if (!is_array($row)) return;
		yield new Metric((int)$row['documents'], ['kind' => 'documents']);
		yield new Metric((int)$row['chunks'], ['kind' => 'chunks']);
	}
}
