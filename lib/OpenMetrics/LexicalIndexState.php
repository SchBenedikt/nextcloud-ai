<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Instance-wide lexical posting progress; never exports tenant or source labels. */
final class LexicalIndexState implements IMetricFamily {
	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_lexical_index_chunks'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'chunks'; }
	public function help(): string { return 'EVA chunks by lexical posting index state'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('lexical_indexed')->selectAlias($q->createFunction('COUNT(*)'), 'chunks')
			->from('eva_ai_chunks')->groupBy('lexical_indexed');
		$result = $q->executeQuery();
		try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }

		$counts = [];
		foreach ($rows as $row) {
			$state = (int)$row['lexical_indexed'];
			if (in_array($state, [-1, 0, 1, 2], true)) $counts[$state] = (int)$row['chunks'];
		}
		foreach ([-1 => 'unsupported', 0 => 'pending', 1 => 'indexed', 2 => 'processing'] as $state => $label) {
			yield new Metric($counts[$state] ?? 0, ['state' => $label]);
		}
	}
}
