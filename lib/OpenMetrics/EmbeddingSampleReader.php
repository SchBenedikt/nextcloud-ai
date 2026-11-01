<?php

declare(strict_types=1);

namespace OCA\EvaAi\OpenMetrics;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Read only a fixed, bounded sample of privacy-safe embedding batch rows. */
final class EmbeddingSampleReader {
	public const WINDOW_SECONDS = 604800;
	public const LIMIT_PER_PROVIDER = 5000;
	public const PROVIDERS = ['ollama', 'openai_compatible'];

	/** @return list<array{operation:string,duration_ms:int,cache_hits:int,cache_misses:int}> */
	public static function read(IDBConnection $db, string $provider): array {
		if (!in_array($provider, self::PROVIDERS, true)) return [];
		$q = $db->getQueryBuilder();
		$q->select('operation', 'duration_ms', 'embedding_cache_hits', 'embedding_cache_misses')
			->from('eva_ai_usage')
			->where($q->expr()->gte('created_at', $q->createNamedParameter(time() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('provider', $q->createNamedParameter($provider)))
			->andWhere($q->expr()->in('operation', $q->createNamedParameter(['embedding', 'embedding_error'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults(self::LIMIT_PER_PROVIDER);
		$result = $q->executeQuery();
		$rows = [];
		try {
			while (($row = $result->fetch()) !== false) {
				$rows[] = [
					'operation' => (string)$row['operation'],
					'duration_ms' => max(0, (int)$row['duration_ms']),
					'cache_hits' => max(0, (int)$row['embedding_cache_hits']),
					'cache_misses' => max(0, (int)$row['embedding_cache_misses']),
				];
			}
		} finally {
			$result->closeCursor();
		}
		return $rows;
	}
}
