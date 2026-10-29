<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Dto\PreparedVectorChunk;
use OCA\EvaAi\Vector\VectorDocumentSnapshot;
use OCA\EvaAi\Vector\VectorGeneration;
use OCA\EvaAi\Vector\VectorEmbeddingCodec;
use OCA\EvaAi\Vector\VectorStoreException;
use OCA\EvaAi\Vector\SearchFilters;
use OCA\EvaAi\Vector\VectorNorm;
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

    /** Hydrate tenant-owned SQL chunks at the exact remote point revisions. */
    public function searchRows(string $userId, VectorGeneration $generation, array $hits): array {
        if($generation->userId!==$userId||$generation->backend!=='qdrant'||!array_is_list($hits)||count($hits)>100)throw new VectorStoreException('invalid_search_response');
        if($hits===[])return [];
        $revisions=[];
        foreach($hits as$hit){
            if(!is_array($hit)||!is_int($hit['chunkId']??null)||$hit['chunkId']<1||isset($revisions[$hit['chunkId']])
                ||!is_int($hit['revision']??null)||$hit['revision']<1||$hit['revision']>9007199254740991)throw new VectorStoreException('invalid_search_response');
            $revisions[$hit['chunkId']]=$hit['revision'];
        }
        $rowsById=[];
        foreach(array_chunk(array_keys($revisions),500)as$ids){
            $q=$this->db->getQueryBuilder();
            $q->select('c.id','c.document_id','c.chunk_index','c.content','c.embedding','c.vector_norm','c.provenance','c.content_hash','c.vector_revision','c.vector_state',
                'd.user_id','d.file_id','d.path','d.name','d.source')
                ->from('eva_ai_chunks','c')->innerJoin('c','eva_ai_documents','d',$q->expr()->eq('d.id','c.document_id'))
                ->where($q->expr()->in('c.id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($generation->id)))
                ->andWhere($q->expr()->eq('c.vector_state',$q->createNamedParameter('delivered')))
                ->andWhere($q->expr()->eq('d.user_id',$q->createNamedParameter($userId)));
            $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
            foreach($rows as$row)$rowsById[(int)$row['id']]=$row;
        }
        $snapshots=[];$ordered=[];
        foreach($hits as$hit){
            $id=$hit['chunkId'];$row=$rowsById[$id]??null;
            if($row===null||(int)$row['vector_revision']!==$hit['revision'])throw new VectorStoreException('search_stale_revision');
            $documentId=(int)$row['document_id'];
            $snapshot=$snapshots[$documentId]??=$this->snapshot($userId,$documentId);
            $provenance=json_decode((string)$row['provenance'],true);
            if($snapshot===null||!is_array($provenance)||!is_array($provenance['_eva_vector']??null)
                ||!hash_equals($snapshot->stamp,(string)($provenance['_eva_vector']['document_stamp']??''))
                ||!hash_equals(hash('sha256',(string)$row['content']),(string)$row['content_hash']))throw new VectorStoreException('search_stale_source');
            $ordered[]=$row;
        }
        return $ordered;
    }

    /** Bounded export of one active Qdrant generation for an explicit benchmark snapshot. */
    public function benchmarkRowsAfter(string $userId, VectorGeneration $generation, int $afterId, int $limit = 250): array {
        if ($generation->userId !== $userId || $generation->backend !== 'qdrant' || $afterId < 0) {
            throw new VectorStoreException('invalid_benchmark_generation');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('c.id', 'c.document_id', 'c.chunk_index', 'c.content', 'c.embedding', 'c.provenance',
            'c.content_hash', 'c.vector_revision', 'd.user_id', 'd.file_id', 'd.path', 'd.name', 'd.source')
            ->from('eva_ai_chunks', 'c')
            ->innerJoin('c', 'eva_ai_documents', 'd', $q->expr()->eq('d.id', 'c.document_id'))
            ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('c.vector_generation_id', $q->createNamedParameter($generation->id)))
            ->andWhere($q->expr()->eq('c.vector_state', $q->createNamedParameter('delivered')))
            ->andWhere($q->expr()->gt('c.id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('c.id', 'ASC')->setMaxResults(max(1, min(500, $limit)));
        $result = $q->executeQuery();
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }

    /** Source-row lock shared by chunk writes and zero-chunk build checkpoints. */
    public function lockSnapshot(VectorDocumentSnapshot $snapshot): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Source locking requires the mutation transaction.');
        $lock=$this->db->getQueryBuilder();
        $lock->update('eva_ai_documents')->set('file_mtime',$lock->func()->add('file_mtime',$lock->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->where($lock->expr()->eq('id',$lock->createNamedParameter($snapshot->id,IQueryBuilder::PARAM_INT)))
            ->andWhere($lock->expr()->eq('user_id',$lock->createNamedParameter($snapshot->userId)))->executeStatement();
        $current=$this->snapshot($snapshot->userId,$snapshot->id);
        if ($current===null || !hash_equals($snapshot->stamp,$current->stamp)) throw new VectorStoreException('source_changed');
    }

    /**
     * Embedding must finish before calling this short SQL transaction. Recheck
     * immutable generation and source metadata, preserving the entire legacy row.
     * @return array{id:int,revision:int,changed:bool}
     */
    public function putBuildingChunk(VectorGeneration $generation, string $target,
        VectorDocumentSnapshot $snapshot, PreparedVectorChunk $chunk): array {
        return $this->putChunkForGenerationStatus($generation,$target,$snapshot,$chunk,'building');
    }

    /** Idempotent per-chunk upsert for a leased, active-generation mutation. */
    public function putActiveChunk(VectorGeneration $generation, string $target,
        VectorDocumentSnapshot $snapshot, PreparedVectorChunk $chunk): array {
        return $this->putChunkForGenerationStatus($generation,$target,$snapshot,$chunk,'active');
    }

    private function putChunkForGenerationStatus(VectorGeneration $generation, string $target,
        VectorDocumentSnapshot $snapshot, PreparedVectorChunk $chunk, string $expectedStatus): array {
        if ($snapshot->userId !== $generation->userId || preg_match('/^[a-f0-9]{64}$/D', $target) !== 1) throw new VectorStoreException('source_identity_mismatch');
        $chunk->validate($generation);
        $isSql = $generation->backend === 'sql';
        $embedding = $isSql
            ? VectorEmbeddingCodec::encode($chunk->vector)
            : json_encode(array_map('floatval', $chunk->vector), JSON_THROW_ON_ERROR);
        $vectorNorm = VectorNorm::of($chunk->vector);
        if ($vectorNorm === null) throw new VectorStoreException('invalid_sql_vector');
        $hash = hash('sha256', $chunk->content);
        $provenance = json_encode($chunk->provenance + ['_eva_vector' => ['version' => 1,
            'document_stamp' => $snapshot->stamp, 'chunk_size' => $generation->chunkSize,
            'chunk_overlap' => $generation->chunkOverlap]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($embedding) > 524288 || strlen($provenance) > 4096) throw new VectorStoreException('chunk_too_large');

        return $this->outbox->transaction(function () use ($generation, $target, $snapshot, $chunk, $embedding, $vectorNorm, $hash, $provenance, $expectedStatus, $isSql): array {
            $this->owners->lockOpen($generation->userId);
            $this->lockGenerationStatus($generation, $target, $expectedStatus);
            // Lock source before checking the stamp. Normal metadata updates and
            // deletes serialize on this document row, including older cores.
            $this->lockSnapshot($snapshot);

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
            $revision = null;
            if ($isSql) {
                $previousRevision = (int)($row['vector_revision'] ?? 0);
                if ($previousRevision < 0 || $previousRevision >= 9007199254740991) throw new VectorStoreException('invalid_revision');
                $revision = $previousRevision + 1;
            }
            $values = ['document_id' => $snapshot->id, 'chunk_index' => $chunk->index, 'content' => $chunk->content,
                'embedding' => $embedding, 'vector_norm' => (string)$vectorNorm, 'provenance' => $provenance, 'token_count' => $chunk->tokens,
                'vector_generation_id' => $generation->id, 'vector_state' => $isSql ? 'ready' : 'pending', 'content_hash' => $hash,
                'lexical_indexed' => 0];
            if ($isSql) $values['vector_revision'] = $revision;
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
            if (!$isSql) {
                $revision = $this->outbox->enqueue($generation, $target, 'upsert', $snapshot->id, $id);
                if ($revision > 9007199254740991 || ($row !== null && $revision <= (int)$row['vector_revision'])) throw new VectorStoreException('invalid_revision');
                $version = $this->db->getQueryBuilder();
                $version->update('eva_ai_chunks')->set('vector_revision', $version->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
                    ->where($version->expr()->eq('id', $version->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            }
            return ['id' => $id, 'revision' => $revision, 'changed' => true];
        });
    }

    /** Owned and source-current payload for a later point worker; no HTTP here. */
    public function pointForDelivery(VectorGeneration $generation, int $id, int $revision): ?array {
        if ($generation->backend !== 'qdrant' || $id < 1 || $revision < 1 || $revision > 9007199254740991) throw new VectorStoreException('invalid_revision');
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
            'source_mtime' => (int)$row['file_mtime'], 'source_size' => (int)$row['size'],
            'content_hash' => $row['content_hash'], 'vector' => $vector]
            + ($generation->includeTextPayload ? ['content' => $row['content']] : []);
    }

    public function countStaged(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'total')->from('eva_ai_chunks')
            ->where($qb->expr()->isNotNull('vector_generation_id'));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return (int)($row['total'] ?? 0);
    }

    /** Bounded exact SQL dense ranking used only by the explicit shadow probe. */
    public function shadowDenseRanking(VectorGeneration $generation,array $vector,SearchFilters $filters,int $topK,
        int $maxRows=20000,float $budgetSeconds=3.0): array {
        if($generation->backend!=='qdrant')throw new VectorStoreException('invalid_shadow_generation');
        $generation->validateVector($vector);
        if($topK<1||$topK>20||$maxRows<1||$maxRows>20000||$budgetSeconds<=0||$budgetSeconds>5)throw new VectorStoreException('invalid_shadow_budget');
        $queryNorm=VectorNorm::of($vector);
        if($queryNorm===null)throw new VectorStoreException('invalid_shadow_vector');
        $started=microtime(true);$cursor=0;$scanned=0;$best=[];
        do{
            if(microtime(true)-$started>=$budgetSeconds)throw new VectorStoreException('sql_shadow_budget_exceeded');
            $q=$this->db->getQueryBuilder();$q->select('c.id','c.document_id','c.vector_revision','c.embedding','c.vector_norm','d.path','d.source')
                ->from('eva_ai_chunks','c')->innerJoin('c','eva_ai_documents','d',$q->expr()->eq('d.id','c.document_id'))
                ->where($q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($generation->id)))
                ->andWhere($q->expr()->eq('d.user_id',$q->createNamedParameter($generation->userId)))
                ->andWhere($q->expr()->gt('c.id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))
                ->orderBy('c.id','ASC')->setMaxResults(500);
            $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
            foreach($rows as$row){
                $cursor=(int)$row['id'];$scanned++;
                if($scanned>$maxRows)throw new VectorStoreException('sql_shadow_rows_exceeded');
                if((int)$row['vector_revision']<1)throw new VectorStoreException('invalid_sql_revision');
                if($filters->source!==null&&$row['source']!==$filters->source)continue;
                if($filters->scopePath!==null&&$filters->scopePath!==''){
                    $path=(string)$row['path'];$prefix=rtrim($filters->scopePath,'/');
                    if($row['source']!=='files'||($path!==$prefix&&!str_starts_with($path,$prefix.'/')))continue;
                }
                $embedding=json_decode($row['embedding'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($embedding))throw new VectorStoreException('invalid_sql_vector');
                if(!array_is_list($embedding)||count($embedding)!==$generation->dimension)throw new VectorStoreException('invalid_sql_vector');
                $dot=0.0;$sum=0.0;
                $storedNorm=is_numeric($row['vector_norm']??null)&&(float)$row['vector_norm']>0.0
                    ?(float)$row['vector_norm']:null;
                foreach($vector as$i=>$value){
                    if(!is_int($embedding[$i]??null)&&!is_float($embedding[$i]??null))throw new VectorStoreException('invalid_sql_vector');
                    $other=(float)$embedding[$i];
                    if(!is_finite($other))throw new VectorStoreException('invalid_sql_vector');
                    $dot+=$value*$other;
                    if($storedNorm===null)$sum+=$other*$other;
                }
                $magnitude=$storedNorm??(is_finite($sum)&&$sum>0?sqrt($sum):null);
                if($magnitude===null||!is_finite($magnitude)||$magnitude<=0)continue;
                $best[]=['id'=>$cursor,'revision'=>(int)$row['vector_revision'],'score'=>$dot/($queryNorm*$magnitude)];
            }
            if(count($best)>$topK*4){usort($best,static fn(array$a,array$b):int=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));$best=array_slice($best,0,$topK);}
        }while($rows!==[]);
        usort($best,static fn(array$a,array$b):int=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
        return ['points'=>array_slice($best,0,$topK),'scanned'=>$scanned,'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
    }

    /** Revisions for returned shadow IDs; no content is hydrated or logged. */
    public function shadowRevisions(VectorGeneration $generation,array $ids): array {
        if(!array_is_list($ids)||count($ids)>20)throw new VectorStoreException('invalid_shadow_results');
        if($ids===[])return [];
        $q=$this->db->getQueryBuilder();$q->select('c.id','c.vector_revision')->from('eva_ai_chunks','c')
            ->innerJoin('c','eva_ai_documents','d',$q->expr()->eq('d.id','c.document_id'))
            ->where($q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($generation->id)))
            ->andWhere($q->expr()->eq('d.user_id',$q->createNamedParameter($generation->userId)))
            ->andWhere($q->expr()->in('c.id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_INT_ARRAY)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        $revisions=[];foreach($rows as$row)$revisions[(int)$row['id']]=(int)$row['vector_revision'];return $revisions;
    }

    private function lockGenerationStatus(VectorGeneration $generation, string $target, string $expectedStatus): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_generations')->set('build_cursor', $qb->func()->add('build_cursor', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($generation->id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($generation->userId)))->executeStatement();
        $read = $this->db->getQueryBuilder();
        $read->select('*')->from('eva_ai_vec_generations')->where($read->expr()->eq('id', $read->createNamedParameter($generation->id)))
            ->andWhere($read->expr()->eq('user_id', $read->createNamedParameter($generation->userId)));
        $result = $read->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row || $row['status'] !== $expectedStatus || $row['storage_endpoint_hash'] !== $target
            || VectorGeneration::fromRow($row)->toRow() !== $generation->toRow()) throw new VectorStoreException('generation_state_changed');
    }
}
