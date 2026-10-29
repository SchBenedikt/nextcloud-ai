<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Portable, exact-token postings for bounded SQL lexical candidate selection. */
final class LexicalTermRepository {
	public function __construct(private IDBConnection $db) {}

	/** @param list<array{id:int,documentId:int,content:string}> $chunks */
	private function indexChunks(array $chunks): int {
		if ($chunks === []) return 0;
		foreach (array_chunk($chunks, 500) as $batch) {
			$ids = [];
			foreach ($batch as $chunk) {
				if ($chunk['id'] < 1 || $chunk['documentId'] < 1) continue;
				$ids[] = $chunk['id'];
			}
			if ($ids === []) continue;

			$delete = $this->db->getQueryBuilder();
			$delete->delete('eva_ai_chunk_terms')->where($delete->expr()->in('chunk_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			$indexedIds = [];
			$unsupportedIds = [];
			$values = [];
			foreach ($batch as $chunk) {
				if ($chunk['id'] < 1 || $chunk['documentId'] < 1) continue;
				$frequencies = self::frequencies($chunk['content']);
				if ($frequencies === null) {
					$unsupportedIds[] = (int)$chunk['id'];
					continue;
				}
				$indexedIds[] = (int)$chunk['id'];
				foreach ($frequencies as $term => $frequency) {
					$values[] = [(int)$chunk['id'], (int)$chunk['documentId'], hash('sha256', $term), (int)$frequency];
				}
			}
			$this->insertPostings($values);
			foreach ([1 => $indexedIds, -1 => $unsupportedIds] as $state => $stateIds) {
				foreach (array_chunk($stateIds, 500) as $idBatch) {
					if ($idBatch === []) continue;
					$update = $this->db->getQueryBuilder();
					$update->update('eva_ai_chunks')->set('lexical_indexed', $update->createNamedParameter($state, IQueryBuilder::PARAM_INT))
						->where($update->expr()->in('id', $update->createNamedParameter($idBatch, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
				}
			}
		}
		return count($chunks);
	}

	/** Index at most 200 existing chunks in one bounded transaction. */
	public function backfill(int $limit = 200): int {
		$limit = max(1, min(200, $limit));
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'document_id')->from('eva_ai_chunks')
			->where($q->expr()->eq('lexical_indexed', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit);
		$r = $q->executeQuery();
		try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
		if ($rows === []) return 0;
		$ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);

		$this->db->beginTransaction();
		try {
			$claim = $this->db->getQueryBuilder();
			$claim->update('eva_ai_chunks')->set('lexical_indexed', $claim->createNamedParameter(2, IQueryBuilder::PARAM_INT))
				->where($claim->expr()->in('id', $claim->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($claim->expr()->eq('lexical_indexed', $claim->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			// Read after the claim locks each row, so a concurrent replacement is either
			// observed here or runs after commit and resets its row for a later tick.
			$current = $this->db->getQueryBuilder();
			$current->select('id', 'document_id', 'content')->from('eva_ai_chunks')
				->where($current->expr()->in('id', $current->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($current->expr()->eq('lexical_indexed', $current->createNamedParameter(2, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC');
			$read = $current->executeQuery();
			try { $currentRows = $read->fetchAll(); } finally { $read->closeCursor(); }
			$chunks = array_map(static fn(array $row): array => [
				'id' => (int)$row['id'], 'documentId' => (int)$row['document_id'], 'content' => (string)$row['content'],
			], $currentRows);
			$processed = $this->indexChunks($chunks);
			$this->db->commit();
			return $processed;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Remove derived terms before their source chunks/documents are deleted. */
	public function deleteDocuments(array $documentIds): void {
		$ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
		foreach (array_chunk($ids, 500) as $batch) {
			$q = $this->db->getQueryBuilder();
			$q->delete('eva_ai_chunk_terms')->where($q->expr()->in('document_id', $q->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
	}

	/** Remove terms for only the generation-tagged rows being retired. */
	public function deleteGenerationDocuments(array $documentIds, ?string $generationId = null): void {
		$documents = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
		if ($generationId !== null && preg_match('/^[a-f0-9]{32}$/D', $generationId) !== 1) throw new \InvalidArgumentException('Invalid generation ID.');
		foreach (array_chunk($documents, 500) as $documentBatch) {
			$cursor = 0;
			do {
				$q = $this->db->getQueryBuilder();
				$q->select('id')->from('eva_ai_chunks')
					->where($q->expr()->in('document_id', $q->createNamedParameter($documentBatch, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($q->expr()->isNotNull('vector_generation_id'))
					->andWhere($q->expr()->gt('id', $q->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
					->orderBy('id', 'ASC')->setMaxResults(500);
				if ($generationId !== null) $q->andWhere($q->expr()->eq('vector_generation_id', $q->createNamedParameter($generationId)));
				$r = $q->executeQuery();
				try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
				$ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
				if ($ids !== []) {
					$delete = $this->db->getQueryBuilder();
					$delete->delete('eva_ai_chunk_terms')->where($delete->expr()->in('chunk_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
					$cursor = $ids[count($ids) - 1];
				}
			} while (count($rows) === 500);
		}
	}

	/** @return array<string,int>|null */
	private static function frequencies(string $content): ?array {
		if (preg_match_all('/\p{L}\p{M}*+[\p{L}\p{M}\p{N}_]*+|\p{N}+/u', mb_strtolower($content), $matches) === false) return null;
		$frequencies = [];
		foreach ($matches[0] as $term) {
			if (mb_strlen($term) < 3) continue;
			$frequencies[$term] = ($frequencies[$term] ?? 0) + 1;
		}
		return $frequencies;
	}

	/** @param list<array{int,int,string,int}> $values */
	private function insertPostings(array $values): void {
		foreach (array_chunk($values, 200) as $batch) {
			$tuples = [];
			$params = [];
			foreach ($batch as [$chunkId, $documentId, $hash, $frequency]) {
				$tuples[] = '(?, ?, ?, ?)';
				array_push($params, $chunkId, $documentId, $hash, $frequency);
			}
			if ($tuples !== []) {
				$this->db->executeStatement('INSERT INTO *PREFIX*eva_ai_chunk_terms (chunk_id, document_id, term_hash, frequency) VALUES ' . implode(', ', $tuples), $params);
			}
		}
	}
}
