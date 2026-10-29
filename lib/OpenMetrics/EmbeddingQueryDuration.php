<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Approximate query-embedding stage duration by fixed provider type. */
final class EmbeddingQueryDuration implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT = 5000;
	private const PROVIDERS = ['ollama', 'openai_compatible'];
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_embedding_query_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate seven-day query-embedding stage quantiles by fixed provider type'; }

	public function metrics(): Generator {
		$q = $this->db->getQueryBuilder();
		$q->select('retrieval_embedding_provider', 'retrieval_stage_mask', 'retrieval_embedding_ms')->from('eva_ai_usage')
			->where($q->expr()->eq('operation', $q->createNamedParameter('chat')))
			->andWhere($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT);
		$sampled = [];
		try {
			$result = $q->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$provider = (string)$row['retrieval_embedding_provider'];
					if (!in_array($provider, self::PROVIDERS, true) || (((int)$row['retrieval_stage_mask'] & (1 << 1)) === 0)) continue;
					$sampled[$provider][] = max(0, (int)$row['retrieval_embedding_ms']);
				}
			} finally { $result->closeCursor(); }
		} catch (\Throwable) {
			// A scrape must remain available on older schemas or during upgrades.
			return;
		}

		foreach ($sampled as $provider => $values) {
			sort($values, SORT_NUMERIC);
			$count = count($values);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($values[$index], ['provider' => $provider, 'quantile' => $label]);
			}
		}
	}
}
