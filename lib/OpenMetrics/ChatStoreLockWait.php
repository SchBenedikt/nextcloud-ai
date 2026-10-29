<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Bounded quantiles for chat-storage lock waits that actually encountered contention. */
final class ChatStoreLockWait implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const MODES = ['shared', 'exclusive'];
	private const OUTCOMES = ['acquired', 'degraded_read', 'busy', 'read_failed'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_chat_store_lock_wait_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate seven-day quantiles for contended EVA chat-store lock waits'; }

	public function metrics(): Generator {
		foreach (self::MODES as $mode) foreach (self::OUTCOMES as $outcome) {
			$q = $this->db->getQueryBuilder();
			$q->select('duration_ms')->from('eva_ai_usage')
				->where($q->expr()->eq('operation', $q->createNamedParameter('chat_lock_wait')))
				->andWhere($q->expr()->eq('model', $q->createNamedParameter($mode . ':' . $outcome)))
				->andWhere($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
				->andWhere($q->expr()->gt('duration_ms', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
			try {
				$result = $q->executeQuery();
				$values = [];
				try {
					while (($row = $result->fetch()) !== false) $values[] = max(0, (int)$row['duration_ms']);
				} finally { $result->closeCursor(); }
			} catch (\Throwable) {
				// A metrics scrape must not affect chat requests when telemetry is unavailable.
				continue;
			}
			if ($values === []) continue;
			sort($values, SORT_NUMERIC);
			$count = count($values);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($values[$index], ['mode' => $mode, 'outcome' => $outcome, 'quantile' => $label]);
			}
		}
	}
}
