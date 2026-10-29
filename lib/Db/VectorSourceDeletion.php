<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorGeneration;
use OCA\EvaAi\Vector\VectorStoreException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Canonical deletion and remote tombstone intent share one SQL transaction. */
class VectorSourceDeletion {
    private VectorOwnerFenceRepository $owners;
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox,?VectorOwnerFenceRepository $owners=null) {
        $this->owners=$owners??new VectorOwnerFenceRepository($db,$outbox);
    }

    /** Source creation and verification invalidation commit atomically. */
    public function insertDocument(string $userId,callable $insert): mixed {
        return $this->outbox->transaction(function()use($userId,$insert):mixed{
            $this->owners->lockOpen($userId);
            $entity=$insert();
            $this->invalidateOwnerVerification($userId);
            return $entity;
        });
    }

    /** Serialize each legacy chunk insert against the persistent owner erase fence. */
    public function insertChunk(int $documentId,callable $insert): mixed {
        return $this->insertChunks($documentId,$insert);
    }

    /** Hold source/owner fences once for a bounded batch belonging to one document. */
    public function insertChunks(int $documentId,callable $insert): mixed {
        if($documentId<1)throw new \InvalidArgumentException('Chunk requires a valid source document.');
        return $this->outbox->transaction(function()use($documentId,$insert):mixed{
            $user=$this->documentOwner($documentId)??throw new VectorStoreException('source_missing');
            $this->owners->lockOpen($user);
            $this->lockDocuments([$documentId]);
            if($this->documentOwner($documentId)!==$user)throw new VectorStoreException('source_changed');
            return $insert();
        });
    }

    /** @return array{chunks:int,documents:int} */
    public function deleteDocuments(array $ids, bool $removeDocuments = false, bool $generationOnly = false, ?string $generationId = null): array {
        if ($removeDocuments && $generationOnly) throw new \InvalidArgumentException('Document removal must include legacy chunks.');
        if ($generationId !== null && (!$generationOnly || preg_match('/^[a-f0-9]{32}$/D',$generationId)!==1)) throw new \InvalidArgumentException('A generation filter requires generation-only removal.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 500) throw new \InvalidArgumentException('Source deletion requires bounded document batches.');
        if ($ids === []) return ['chunks' => 0, 'documents' => 0];
        sort($ids, SORT_NUMERIC);
        return $this->outbox->transaction(function () use ($ids, $removeDocuments, $generationOnly, $generationId): array {
            // Same source-row boundary as putBuildingChunk. A concurrent writer
            // must recheck the source after this transaction releases the lock.
            $this->lockDocuments($ids);
            // Any canonical chunk deletion changes the certified SQL inventory.
            foreach($this->documentOwners($ids)as$user)$this->invalidateOwnerVerification($user);
            $cursor = 0;
            do {
                $qb = $this->db->getQueryBuilder();
                $qb->select('c.id', 'c.document_id', 'c.vector_generation_id', 'c.vector_revision', 'd.user_id')->from('eva_ai_chunks', 'c')
                    ->leftJoin('c', 'eva_ai_documents', 'd', $qb->expr()->eq('c.document_id', 'd.id'))
                    ->where($qb->expr()->in('c.document_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($qb->expr()->isNotNull('c.vector_generation_id'))
                    ->andWhere($qb->expr()->gt('c.id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
                    ->orderBy('c.id', 'ASC')->setMaxResults(250);
                if ($generationId!==null) $qb->andWhere($qb->expr()->eq('c.vector_generation_id',$qb->createNamedParameter($generationId)));
                $result = $qb->executeQuery();
                try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
                $generations = [];
                foreach ($rows as $row) {
                    $key = $row['vector_generation_id'];
                    if (!isset($generations[$key])) $generations[$key] = $this->generation($key, $row['user_id']);
                    [$generation, $target] = $generations[$key];
                    if ($row['user_id'] !== null && $generation->userId !== $row['user_id']) throw new VectorStoreException('source_identity_mismatch');
                    $revision = $this->outbox->enqueue($generation, $target, 'delete_chunk', (int)$row['document_id'], (int)$row['id']);
                    if ($revision > 9007199254740991 || $revision <= (int)$row['vector_revision']) throw new VectorStoreException('invalid_revision');
                    $cursor = (int)$row['id'];
                }
            } while (count($rows) === 250);
            $delete = $this->db->getQueryBuilder();
            $delete->delete('eva_ai_chunks')->where($delete->expr()->in('document_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            if ($generationOnly) $delete->andWhere($delete->expr()->isNotNull('vector_generation_id'));
            if ($generationId!==null) $delete->andWhere($delete->expr()->eq('vector_generation_id',$delete->createNamedParameter($generationId)));
            $chunks = $delete->executeStatement();
            $documents = 0;
            if ($removeDocuments) {
                $delete = $this->db->getQueryBuilder();
                $delete->delete('eva_ai_documents')->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
                $documents = $delete->executeStatement();
            }
            return ['chunks' => $chunks, 'documents' => $documents];
        });
    }

    /** Source metadata and invalidation commit together, without re-embedding. */
    public function mutateDocuments(array $ids, callable $mutation): mixed {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 500) throw new \InvalidArgumentException('Source mutation requires bounded document batches.');
        sort($ids, SORT_NUMERIC);
        if ($ids === []) return $mutation();
        return $this->outbox->transaction(function () use ($ids, $mutation): mixed {
            $this->lockDocuments($ids);
            $before = $this->sourceMetadata($ids);
            $result = $mutation();
            $after = $this->sourceMetadata($ids);
            $changed = [];
            $owners=[];
            foreach ($before as $id => $metadata) {
                if (isset($after[$id]) && $metadata['user_id'] !== $after[$id]['user_id']) throw new VectorStoreException('source_identity_mismatch');
                if ($metadata !== ($after[$id] ?? null)) { $changed[] = $id; $owners[$metadata['user_id']]=true; }
            }
            // Old point IDs become terminal tombstones. A later backfill uses
            // fresh IDs and a new source stamp; legacy search stays available.
            if ($changed !== []) {
                foreach(array_keys($owners)as$user)$this->invalidateOwnerVerification((string)$user);
                $this->deleteDocuments($changed, false, true);
            }
            return $result;
        });
    }

    private function invalidateOwnerVerification(string $userId): void {
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_generations')
            ->set('verified_at',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
            ->set('verification_hash',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)))->executeStatement();
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_verifications')
            ->set('status',$q->createNamedParameter('stale'))
            ->set('error_code',$q->createNamedParameter('source_changed'))
            ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
            ->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)))->executeStatement();
    }

    /** @return list<string> */
    private function documentOwners(array $ids): array {
        $q=$this->db->getQueryBuilder();$q->selectDistinct('user_id')->from('eva_ai_documents')
            ->where($q->expr()->in('id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_INT_ARRAY)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        return array_values(array_unique(array_filter(array_map(static fn(array$row):string=>(string)$row['user_id'],$rows))));
    }

    private function documentOwner(int $id): ?string {
        $q=$this->db->getQueryBuilder();$q->select('user_id')->from('eva_ai_documents')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{$owner=$r->fetchOne();}finally{$r->closeCursor();}
        return is_string($owner)&&$owner!==''?$owner:null;
    }

    private function lockDocuments(array $ids): void {
        $lock = $this->db->getQueryBuilder();
        $lock->update('eva_ai_documents')->set('file_mtime', $lock->func()->add('file_mtime', $lock->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->where($lock->expr()->in('id', $lock->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
    }

    private function sourceMetadata(array $ids): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'file_id', 'path', 'name', 'source', 'content_hash', 'file_mtime', 'size', 'mime')->from('eva_ai_documents')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        $metadata = [];
        foreach ($rows as $row) $metadata[(int)$row['id']] = $row;
        return $metadata;
    }

    private function generation(string $id, ?string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        if ($userId !== null) $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row || !is_string($row['storage_endpoint_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $row['storage_endpoint_hash']) !== 1) throw new VectorStoreException('invalid_source_generation');
        return [VectorGeneration::fromRow($row), $row['storage_endpoint_hash']];
    }
}
