<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ChunkMapper extends QBMapper {
    /** Bounded keyset export of one tenant's exact retrieval snapshot. */
    public function benchmarkRowsAfter(string $userId, int $afterId, int $limit = 250): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.content', 'c.embedding', 'c.provenance',
            'd.user_id', 'd.path', 'd.name', 'd.source', 'd.file_id')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('c.id', $qb->createNamedParameter(max(0, $afterId), IQueryBuilder::PARAM_INT)))
            ->orderBy('c.id', 'ASC')->setMaxResults(max(1, min(500, $limit)));
        $qb->andWhere($qb->expr()->isNull('c.vector_generation_id'));
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }

    private VectorSourceDeletion $sourceDeletion;

    public function __construct(IDBConnection $db, ?VectorSourceDeletion $sourceDeletion = null) {
        parent::__construct($db, 'eva_ai_chunks');
        $this->sourceDeletion = $sourceDeletion ?? new VectorSourceDeletion($db, new VectorOutboxRepository($db));
    }

    public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
        if(!$entity instanceof Chunk||!is_int($entity->getDocumentId())||$entity->getDocumentId()<1)throw new \InvalidArgumentException('Chunks require an indexed source document.');
        return $this->insertMany([$entity])[0];
    }

    /** Insert one document's bounded chunk batch while acquiring owner/source fences once. */
    public function insertMany(array $entities): array {
        if($entities===[])throw new \InvalidArgumentException('Chunk insert batch must not be empty.');
        $documentId=null;
        foreach($entities as$entity){
            if(!$entity instanceof Chunk||!is_int($entity->getDocumentId())||$entity->getDocumentId()<1
                ||($documentId!==null&&$documentId!==$entity->getDocumentId()))throw new \InvalidArgumentException('Chunk batch must belong to one indexed source document.');
            $documentId=$entity->getDocumentId();
        }
        $inserted=[];
        foreach(array_chunk($entities,500)as$batch){
            array_push($inserted, ...$this->sourceDeletion->insertChunks($documentId,
                fn(): array => array_map(fn(Chunk $chunk): \OCP\AppFramework\Db\Entity => parent::insert($chunk), $batch)));
        }
        return $inserted;
    }

    public function deleteByDocument(int $documentId): void {
        $this->sourceDeletion->deleteDocuments([$documentId]);
    }

    /** @param int[] $documentIds */
    public function deleteByDocumentIds(array $documentIds): void {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
        foreach (array_chunk($documentIds, 500) as $batch) $this->sourceDeletion->deleteDocuments($batch);
    }

    public function deleteForUser(string $userId): int {
        return $this->deleteOwnedChunks($userId);
    }

    public function deleteAll(): int {
        return $this->deleteOwnedChunks(null);
    }

    private function deleteOwnedChunks(?string $userId): int {
        $cursor = 0; $deleted = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('c.document_id')->from('eva_ai_chunks', 'c')
                ->where($qb->expr()->gt('c.document_id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                ->orderBy('c.document_id', 'ASC')->setMaxResults(500);
            if ($userId !== null) {
                $qb->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
                    ->andWhere($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
            }
            $result = $qb->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            $ids = array_map(static fn(array $row): int => (int)$row['document_id'], $rows);
            if ($ids !== []) {
                $deleted += $this->sourceDeletion->deleteDocuments($ids)['chunks'];
                $cursor = $ids[count($ids) - 1];
            }
        } while (count($rows) === 500);
        return $deleted;
    }

    /**
     * Chunks for a user up to the retrieval candidate pool.
     *
     * The caller uses this path only when the count already fits the pool;
     * keeping the SQL limit here as a defensive boundary prevents accidental
     * full-index materialisation when a new call site is added (Issue #394).
     * @return array<int,array<string,mixed>>
     */
    public function chunksForUser(string $userId, int $limit = 8000, ?string $generationId = null, string $generationState = 'ready'): array {
        $limit = max(1, $limit);
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit);
        $this->filterGeneration($qb,$generationId,$generationState);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Bounded, token-independent page of chunks for a user.
     *
     * Used as the *dense* candidate set on large indexes so semantic-only
     * matches are NOT gated by SQL lexical (LIKE) overlap (Issue #13).
     * A deterministic offset derived from the query hash keeps the sample
     * stable per query while still bounding memory/time.
     *
     * @return array<int,array<string,mixed>>
     */
    public function chunksForUserPage(string $userId, int $limit, int $offset, ?string $generationId = null, string $generationState = 'ready'): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $this->filterGeneration($qb,$generationId,$generationState);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /** Stable keyset page for large-index scans; inserts/deletes before the cursor cannot shift later pages. */
    public function chunksForUserAfter(string $userId, int $afterId, int $limit, ?string $generationId = null, string $generationState = 'ready'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('c.id', $qb->createNamedParameter(max(0, $afterId), IQueryBuilder::PARAM_INT)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(max(1, min(5000, $limit)));
        $this->filterGeneration($qb, $generationId, $generationState);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }

    /** Lightweight page for dense ranking: full chunk rows are hydrated only for top matches. */
    public function vectorsForUserAfter(string $userId, int $afterId, int $limit, ?string $generationId = null, string $generationState = 'ready'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.embedding', 'c.vector_norm')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('c.id', $qb->createNamedParameter(max(0, $afterId), IQueryBuilder::PARAM_INT)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(max(1, min(5000, $limit)));
        $this->filterGeneration($qb, $generationId, $generationState);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }

    /**
     * Coarse lexical preselect for very large collections: only chunks whose
     * content contains at least one query token, up to $cap rows.
     * @param string[] $tokens
     * @return array<int,array<string,mixed>>
     */
    public function filterChunksByTokens(string $userId, array $tokens, int $cap, ?string $source = null, ?string $generationId = null, string $generationState = 'ready'): array {
        $cap = max(1, min(8000, $cap));
        $tokens = array_values(array_unique($tokens));
        if ($tokens === []) return $this->chunksForUserPage($userId, $cap, 0, $generationId, $generationState);
        $candidateTokens = array_slice($tokens, 0, 12);
        $candidateHashes = array_map(static fn(string $token): string => hash('sha256', $token), $candidateTokens);
        $frequencyHashes = array_map(static fn(string $token): string => hash('sha256', $token), array_slice($tokens, 0, 64));
        if ($this->hasUnindexedChunks($userId, $source, $generationId, $generationState)) {
            return $this->legacyTokenCandidates($userId, $candidateTokens, $cap, $source, $generationId, $generationState);
        }

        // Select a bounded candidate ID set from postings without moving chunk bodies or embeddings.
        $indexed = $this->db->getQueryBuilder();
        $indexed->selectDistinct('c.id', 'c.document_id')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $indexed->expr()->eq('c.document_id', 'd.id'))
            ->innerJoin('c', 'eva_ai_chunk_terms', 't', $indexed->expr()->eq('t.chunk_id', 'c.id'))
            ->where($indexed->expr()->eq('d.user_id', $indexed->createNamedParameter($userId)))
            ->andWhere($indexed->expr()->in('t.term_hash', $indexed->createNamedParameter($candidateHashes, IQueryBuilder::PARAM_STR_ARRAY)));
        if ($source !== null && $source !== '') $indexed->andWhere($indexed->expr()->eq('d.source', $indexed->createNamedParameter($source)));
        $indexed->orderBy('c.document_id', 'ASC')->addOrderBy('c.id', 'ASC')->setMaxResults($cap);
        $this->filterGeneration($indexed, $generationId, $generationState);
        $result = $indexed->executeQuery();
        try { $postingRows = $result->fetchAll(); } finally { $result->closeCursor(); }
        $orderedIds = array_map(static fn(array $row): int => (int)$row['id'], $postingRows);
        $frequencies = array_fill_keys($orderedIds, []);
        foreach (array_chunk($orderedIds, 500) as $idBatch) {
            $terms = $this->db->getQueryBuilder();
            $terms->select('chunk_id', 'term_hash', 'frequency')->from('eva_ai_chunk_terms')
                ->where($terms->expr()->in('chunk_id', $terms->createNamedParameter($idBatch, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($terms->expr()->in('term_hash', $terms->createNamedParameter($frequencyHashes, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $terms->executeQuery();
            try { $termRows = $result->fetchAll(); } finally { $result->closeCursor(); }
            foreach ($termRows as $termRow) {
                $frequencies[(int)$termRow['chunk_id']][(string)$termRow['term_hash']] = (int)$termRow['frequency'];
            }
        }
        $indexedRows = [];
        foreach ($this->findChunksByIds($orderedIds, $generationId, $generationState) as $row) {
            $row['_eva_lexical_frequencies'] = $frequencies[(int)$row['id']] ?? [];
            $indexedRows[(int)$row['id']] = $row;
        }
        $rows = [];
        foreach ($orderedIds as $id) if (isset($indexedRows[$id])) $rows[] = $indexedRows[$id];
        return $rows;
    }

    private function hasUnindexedChunks(string $userId, ?string $source, ?string $generationId, string $generationState): bool {
        $q = $this->db->getQueryBuilder();
        $q->select('c.id')->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $q->expr()->eq('c.document_id', 'd.id'))
            ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($userId)))
            ->andWhere($q->expr()->neq('c.lexical_indexed', $q->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        if ($source !== null && $source !== '') $q->andWhere($q->expr()->eq('d.source', $q->createNamedParameter($source)));
        $this->filterGeneration($q, $generationId, $generationState);
        $result = $q->executeQuery();
        try { return $result->fetch() !== false; } finally { $result->closeCursor(); }
    }

    /** Keep the complete previous candidate behavior until every eligible row has postings. */
    private function legacyTokenCandidates(string $userId, array $tokens, int $cap, ?string $source, ?string $generationId, string $generationState): array {
        $q = $this->db->getQueryBuilder();
        $q->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $q->expr()->eq('c.document_id', 'd.id'))
            ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($userId)));
        if ($source !== null && $source !== '') $q->andWhere($q->expr()->eq('d.source', $q->createNamedParameter($source)));
        $like = $q->expr()->orX();
        foreach ($tokens as $token) $like->add($q->expr()->like('c.content', $q->createNamedParameter('%' . $this->escapeLike($token) . '%')));
        $q->andWhere($like)->orderBy('c.document_id', 'ASC')->setMaxResults($cap);
        $this->filterGeneration($q, $generationId, $generationState);
        $result = $q->executeQuery();
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }

    }

    /** Bounded exact-phrase candidate recovery for native remote hybrid search. */
    public function filterChunksByPhrases(string $userId, array $phrases, int $cap, ?string $generationId, string $generationState = 'delivered'): array {
        $phrases = array_slice(array_values(array_unique(array_filter($phrases, static fn($phrase): bool => is_string($phrase)
            && trim($phrase) !== '' && strlen($phrase) <= 800))), 0, 5);
        if ($phrases === []) return [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance', 'c.vector_revision')
            ->from('eva_ai_chunks', 'c')->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $matches = $qb->expr()->orX();
        foreach ($phrases as $phrase) {
            $matches->add($qb->expr()->like($qb->func()->lower('c.content'),
                $qb->createNamedParameter('%' . $this->escapeLike(mb_strtolower(trim($phrase))) . '%')));
        }
        $qb->andWhere($matches)->orderBy('c.id', 'ASC')->setMaxResults(max(1, min(100, $cap)));
        $this->filterGeneration($qb, $generationId, $generationState);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }

    private function escapeLike(string $value): string {
        return addcslashes($value, '%_\\');
    }

    private function filterGeneration(IQueryBuilder $qb, ?string $generationId, string $generationState = 'ready'): void {
        if ($generationId === null) {
            $qb->andWhere($qb->expr()->isNull('c.vector_generation_id'));
            return;
        }
        if (preg_match('/^[a-f0-9]{32}$/D',$generationId)!==1) throw new \InvalidArgumentException('Invalid vector generation filter.');
        if (!in_array($generationState,['ready','delivered'],true)) throw new \InvalidArgumentException('Invalid vector generation state.');
        $qb->andWhere($qb->expr()->eq('c.vector_generation_id',$qb->createNamedParameter($generationId)))
            ->andWhere($qb->expr()->eq('c.vector_state',$qb->createNamedParameter($generationState)));
    }

    /**
     * Full rows for a bounded set of chunk ids (used to materialise the dense
     * candidate set after the cosine scan, Issue #61). Batch sizes stay well
     * below DB parameter limits.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    public function findChunksByIds(array $ids, ?string $generationId = null, string $generationState = 'ready'): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunkIds) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.vector_norm', 'c.provenance')
                ->from('eva_ai_chunks', 'c')
                ->where($qb->expr()->in('c.id', $qb->createNamedParameter($chunkIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $this->filterGeneration($qb,$generationId,$generationState);
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            foreach ($rows as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function countForUser(string $userId, ?string $generationId = null, string $generationState = 'ready'): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $this->filterGeneration($qb,$generationId,$generationState);
        $result=$qb->executeQuery();
        try{$row=$result->fetch();}finally{$result->closeCursor();}
        return $row ? (int)$row['c'] : 0;
    }

    public function countForDocument(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_chunks', 'c')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $qb->andWhere($qb->expr()->isNull('c.vector_generation_id'));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     *
     * Bounded pagination: $limit/$offset keep huge documents (thousands of
     * chunks) from being transferred in a single response (Issue #91/#140).
     */
    public function findByDocument(int $documentId, ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'chunk_index', 'content', 'provenance')
            ->from('eva_ai_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('chunk_index', 'ASC');
        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null && $offset > 0) {
            $qb->setFirstResult($offset);
        }
        $qb->andWhere($qb->expr()->isNull('vector_generation_id'));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /** @param int[] $documentIds @return array<int,array<string,mixed>> */
    public function findByDocuments(array $documentIds): array {
        if ($documentIds === []) {
            return [];
        }
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($documentIds, 500) as $batch) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('document_id', 'chunk_index', 'content', 'provenance')
                ->from('eva_ai_chunks')
                ->where($qb->expr()->in('document_id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('chunk_index', 'ASC');
            $qb->andWhere($qb->expr()->isNull('vector_generation_id'));
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            $out = array_merge($out, $rows);
        }
        return $out;
    }
}
