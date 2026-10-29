<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorNorm;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Bounded, resumable population of exact vector norms for existing chunks. */
final class VectorNormRepository {
	public function __construct(private IDBConnection $db) {}

	/** Populate at most 500 rows and return the number successfully updated. */
	public function backfill(int $limit = 500): int {
		$limit = max(1, min(500, $limit));
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'embedding')->from('eva_ai_chunks')
			->where($q->expr()->isNull('vector_norm'))
			->orderBy('id', 'ASC')->setMaxResults($limit);
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }

		$updated = 0;
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			if ($id < 1 || !is_string($row['embedding'] ?? null)) continue;
			$norm = VectorNorm::fromJson($row['embedding']);
			// A negative sentinel means the row was examined but invalid. Search
			// treats it as absent and retains its existing defensive validation.
			$value = $norm ?? -1.0;
			$write = $this->db->getQueryBuilder();
			$write->update('eva_ai_chunks')->set('vector_norm', $write->createNamedParameter((string)$value))
				->where($write->expr()->eq('id', $write->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($write->expr()->eq('embedding', $write->createNamedParameter($row['embedding'])))
				->andWhere($write->expr()->isNull('vector_norm'));
			$updated += $write->executeStatement();
		}
		return $updated;
	}
}
