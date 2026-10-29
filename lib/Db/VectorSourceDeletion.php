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
    private VectorGenerationMutationRepository $mutations;
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox,
        ?VectorOwnerFenceRepository $owners=null, ?VectorGenerationMutationRepository $mutations=null) {
        $this->owners=$owners??new VectorOwnerFenceRepository($db,$outbox);
        $this->mutations=$mutations??new VectorGenerationMutationRepository($db);
    }

    /** Source creation and verification invalidation commit atomically. */
    public function insertDocument(string $userId,callable $insert): mixed {
        return $this->outbox->transaction(function()use($userId,$insert):mixed{
            $this->owners->lockOpen($userId);
            $entity=$insert();
            if (is_object($entity) && method_exists($entity, 'getId')) {
                $documentId=(int)$entity->getId();
                if ($documentId>0) {
                    $this->extendBuildWatermarks($userId,$documentId);
                    $this->mutations->enqueueActive($userId,$documentId);
                }
            }
            $this->invalidateOwnerVerification($userId);
            return $entity;
        });
    }

    /** Keep active generation builds current with normal indexed document inserts. */
    private function extendBuildWatermarks(string $userId,int $documentId): void {
        $q=$this->db->getQueryBuilder();
        $q->select('b.generation_id','b.status','b.upper_document_id','b.after_document_id','b.document_id')
            ->from('eva_ai_vec_builds','b')
            ->innerJoin('b','eva_ai_vec_generations','g',$q->expr()->eq('g.id','b.generation_id'))
            ->where($q->expr()->eq('b.user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('g.user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('building')))
            ->andWhere($q->expr()->in('b.status',$q->createNamedParameter(['queued','running','paused','staged'],IQueryBuilder::PARAM_STR_ARRAY)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as$row){
            $currentDocument=(int)$row['document_id'];
            $rewind=$documentId<=(int)$row['after_document_id']||($currentDocument>0&&$documentId<$currentDocument);
            $invalidate=$rewind||$row['status']==='staged';
            $after=$rewind?max(0,$documentId-1):(int)$row['after_document_id'];
            $upper=max((int)$row['upper_document_id'],$documentId);
            $status=$invalidate&&in_array($row['status'],['running','staged'],true)?'queued':$row['status'];
            $update=$this->db->getQueryBuilder();
            $update->update('eva_ai_vec_builds')
                ->set('upper_document_id',$update->createNamedParameter($upper,IQueryBuilder::PARAM_INT))
                ->set('after_document_id',$update->createNamedParameter($after,IQueryBuilder::PARAM_INT))
                ->set('total_documents',$update->func()->add('total_documents',$update->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
                ->set('status',$update->createNamedParameter($status))
                ->set('revision',$update->func()->add('revision',$update->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
                ->set('updated_at',$update->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
                ->where($update->expr()->eq('user_id',$update->createNamedParameter($userId)))
                ->andWhere($update->expr()->eq('generation_id',$update->createNamedParameter($row['generation_id'])));
            if($invalidate){
                $update->set('document_id',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                    ->set('chunk_cursor',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                    ->set('plan_hash',$update->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                    ->set('lease_token',$update->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                    ->set('lease_until',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT));
            }
            $update->executeStatement();
        }
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
                    if ($generation->backend === 'qdrant') {
                        $revision = $this->outbox->enqueue($generation, $target, 'delete_chunk', (int)$row['document_id'], (int)$row['id']);
                        if ($revision > 9007199254740991 || $revision <= (int)$row['vector_revision']) throw new VectorStoreException('invalid_revision');
                    }
                    $cursor = (int)$row['id'];
                }
            } while (count($rows) === 250);
            $terms = new LexicalTermRepository($this->db);
            if ($generationOnly) $terms->deleteGenerationDocuments($ids, $generationId);
            else $terms->deleteDocuments($ids);
            $delete = $this->db->getQueryBuilder();
            $delete->delete('eva_ai_chunks')->where($delete->expr()->in('document_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            if ($generationOnly) $delete->andWhere($delete->expr()->isNotNull('vector_generation_id'));
            if ($generationId!==null) $delete->andWhere($delete->expr()->eq('vector_generation_id',$delete->createNamedParameter($generationId)));
            $chunks = $delete->executeStatement();
            $documents = 0;
            if ($removeDocuments) {
                $this->mutations->deleteSources($ids);
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
        $knownOwners=$this->documentOwners($ids);
        sort($knownOwners,SORT_STRING);
        return $this->outbox->transaction(function () use ($ids, $mutation, $knownOwners): mixed {
            // Match build-worker lock order: owner fence before build state and source rows.
            foreach($knownOwners as$user)$this->owners->lockOpen($user);
            $this->lockDocuments($ids);
            $before = $this->sourceMetadata($ids);
            foreach($before as$metadata)if(!in_array($metadata['user_id'],$knownOwners,true))throw new VectorStoreException('source_identity_mismatch');
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
                foreach(array_keys($owners)as$user){
                    $user=(string)$user;
                    $this->invalidateOwnerVerification($user);
                    $ownedIds=array_values(array_map('intval',array_filter($changed,static fn(int$id):bool=>($before[$id]['user_id']??null)===$user)));
                    $this->rewindBuildsForChangedDocuments($user,$ownedIds);
                    foreach($ownedIds as$documentId) {
                        if (isset($after[$documentId])) $this->mutations->enqueueActive($user,$documentId);
                    }
                }
                $this->deleteDocuments($changed, false, true);
            }
            return $result;
        });
    }

    /** Revisit changed source stamps in every prepared build without resuming a user-paused build. */
    private function rewindBuildsForChangedDocuments(string $userId,array $documentIds): void {
        if($documentIds===[])return;
        sort($documentIds,SORT_NUMERIC);$firstId=$documentIds[0];
        $q=$this->db->getQueryBuilder();
        $q->select('b.generation_id','b.status','b.after_document_id','b.document_id','b.documents_done')
            ->from('eva_ai_vec_builds','b')
            ->innerJoin('b','eva_ai_vec_generations','g',$q->expr()->eq('g.id','b.generation_id'))
            ->where($q->expr()->eq('b.user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('g.user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('building')))
            ->andWhere($q->expr()->in('b.status',$q->createNamedParameter(['queued','running','paused','staged'],IQueryBuilder::PARAM_STR_ARRAY)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as$row){
            $currentDocument=(int)$row['document_id'];
            $rewind=$firstId<=(int)$row['after_document_id']||($currentDocument>0&&$firstId<=$currentDocument);
            $manifest=$this->db->getQueryBuilder();
            $manifest->selectAlias($manifest->createFunction('COUNT(*)'),'total')->from('eva_ai_vec_build_docs')
                ->where($manifest->expr()->eq('generation_id',$manifest->createNamedParameter($row['generation_id'])))
                ->andWhere($manifest->expr()->eq('user_id',$manifest->createNamedParameter($userId)))
                ->andWhere($manifest->expr()->in('document_id',$manifest->createNamedParameter($documentIds,IQueryBuilder::PARAM_INT_ARRAY)));
            $mr=$manifest->executeQuery();try{$completed=(int)$mr->fetchOne();}finally{$mr->closeCursor();}
            $remove=$this->db->getQueryBuilder();
            $remove->delete('eva_ai_vec_build_docs')->where($remove->expr()->eq('generation_id',$remove->createNamedParameter($row['generation_id'])))
                ->andWhere($remove->expr()->eq('user_id',$remove->createNamedParameter($userId)))
                ->andWhere($remove->expr()->in('document_id',$remove->createNamedParameter($documentIds,IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
            if(!$rewind&&$completed===0)continue;
            $status=in_array($row['status'],['running','staged'],true)?'queued':$row['status'];
            $update=$this->db->getQueryBuilder();
            $update->update('eva_ai_vec_builds')
                ->set('documents_done',$update->createNamedParameter(max(0,(int)$row['documents_done']-$completed),IQueryBuilder::PARAM_INT))
                ->set('revision',$update->func()->add('revision',$update->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
                ->set('updated_at',$update->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
                ->set('status',$update->createNamedParameter($status))
                ->where($update->expr()->eq('user_id',$update->createNamedParameter($userId)))
                ->andWhere($update->expr()->eq('generation_id',$update->createNamedParameter($row['generation_id'])));
            if($rewind){
                $update->set('after_document_id',$update->createNamedParameter(min((int)$row['after_document_id'],max(0,$firstId-1)),IQueryBuilder::PARAM_INT))
                    ->set('document_id',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                    ->set('chunk_cursor',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                    ->set('plan_hash',$update->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                    ->set('lease_token',$update->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                    ->set('lease_until',$update->createNamedParameter(0,IQueryBuilder::PARAM_INT));
            }
            $update->executeStatement();
        }
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
            || preg_match('/^[a-f0-9]{64}$/D', $row['storage_endpoint_hash']) !== 1
            || ($row['backend'] === 'sql' && !hash_equals(VectorGenerationRepository::sqlTargetHash(), $row['storage_endpoint_hash']))) throw new VectorStoreException('invalid_source_generation');
        return [VectorGeneration::fromRow($row), $row['storage_endpoint_hash']];
    }
}
