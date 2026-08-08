<?php

declare(strict_types=1);

namespace OCA\RagChat\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DocumentMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'ragchat_documents');
    }

    public function findByUserAndFile(string $userId, int $fileId): ?Document {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    public function hashesForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'content_hash')
            ->from('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['file_id']] = $row['content_hash'];
        }
        $result->closeCursor();
        return $map;
    }

    public function deleteByUserAndFile(string $userId, int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function countForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    public function findByUser(string $userId, ?string $search = null, ?int $limit = 100, ?int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->like('path', $qb->createNamedParameter('%' . $search . '%'))
            );
        }
        $qb->orderBy('indexed_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    public function findById(int $id): ?Document {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('ragchat_documents')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    public function findFileIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')->from('ragchat_documents');
        if ($userId !== '') {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['file_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /** @param int[] $ids @return Document[] */
    public function findByIds(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('ragchat_documents')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    /** @param int[] $ids */
    public function deleteByIds(array $ids): void {
        if (empty($ids)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_documents')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }

    public function deleteByUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $qb->executeStatement();
    }

    public function deleteAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ragchat_documents');
        return $qb->executeStatement();
    }
}