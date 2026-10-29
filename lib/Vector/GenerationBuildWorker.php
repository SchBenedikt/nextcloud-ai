<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationBuildRepository, GenerationChunkRepository, VectorGenerationRepository, VectorSourceDeletion};
use OCA\EvaAi\Dto\PreparedVectorChunk;
use OCA\EvaAi\Service\{AppConfig,ChatPriorityYieldException,LockGuard};
use OCP\Lock\{ILockingProvider,LockedException};

/** Bounded background work only. SQL retrieval and interactive chat never call it. */
class GenerationBuildWorker {
    public function __construct(private GenerationBuildRepository $builds, private VectorGenerationRepository $generations,
        private GenerationChunkRepository $chunks, private VectorSourceDeletion $deletion, private GenerationDocumentPreparer $preparer,
        private GenerationEmbeddingProvider $embeddings, private VectorSourceAccess $access, private AppConfig $config,
        private ?ILockingProvider $lockingProvider=null) {}

    /** One lease, at most 256 chunks / 16 sources and a 90-second work budget. */
    public function run(): array {
        $claim=$this->builds->claim(time());
        $stats=['claimed'=>$claim!==null,'chunks'=>0,'documents'=>0,'staged'=>false,'deferred_for_chat'=>false,
            'deferred_for_source_refresh'=>false,'error'=>null];
        if ($claim===null) return $stats;
        $previous=$this->config->userId();$deadline=microtime(true)+90;
        try {
            $this->config->setUserId($claim['user_id']);
            $generation=$this->generations->find($claim['user_id'],$claim['generation_id']) ?? throw new VectorStoreException('generation_missing');
            while ($stats['chunks']<256 && $stats['documents']<16 && microtime(true)<$deadline) {
                $row=$this->builds->assertLease($claim,time());
                if($this->chatRequestIsActive($claim['user_id'])){
                    $this->builds->release($claim);$stats['deferred_for_chat']=true;return $stats;
                }
                $this->access->assertOwner($generation->userId);
                $documentId=$this->builds->nextDocument($row);
                if ($documentId===null) {
                    $nextDocument=null;
                    $this->builds->withLease($claim,function(array $current)use($claim,&$nextDocument):void {
                        // Recheck under the same owner fence as document creation.
                        // A new source may have arrived after nextDocument() above.
                        $nextDocument=$this->builds->nextDocument($current);
                        if($nextDocument!==null)return;
                        $this->builds->checkpoint($claim,['status'=>'staged','lease_token'=>null,'lease_until'=>0]);
                    });
                    if($nextDocument!==null)continue;
                    $stats['staged']=true;
                    return $stats;
                }
                $source=$this->chunks->snapshot($generation->userId,$documentId);
                if ($source===null) {
                    // A canonical deletion already removed its prepared points.
                    $this->builds->withLease($claim,fn()=> $this->builds->checkpoint($claim,
                        ['after_document_id'=>$documentId,'document_id'=>0,'chunk_cursor'=>0,'plan_hash'=>null]));
                    $stats['documents']++;
                    continue;
                }
                if ($row['document_id']!==$documentId) {
                    $this->builds->withLease($claim,function()use($claim,$source):void {
                        $this->chunks->lockSnapshot($source);
                        $this->builds->checkpoint($claim,['document_id'=>$source->id,'chunk_cursor'=>0,'plan_hash'=>null]);
                    });
                }
                $plan=$this->preparer->prepare($generation,$source,fn()=> $this->guard($claim,$generation,$source,$deadline),
                    fn():bool=>$this->chatRequestIsActive($claim['user_id']));
                $this->guard($claim,$generation,$source,$deadline);
                $this->builds->withLease($claim,function(array $current)use($claim,$source,$plan,$documentId,$generation):void {
                    $this->chunks->lockSnapshot($source);
                    if ($current['document_id']!==$documentId || $current['plan_hash']!==$plan->planHash) {
                        // New IDs after terminal tombstones; never disturb legacy
                        // rows or another generation's in-progress snapshot.
                        $this->deletion->deleteDocuments([$documentId],false,true,$generation->id);
                        $this->builds->checkpoint($claim,['document_id'=>$documentId,'chunk_cursor'=>0,'plan_hash'=>$plan->planHash]);
                    }
                });
                // Reuse this extraction for several small embedding batches.
                do {
                    $row=$this->builds->assertLease($claim,time());
                    $offset=$row['chunk_cursor'];
                    if ($offset>count($plan->chunks)) throw new VectorStoreException('invalid_build_cursor');
                    $batch=array_slice($plan->chunks,$offset,min(8,256-$stats['chunks']));
                    foreach ($batch as $index=>$chunk) new PreparedVectorChunk($offset+$index,$chunk['content'],[],$chunk['tokens'],$chunk['provenance']);
                    $guard=fn()=> $this->guard($claim,$generation,$source,$deadline);
                    if($this->chatRequestIsActive($claim['user_id'])){
                        $this->builds->release($claim);$stats['deferred_for_chat']=true;return $stats;
                    }
                    $vectors=$batch===[]?[]:$this->embeddings->embed($generation,array_column($batch,'content'),$guard,true,
                        min(30,max(1,(int)ceil($deadline-microtime(true)))));
                    $guard();
                    $done=$offset+count($batch)===count($plan->chunks);
                    $this->builds->withLease($claim,function(array $current)use($claim,$generation,$source,$plan,$batch,$vectors,$offset,$done):void {
                        if ($current['document_id']!==$source->id || $current['chunk_cursor']!==$offset || $current['plan_hash']!==$plan->planHash) throw new VectorStoreException('stale_build_lease');
                        $this->chunks->lockSnapshot($source);
                        foreach ($batch as $index=>$chunk) $this->chunks->putBuildingChunk($generation,$claim['target_hash'],$source,
                            new PreparedVectorChunk($offset+$index,$chunk['content'],$vectors[$index],$chunk['tokens'],$chunk['provenance']));
                        if ($done) {
                            $this->builds->completeDocument($claim,$source->id,$source->stamp,$plan->planHash,count($plan->chunks));
                            $this->builds->checkpoint($claim,['after_document_id'=>$source->id,'document_id'=>0,'chunk_cursor'=>0,
                                'plan_hash'=>null,'documents_done'=>$current['documents_done']+1]);
                        } else $this->builds->checkpoint($claim,['chunk_cursor'=>$offset+count($batch)]);
                    });
                    $stats['chunks']+=count($batch);
                    if ($done) { $stats['documents']++; break; }
                } while ($stats['chunks']<256 && microtime(true)<$deadline);
            }
            $this->builds->release($claim);
        } catch (ChatPriorityYieldException) {
            $this->builds->release($claim);$stats['deferred_for_chat']=true;
        } catch (\Throwable $error) {
            $reason=$error instanceof VectorStoreException && preg_match('/^[a-z0-9_]{1,48}$/D',$error->reason) ? $error->reason : 'build_failed';
            if ($reason==='embedding_connection_failed' && microtime(true)>=$deadline) $reason='build_budget';
            // Repeating a source that consumes every tick without progress
            // would waste background capacity indefinitely. Require attention.
            if ($reason==='build_budget' && $stats['chunks']===0 && $stats['documents']===0) $reason='source_extraction_failed';
            if (in_array($reason,['source_changed','invalid_source_snapshot'],true)) {
                // The canonical file or its SQL snapshot changed. Wait for the
                // ordinary indexer to refresh its SQL stamp instead of spinning
                // on the same stale source. A canonical source mutation clears
                // this delay; otherwise a five-minute retry remains available.
                $this->builds->deferChangedSource($claim);
                $stats['deferred_for_source_refresh']=true;
            } else {
                $stats['error']=$reason;
                $this->builds->release($claim,in_array($reason,['build_budget','build_disabled','stale_build_lease'],true)?null:$reason);
            }
        } finally { $this->config->setUserId($previous); }
        return $stats;
    }

    private function guard(array $claim,VectorGeneration $generation,VectorDocumentSnapshot $source,float $deadline): void {
        if (microtime(true)>=$deadline) throw new VectorStoreException('build_budget');
        $this->builds->assertLease($claim,time());
        $current=$this->generations->find($generation->userId,$generation->id);
        if ($current===null || $current->toRow()!==$generation->toRow()) throw new VectorStoreException('generation_identity_mismatch');
        $snapshot=$this->chunks->snapshot($generation->userId,$source->id);
        if ($snapshot===null || $snapshot->stamp!==$source->stamp) throw new VectorStoreException('source_changed');
        $this->access->assertAccessible($generation->userId,['source'=>$source->metadata['source'],'file_id'=>$source->metadata['file_id'],
            'path'=>$source->metadata['path'],'source_mtime'=>$source->metadata['file_mtime'],'source_size'=>$source->metadata['size']]);
    }

    /** Yield shared model capacity to active chats; fail closed if lock state is unavailable. */
    private function chatRequestIsActive(string $userId): bool {
        if($this->lockingProvider===null)return false;
        foreach([LockGuard::chatActivityLockPath(),LockGuard::chatRequestLockPath($userId)] as $path){
            try{$this->lockingProvider->acquireLock($path,ILockingProvider::LOCK_EXCLUSIVE,'EVA generation build chat-priority check');}
            catch(LockedException){return true;}
            catch(\Throwable){return true;}
            try{$this->lockingProvider->releaseLock($path,ILockingProvider::LOCK_EXCLUSIVE);}
            catch(\Throwable){return true;}
        }
        return false;
    }
}
