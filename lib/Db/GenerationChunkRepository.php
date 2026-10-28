<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Dto\PreparedVectorChunk;
use OCA\EvaAi\Vector\VectorDocumentSnapshot;
use OCA\EvaAi\Vector\VectorGeneration;
use OCA\EvaAi\Vector\VectorStoreException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Isolated building rows and atomic intent. Performs no embedding or remote I/O. */
class GenerationChunkRepository {
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox,
        private VectorOwnerFenceRepository $owners) {}

    public function snapshot(string $userId, int $documentId): ?VectorDocumentSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'file_id', 'path', 'name', 'source', 'content_hash', 'file_mtime', 'size', 'mime')
            ->from('eva_ai_documents')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return $row ? VectorDocumentSnapshot::fromRow($row) : null;
    }

    /**
     * Embedding must finish before calling this short SQL transaction. Recheck
     * immutable generation and source metadata, preserving the entire legacy row.
     * @return array{id:int,revision:int,changed:bool}
     */
    public function putBuildingChunk(VectorGeneration $generation, string $target,
        VectorDocumentSnapshot $snapshot, PreparedVectorChunk $chunk): array {
        if ($snapshot->userId !== $generation->userId || preg_match('/^[a-f0-9]{64}$/D', $target) !== 1) throw new VectorStoreException('source_identity_mismatch');
        $chunk->validate($generation);
        $embedding = json_encode(array_map('floatval', $chunk->vector), JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $chunk->content);
        $provenance = json_encode($chunk->provenance + ['_eva_vector' => ['version' => 1,
            'document_stamp' => $snapshot->stamp, 'chunk_size' => $generation->chunkSize,
            'chunk_overlap' => $generation->chunkOverlap]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($embedding) > 524288 || strlen($provenance) > 4096) throw new VectorStoreException('chunk_too_large');

        return $this->outbox->transaction(function () use ($generation, $target, $snapshot, $chunk, $embedding, $hash, $provenance): array {
            $this->owners->lockOpen($generation->userId);
            $this->lockBuilding($generation, $target);
            // Lock source before checking the stamp. Normal metadata updates and
            // deletes serialize on this document row, including older cores.
            $lock = $this->db->getQueryBuilder();
            $lock->update('eva_ai_documents')->set('file_mtime', $lock->func()->add('file_mtime', $lock->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->where($lock->expr()->eq('id', $lock->createNamedParameter($snapshot->id, IQueryBuilder::PARAM_INT)))
                ->andWhere($lock->expr()->eq('user_id', $lock->createNamedParameter($generation->userId)))->executeStatement();
            $current = $this->snapshot($generation->userId, $snapshot->id);
            if ($current === null || !hash_equals($snapshot->stamp, $current->stamp)) throw new VectorStoreException('source_changed');

            $read = $this->db->getQueryBuilder();
            $read->select('*')->from('eva_ai_chunks')
                ->where($read->expr()->eq('vector_generation_id', $read->createNamedParameter($generation->id)))
                ->andWhere($read->expr()->eq('document_id', $read->createNamedParameter($snapshot->id, IQueryBuilder::PARAM_INT)))
                ->andWhere($read->expr()->eq('chunk_index', $read->createNamedParameter($chunk->index, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(2);
            $result = $read->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            if (count($rows) > 1) throw new VectorStoreException('duplicate_generation_chunk');
            $row = $rows[0] ?? null;
            if ($row !== null && $row['content_hash'] === $hash && $row['content'] === $chunk->content
                && $row['embedding'] === $embedding && $row['provenance'] === $provenance && (int)$row['token_count'] === $chunk->tokens) {
                $revision = (int)$row['vector_revision'];
                if ($revision < 1 || $revision > 9007199254740991) throw new VectorStoreException('invalid_revision');
                return ['id' => (int)$row['id'], 'revision' => $revision, 'changed' => false];
            }
            $values = ['document_id' => $snapshot->id, 'chunk_index' => $chunk->index, 'content' => $chunk->content,
                'embedding' => $embedding, 'provenance' => $provenance, 'token_count' => $chunk->tokens,
                'vector_generation_id' => $generation->id, 'vector_state' => 'pending', 'content_hash' => $hash];
            $write = $this->db->getQueryBuilder();
            if ($row === null) {
                $write->insert('eva_ai_chunks');
                foreach ($values as $key => $value) $write->setValue($key, $write->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
                $write->executeStatement();
                $id = $write->getLastInsertId();
                if ($id < 1) throw new VectorStoreException('invalid_point');
            } else {
                $id = (int)$row['id'];
                $write->update('eva_ai_chunks')->where($write->expr()->eq('id', $write->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                    ->andWhere($write->expr()->eq('vector_generation_id', $write->createNamedParameter($generation->id)));
                foreach ($values as $key => $value) $write->set($key, $write->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
                $write->executeStatement();
            }
            $revision = $this->outbox->enqueue($generation, $target, 'upsert', $snapshot->id, $id);
            if ($revision > 9007199254740991 || ($row !== null && $revision <= (int)$row['vector_revision'])) throw new VectorStoreException('invalid_revision');
            $version = $this->db->getQueryBuilder();
            $version->update('eva_ai_chunks')->set('vector_revision', $version->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
                ->where($version->expr()->eq('id', $version->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            return ['id' => $id, 'revision' => $revision, 'changed' => true];
        });
    }

    /** Owned and source-current payload for a later point worker; no HTTP here. */
    public function pointForDelivery(VectorGeneration $generation, int $id, int $revision): ?array {
        if ($id < 1 || $revision < 1 || $revision > 9007199254740991) throw new VectorStoreException('invalid_revision');
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.provenance', 'c.content_hash',
            'd.user_id', 'd.file_id', 'd.path', 'd.name', 'd.source', 'd.file_mtime', 'd.size', 'd.mime')
            ->selectAlias('d.content_hash', 'document_content_hash')->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
            ->where($qb->expr()->eq('c.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.vector_generation_id', $qb->createNamedParameter($generation->id)))
            ->andWhere($qb->expr()->eq('c.vector_revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('d.user_id', $qb->createNamedParameter($generation->userId)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return null;
        if (strlen($row['embedding']) > 524288 || strlen($row['provenance']) > 4096 || strlen($row['content']) > 40000) throw new VectorStoreException('invalid_point');
        $document = $row; $document['id'] = $row['document_id']; $document['content_hash'] = $row['document_content_hash'];
        $current = VectorDocumentSnapshot::fromRow($document);
        $provenance = json_decode($row['provenance'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($provenance) || !is_array($provenance['_eva_vector'] ?? null) || ($provenance['_eva_vector']['version'] ?? null) !== 1
            || ($provenance['_eva_vector']['document_stamp'] ?? '') !== $current->stamp) return null;
        if (($provenance['_eva_vector']['chunk_size'] ?? null) !== $generation->chunkSize
            || ($provenance['_eva_vector']['chunk_overlap'] ?? null) !== $generation->chunkOverlap) throw new VectorStoreException('invalid_point');
        if (!hash_equals(hash('sha256', $row['content']), $row['content_hash'])) throw new VectorStoreException('invalid_point');
        $vector = json_decode($row['embedding'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($vector)) throw new VectorStoreException('invalid_point');
        $generation->validateVector($vector);
        return ['id' => (int)$row['id'], 'document_id' => (int)$row['document_id'], 'file_id' => (int)$row['file_id'],
            'chunk_index' => (int)$row['chunk_index'], 'source' => $row['source'], 'path' => $row['path'],
            'content_hash' => $row['content_hash'], 'vector' => $vector];
    }

    public function countStaged(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'total')->from('eva_ai_chunks')
            ->where($qb->expr()->isNotNull('vector_generation_id'));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return (int)($row['total'] ?? 0);
    }

    private function lockBuilding(VectorGeneration $generation, string $target): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_generations')->set('build_cursor', $qb->func()->add('build_cursor', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($generation->id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($generation->userId)))->executeStatement();
        $read = $this->db->getQueryBuilder();
        $read->select('*')->from('eva_ai_vec_generations')->where($read->expr()->eq('id', $read->createNamedParameter($generation->id)))
            ->andWhere($read->expr()->eq('user_id', $read->createNamedParameter($generation->userId)));
        $result = $read->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row || $row['status'] !== 'building' || $row['storage_endpoint_hash'] !== $target
            || VectorGeneration::fromRow($row)->toRow() !== $generation->toRow()) throw new VectorStoreException('generation_not_building');
    }
}
