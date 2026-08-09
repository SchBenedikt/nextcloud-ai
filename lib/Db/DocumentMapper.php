<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DocumentMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'eva_ai_documents');
    }

    public function findByUserAndFile(string $userId, int $fileId): ?Document {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    public function hashesForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'content_hash')
            ->from('eva_ai_documents')
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
        $qb->delete('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function countForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    public function findByUser(string $userId, ?string $search = null, ?int $limit = 100, ?int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('eva_ai_documents')
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
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Liefert alle Document-Eintraege (id, file_id, name, path) des Users fuer die
     * uebergebenen File-IDs. Wird fuer Datei-Kontext-Chats verwendet ("Mit diesen
     * Dateien chatten"). Liefert nur Dokumente, die dem User gehoeren.
     *
     * @param int[] $fileIds
     * @return list<Document>
     */
    public function findByUserAndFileIds(string $userId, array $fileIds): array {
        if ($fileIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $fileIds)));
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    public function findFileIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')->from('eva_ai_documents');
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
            ->from('eva_ai_documents')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    /** @param int[] $ids */
    public function deleteByIds(array $ids): void {
        if (empty($ids)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_documents')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }

    public function deleteByUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $qb->executeStatement();
    }

    public function deleteAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_documents');
        return $qb->executeStatement();
    }
}