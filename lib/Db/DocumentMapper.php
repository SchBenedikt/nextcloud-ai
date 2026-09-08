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

    /**
     * Distinct user IDs that own at least one indexed document.
     * Used by the background job for independent per-user indexing (Issue #7).
     * @return string[]
     */
    public function distinctUserIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('eva_ai_documents');
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid !== '') {
                $ids[] = $uid;
            }
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * File IDs of indexed mail documents (negative ids) for a user.
     * Mail reconciliation removes entries whose message no longer exists (Issue #15).
     * @return int[]
     */
    public function mailFileIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->lt('file_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['file_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    public function deleteByUserAndFile(string $userId, int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function countForUser(string $userId, ?string $search = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->like('path', $qb->createNamedParameter('%' . $search . '%'))
            );
        }
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * Shared WHERE clause for the document list and its aggregates so the
     * summary never depends on the requested page (Issue #74) and every filter
     * applies to both (Issue #88).
     *
     * Supported filters (all validated/bounded in the controller):
     *  - type: MIME group ("text", "application") or full MIME type
     *  - folder: relative folder path prefix ("Documents/Notes")
     *  - dateFrom/dateTo: indexed_at unix timestamps (inclusive)
     *  - sizeMin/sizeMax: file size in bytes (inclusive)
     */
    private function applyFilters(IQueryBuilder $qb, string $userId, ?string $search, array $filters): void {
        $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->like('path', $qb->createNamedParameter('%' . $search . '%'))
            );
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $type = $filters['type'];
            if (str_contains($type, '/')) {
                // Exact MIME type (e.g. application/pdf).
                $qb->andWhere($qb->expr()->eq('mime', $qb->createNamedParameter($type)));
            } else {
                // MIME group (e.g. text/* or application/*).
                $qb->andWhere($qb->expr()->like('mime', $qb->createNamedParameter($type . '/%')));
            }
        }
        if (isset($filters['folder']) && $filters['folder'] !== '') {
            $folder = trim($filters['folder'], '/');
            $qb->andWhere(
                $qb->expr()->like('path', $qb->createNamedParameter($this->escapeLike($folder) . '/%'))
            );
        }
        if (isset($filters['dateFrom'])) {
            $qb->andWhere(
                $qb->expr()->gte('indexed_at', $qb->createNamedParameter((int)$filters['dateFrom'], IQueryBuilder::PARAM_INT))
            );
        }
        if (isset($filters['dateTo'])) {
            $qb->andWhere(
                $qb->expr()->lte('indexed_at', $qb->createNamedParameter((int)$filters['dateTo'], IQueryBuilder::PARAM_INT))
            );
        }
        if (isset($filters['sizeMin'])) {
            $qb->andWhere(
                $qb->expr()->gte('size', $qb->createNamedParameter((int)$filters['sizeMin'], IQueryBuilder::PARAM_INT))
            );
        }
        if (isset($filters['sizeMax'])) {
            $qb->andWhere(
                $qb->expr()->lte('size', $qb->createNamedParameter((int)$filters['sizeMax'], IQueryBuilder::PARAM_INT))
            );
        }
    }

    /**
     * Validate a sort key and direction; unknown values fall back to the
     * default so the API can never be used to inject SQL.
     *
     * @return array{0:string,1:string} [column, direction]
     */
    private function resolveSort(?string $sort, string $dir): array {
        $columns = [
            'name' => 'name',
            'date' => 'indexed_at',
            'size' => 'size',
            'chunks' => 'chunk_count',
            'path' => 'path',
        ];
        $column = $columns[$sort ?? ''] ?? 'indexed_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return [$column, $direction];
    }

    private function escapeLike(string $value): string {
        return addcslashes($value, '%_\\');
    }

    /**
     * Full-index aggregates for the documents list, applying the same search
     * filter as {@see findByUser()} and {@see countForUser()} so the summary
     * never depends on the requested page (Issue #74).
     *
     * @return array{count:int,chunks:int,size:int}
     */
    public function aggregateForUser(string $userId, ?string $search = null, array $filters = []): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'doc_count')
            ->selectAlias($qb->createFunction('COALESCE(SUM(chunk_count), 0)'), 'chunk_sum')
            ->selectAlias($qb->createFunction('COALESCE(SUM(size), 0)'), 'size_sum')
            ->from('eva_ai_documents');
        $this->applyFilters($qb, $userId, $search, $filters);
        $row = $qb->executeQuery()->fetch();
        if ($row === false || $row === null) {
            return ['count' => 0, 'chunks' => 0, 'size' => 0];
        }
        return [
            'count' => (int)($row['doc_count'] ?? 0),
            'chunks' => (int)($row['chunk_sum'] ?? 0),
            'size' => (int)($row['size_sum'] ?? 0),
        ];
    }

    public function findByUser(string $userId, ?string $search = null, ?int $limit = 100, ?int $offset = 0, array $filters = [], ?string $sort = null, string $dir = 'DESC'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('eva_ai_documents');
        $this->applyFilters($qb, $userId, $search, $filters);
        [$column, $direction] = $this->resolveSort($sort, $dir);
        $qb->orderBy($column, $direction)
            ->addOrderBy('id', 'DESC')
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

    /**
     * Clear content hashes for a user to force re-indexing
     * Used when configuration changes that affect embeddings/chunking
     */
    public function clearHashesForUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_documents')
            ->set('content_hash', $qb->createNamedParameter(''))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
