<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/** Provider completion quantiles from a bounded sample with fixed labels. */
final class ProviderCompletionDuration implements IMetricFamily {
	private const WINDOW_SECONDS = 604800;
	private const SAMPLE_LIMIT_PER_PROVIDER = 5000;
	private const QUANTILES = ['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99];

	public function __construct(private IDBConnection $db) {}

	public function name(): string { return 'eva_ai_provider_completion_duration_ms'; }
	public function type(): MetricType { return MetricType::gauge; }
	public function unit(): string { return 'milliseconds'; }
	public function help(): string { return 'Approximate successful EVA provider completion duration quantiles by provider type'; }

	public function metrics(): Generator {
		$since = time() - self::WINDOW_SECONDS;
		$providers = [
			'ollama' => ['ollama'],
			'groq' => ['groq'],
			'openai_compatible' => null,
		];

		foreach ($providers as $providerType => $providerIds) {
			$durations = [];
			$q = $this->db->getQueryBuilder();
			$q->select('duration_ms')->from('eva_ai_usage')
				->where($q->expr()->eq('operation', $q->createNamedParameter('chat')))
				->andWhere($q->expr()->gte('created_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($q->expr()->gt('duration_ms', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			if ($providerIds !== null) {
				$q->andWhere($q->expr()->in('provider', $q->createNamedParameter($providerIds, IQueryBuilder::PARAM_STR_ARRAY)));
			} else {
				$q->andWhere($q->expr()->neq('provider', $q->createNamedParameter('ollama')))
					->andWhere($q->expr()->neq('provider', $q->createNamedParameter('groq')));
			}
			$q->orderBy('created_at', 'DESC')->setMaxResults(self::SAMPLE_LIMIT_PER_PROVIDER);
			try {
				$result = $q->executeQuery();
				try {
					while (($row = $result->fetch()) !== false) {
						$durations[] = max(0, (int)$row['duration_ms']);
					}
				} finally { $result->closeCursor(); }
			} catch (\Throwable) {
				// Keep metrics scrapes available during a migration or schema mismatch.
				continue;
			}
			if ($durations === []) continue;

			sort($durations, SORT_NUMERIC);
			$count = count($durations);
			foreach (self::QUANTILES as $label => $quantile) {
				$index = max(0, (int)ceil($quantile * $count) - 1);
				yield new Metric($durations[$index], ['provider' => $providerType, 'quantile' => $label]);
			}
		}
	}
}
