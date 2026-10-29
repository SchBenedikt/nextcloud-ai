<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DocumentMapper extends QBMapper {
    private VectorSourceDeletion $sourceDeletion;

    public function __construct(IDBConnection $db, ?VectorSourceDeletion $sourceDeletion = null) {
        parent::__construct($db, 'eva_ai_documents');
        $this->sourceDeletion = $sourceDeletion ?? new VectorSourceDeletion($db, new VectorOutboxRepository($db));
    }

    public function delete(Entity $entity): Entity {
        $this->deleteByIds([(int)$entity->getId()]);
        return $entity;
    }

    public function insert(Entity $entity): Entity {
        if(!$entity instanceof Document||!is_string($entity->getUserId())||$entity->getUserId()==='')throw new \InvalidArgumentException('Indexed documents require an owner.');
        return $this->sourceDeletion->insertDocument($entity->getUserId(),fn():Entity=>parent::insert($entity));
    }

    public function update(Entity $entity): Entity {
        $sourceFields = ['userId', 'fileId', 'path', 'name', 'source', 'contentHash', 'fileMtime', 'size', 'mime'];
        if (array_intersect(array_keys($entity->getUpdatedFields()), $sourceFields) === []) return parent::update($entity);
        if ($entity->getId() === null) throw new \InvalidArgumentException('Entity which should be updated has no id');
        return $this->sourceDeletion->mutateDocuments([(int)$entity->getId()], fn(): Entity => parent::update($entity));
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

    /**
     * Documents at or under a relative folder path (Issue #79). Used by the
     * incremental hook path for folder renames/deletes.
     *
     * @return list<Document>
     */
    public function findByUserAndPathPrefix(string $userId, string $path): array {
        $prefix = trim($path, '/');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(Document::SOURCE_FILES)));
        if ($prefix !== '') {
            $escaped = $this->escapeLike($prefix) . '/%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq('path', $qb->createNamedParameter($prefix)),
                    $qb->expr()->like('path', $qb->createNamedParameter($escaped))
                )
            );
        }
        $qb
            ->setMaxResults(10000);
        return $this->findEntities($qb);
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
    /**
     * Per-file index state for one user, keyed by file id.
     *
     * A full scan otherwise issues one findByUserAndFile() query *and* one
     * UPDATE per file just to discover that nothing changed - several thousand
     * round trips on a settled library before any embedding happens. One bulk
     * read replaces all of them, and the caller decides whether a metadata
     * refresh is actually needed.
     *
     * @return array<int,array{id:int,content_hash:string,size:int,file_mtime:int,path:string,name:string,mime:string}>
     */
    public function stateForUser(string $userId): array {
        $map = [];
        $offset = 0;
        $pageSize = 500;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'file_id', 'content_hash', 'size', 'file_mtime', 'path', 'name', 'mime')
                ->from('eva_ai_documents')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->orderBy('id', 'ASC')
                ->setMaxResults($pageSize)
                ->setFirstResult($offset);
            $result = $qb->executeQuery();
            $count = 0;
            while ($row = $result->fetch()) {
                $map[(int)$row['file_id']] = [
                    'id' => (int)$row['id'],
                    'content_hash' => (string)($row['content_hash'] ?? ''),
                    'size' => (int)($row['size'] ?? 0),
                    'file_mtime' => (int)($row['file_mtime'] ?? 0),
                    'path' => (string)($row['path'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'mime' => (string)($row['mime'] ?? ''),
                ];
                $count++;
            }
            $result->closeCursor();
            $offset += $count;
        } while ($count === $pageSize);
        return $map;
    }

    /** Lightweight state lookup used when a user's index is too large for a
     * process-local bulk map. Keeps large indexing passes memory-bounded. */
    public function stateForFile(string $userId, int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'file_id', 'content_hash', 'size', 'file_mtime', 'path', 'name', 'mime')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'content_hash' => (string)($row['content_hash'] ?? ''),
            'size' => (int)($row['size'] ?? 0),
            'file_mtime' => (int)($row['file_mtime'] ?? 0),
            'path' => (string)($row['path'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'mime' => (string)($row['mime'] ?? ''),
        ];
    }

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
     * File IDs of indexed non-file entries of one producer for a user.
     *
     * Reconciliation removes entries whose origin no longer exists (a deleted
     * mail message, a Talk room the user left). It must filter by `source`: the
     * mail and Talk indexes both use synthetic negative file ids, so a query on
     * "negative file id" alone would hand the mail reconciliation the Talk rooms
     * and delete them.
     *
     * @return int[]
     */
    public function fileIdsForSource(string $userId, string $source): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->lt('file_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['file_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    public function deleteByUserAndFile(string $userId, int $fileId): void {
        $this->deleteMatching($userId, $fileId);
    }

    public function countForUser(string $userId, ?string $search = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'c')
            ->from('eva_ai_documents')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->like('path', $qb->createNamedParameter('%' . $this->escapeLike($search) . '%'))
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
                $qb->expr()->like('path', $qb->createNamedParameter('%' . $this->escapeLike($search) . '%'))
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

    /**
     * Per-user aggregates for the admin overview (Issue #82). Returns one row
     * per user that owns at least one indexed document: document count, total
     * chunks (summed from the document rows) and the most recent indexed_at.
     *
     * @return array<int,array{user_id:string,documents:int,chunks:int,last_indexed_at:?int}>
     */
    public function aggregatePerUser(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'documents')
            ->selectAlias($qb->createFunction('COALESCE(SUM(chunk_count), 0)'), 'chunks')
            ->selectAlias($qb->createFunction('MAX(indexed_at)'), 'last_indexed_at')
            ->from('eva_ai_documents')
            ->groupBy('user_id')
            ->orderBy('user_id', 'ASC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        $out = [];
        foreach ($rows as $row) {
            $userId = (string)($row['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $out[] = [
                'user_id' => $userId,
                'documents' => (int)($row['documents'] ?? 0),
                'chunks' => (int)($row['chunks'] ?? 0),
                'last_indexed_at' => ($row['last_indexed_at'] !== null && $row['last_indexed_at'] !== false)
                    ? (int)$row['last_indexed_at']
                    : null,
            ];
        }
        return $out;
    }

    /** Compact instance-wide totals for native administration; no account rows are exposed. */
    public function aggregateInstance(): array {
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(DISTINCT user_id)'), 'account_count')
            ->selectAlias($q->createFunction('COUNT(*)'), 'document_count')
            ->selectAlias($q->createFunction('COALESCE(SUM(chunk_count), 0)'), 'chunk_count')
            ->from('eva_ai_documents');
        $r = $q->executeQuery();
        try { $row = $r->fetch(); } finally { $r->closeCursor(); }
        return [
            'accounts' => (int)($row['account_count'] ?? 0),
            'documents' => (int)($row['document_count'] ?? 0),
            'chunks' => (int)($row['chunk_count'] ?? 0),
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
        $out = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('eva_ai_documents')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
            $out = array_merge($out, $this->findEntities($qb));
        }
        return $out;
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
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('eva_ai_documents')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
            $out = array_merge($out, $this->findEntities($qb));
        }
        return $out;
    }

    /** @param int[] $ids */
    public function deleteByIds(array $ids): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        foreach (array_chunk($ids, 500) as $batch) $this->sourceDeletion->deleteDocuments($batch, true);
    }

    public function deleteByUser(string $userId): int {
        return $this->deleteMatching($userId);
    }

    public function deleteAll(): int {
        return $this->deleteMatching(null);
    }

    private function deleteMatching(?string $userId, ?int $fileId = null): int {
        $cursor = 0; $deleted = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('eva_ai_documents')
                ->where($qb->expr()->gt('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(500);
            if ($userId !== null) $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            if ($fileId !== null) $qb->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            if ($ids !== []) {
                $deleted += $this->sourceDeletion->deleteDocuments($ids, true)['documents'];
                $cursor = $ids[count($ids) - 1];
            }
        } while (count($rows) === 500);
        return $deleted;
    }

    /**
     * Clear content hashes for a user to force re-indexing
     * Used when configuration changes that affect embeddings/chunking
     */
    public function clearHashesForUser(string $userId): void {
        $cursor = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('eva_ai_documents')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->neq('content_hash', $qb->createNamedParameter('')))
                ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(500);
            $result = $qb->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            if ($ids !== []) {
                $this->sourceDeletion->mutateDocuments($ids, function () use ($ids, $userId): void {
                    $update = $this->db->getQueryBuilder();
                    $update->update('eva_ai_documents')->set('content_hash', $update->createNamedParameter(''))
                        ->where($update->expr()->in('id', $update->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                        ->andWhere($update->expr()->eq('user_id', $update->createNamedParameter($userId)))->executeStatement();
                });
                $cursor = $ids[count($ids) - 1];
            }
        } while (count($rows) === 500);
    }
}
