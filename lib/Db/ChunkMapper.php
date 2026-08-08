<?php

declare(strict_types=1);

namespace OCA\RagChat\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ChunkMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'ragchat_chunks');
    }

    public function deleteByDocument(int $documentId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteByDocumentIds(array $documentIds): void {
        if (empty($documentIds)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_chunks')
            ->where($qb->expr()->in('document_id', $qb->createNamedParameter($documentIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }

    /**
     * Fetch all candidate chunks (id, document_id, chunk_index, content, embedding)
     * for a user, joined against the documents table.
     */
    public function chunksForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding')
            ->from('ragchat_chunks', 'c')
            ->innerJoin('c', 'ragchat_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * Coarse lexical preselect for very large collections: only chunks whose
     * content contains at least one query token, up to $cap rows.
     * @param string[] $tokens
     */
    public function filterChunksByTokens(string $userId, array $tokens, int $cap): array {
        if (empty($tokens)) {
            return $this->chunksForUser($userId);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding')
            ->from('ragchat_chunks', 'c')
            ->innerJoin('c', 'ragchat_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
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
            ->from('ragchat_chunks', 'c')
            ->innerJoin('c', 'ragchat_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    public function countForDocument(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('ragchat_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * @return array<int,array{chunk_index:int,content:string}>
     */
    public function findByDocument(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'chunk_index', 'content')
            ->from('ragchat_chunks')
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('chunk_index', 'ASC')
            ->setMaxResults(200);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }
}