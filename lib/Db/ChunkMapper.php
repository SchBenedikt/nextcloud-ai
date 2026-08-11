<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ChunkMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'eva_ai_chunks');
    }

    public function deleteByDocument(int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** @param int[] $documentIds */
    public function deleteByDocumentIds(array $documentIds): void {
        if ($documentIds === []) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_chunks')
            ->where($qb->expr()->in('document_id', $qb->createNamedParameter($documentIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }

    public function deleteForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_chunks')
            ->where(
                $qb->expr()->in('document_id', $qb->createFunction(
                    'SELECT id FROM `*PREFIX*eva_ai_documents` WHERE user_id = ' . $qb->createNamedParameter($userId)
                ))
            );
        return $qb->executeStatement();
    }

    public function deleteAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_chunks');
        return $qb->executeStatement();
    }

    /**
     * All chunks for a user (used when the index fits the candidate pool).
     * @return array<int,array<string,mixed>>
     */
    public function chunksForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
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
    public function chunksForUserPage(string $userId, int $limit, int $offset): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Coarse lexical preselect for very large collections: only chunks whose
     * content contains at least one query token, up to $cap rows.
     * @param string[] $tokens
     * @return array<int,array<string,mixed>>
     */
    public function filterChunksByTokens(string $userId, array $tokens, int $cap): array {
        if ($tokens === []) {
            // Empty token list: a lexical filter has nothing to match against.
            // Return a bounded page instead of the full index (Issue #13).
            return $this->chunksForUserPage($userId, $cap, 0);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $like = $qb->expr()->orX();
        foreach (array_slice($tokens, 0, 12) as $tok) {
            $like->add($qb->expr()->like('c.content', $qb->createNamedParameter('%' . $tok . '%')));
        }
        $qb->andWhere($like);
        $qb->orderBy('c.document_id', 'ASC')
            ->setMaxResults($cap);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    public function countForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    public function countForDocument(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_chunks', 'c')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function findByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'chunk_index', 'content')
            ->from('eva_ai_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('chunk_index', 'ASC');
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
        $qb = $this->db->getQueryBuilder();
        $qb->select('document_id', 'chunk_index', 'content')
            ->from('eva_ai_chunks')
            ->where($qb->expr()->in('document_id', $qb->createNamedParameter($documentIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('chunk_index', 'ASC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }
}
