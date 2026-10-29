<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationVerificationRepository,VectorGenerationRepository};

/** Bounded read-only reconciliation of staged SQL vectors against Qdrant. */
class GenerationVerificationWorker {
    private const TICK_SECONDS=90;
    private const MAX_BATCHES=20;
    private const COSINE_MIN=0.99999;

    public function __construct(private GenerationVerificationRepository $verifications,private VectorGenerationRepository $generations,
        private QdrantVectorStore $store) {}

    /** One fenced lease, bounded SQL keyset pages and remote reads; never writes or activates points. */
    public function run(): array {
        $claim=$this->verifications->claim(time());
        $stats=['claimed'=>$claim!==null,'checked'=>0,'sampled'=>0,'verified'=>false,'requeued'=>false,'error'=>null];
        if($claim===null)return $stats;
        $deadline=microtime(true)+self::TICK_SECONDS;
        try {
            $generation=$this->generations->find($claim['user_id'],$claim['generation_id'])??throw new VectorStoreException('generation_missing');
            $guard=function()use($claim,$generation,$deadline):void{
                if(microtime(true)>=$deadline)throw new VectorStoreException('verification_budget');
                $this->verifications->assertLease($claim,time());
                $current=$this->generations->find($generation->userId,$generation->id);
                if($current===null||$current->toRow()!==$generation->toRow())throw new VectorStoreException('generation_identity_mismatch');
            };
            $store=$generation->backend==='qdrant'?$this->store->forTarget($claim['target_hash'],$guard):null;
            for($batchNo=0;$batchNo<self::MAX_BATCHES&&microtime(true)<$deadline;$batchNo++){
                $guard();$state=$this->verifications->find($claim['user_id'],$claim['generation_id']);
                if($state===null||$state['status']!=='running'||$state['lease_token']!==$claim['lease_token'])throw new VectorStoreException('stale_verification_lease');
                if($state['phase']==='complete'){
                    if($this->verifications->documentsBeyondSnapshot($claim['user_id'],$state['upper_document_id'])>0
                        ||$this->verifications->expectedCount($claim['user_id'],$claim['generation_id'])!==$state['expected_count'])throw new VectorStoreException('sql_inventory_changed');
                    if($generation->backend==='qdrant'){
                        $count=$store?->countLive($generation);$guard();
                        if(!is_int($count))throw new VectorStoreException('remote_count_unavailable');
                        if($count!==$state['expected_count'])throw new VectorStoreException('remote_count_mismatch');
                        if($generation->includeTextPayload){
                            $missingText=$store?->countMissingTextPayload($generation);$guard();
                            if(!is_int($missingText)||$missingText!==0)throw new VectorStoreException('remote_text_payload_missing');
                        }
                    }else{
                        $count=$this->verifications->expectedCount($claim['user_id'],$claim['generation_id']);
                        if($count!==$state['expected_count']||$state['checked_count']!==$state['expected_count'])throw new VectorStoreException('sql_inventory_changed');
                    }
                    $this->verifications->markVerified($claim,$count);
                    $stats['verified']=true;return $stats;
                }
                if($state['phase']==='points'){
                    $limit=max(1,min(100,intdiv(80000,$generation->dimension)));
                    $rows=$this->verifications->page($state,$limit);
                    if($rows===[]){
                        if($state['checked_count']!==$state['expected_count']||$this->verifications->expectedCount($claim['user_id'],$claim['generation_id'])!==$state['expected_count'])
                            throw new VectorStoreException('sql_inventory_changed');
                        $this->verifications->withLease($claim,fn()=> $this->verifications->checkpoint($claim,['phase'=>'sources']));
                        continue;
                    }
                    $points=$generation->backend==='qdrant'?$store?->retrieve($generation,array_column($rows,'id'),
                        !$generation->includeTextPayload,!$generation->includeTextPayload):null;
                    $hash=$state['verification_hash'];$sampled=0;
                    foreach($rows as$row){
                        $expected=VectorEmbeddingCodec::decode((string)$row['embedding'],$generation->dimension);
                        if(!is_array($expected))throw new VectorStoreException('invalid_sql_vector');
                        try{$generation->validateVector($expected);}catch(\Throwable){throw new VectorStoreException('invalid_sql_vector');}
                        if($generation->backend==='qdrant'){
                            $point=$points[$row['id']]??null;
                            if($point===null)throw new VectorStoreException('remote_point_missing');
                            $payload=$point['payload'];
                            if($payload['deleted']!==false||($payload['document_id']??null)!==$row['document_id']
                                ||($payload['revision']??null)!==$row['vector_revision']||($payload['source']??null)!==$row['source']
                                ||($payload['content_hash']??null)!==$row['content_hash'])throw new VectorStoreException('remote_payload_mismatch');
                            if($state['checked_count']===0&&$stats['checked']===0||((int)$row['id']%64)===0){
                                $samplePoint=$generation->includeTextPayload
                                    ?($store?->retrieve($generation,[(int)$row['id']])[$row['id']]??null):$point;
                                if($samplePoint===null)throw new VectorStoreException('remote_point_missing');
                                if($generation->includeTextPayload&&(!is_string($samplePoint['payload']['content']??null)
                                    ||!hash_equals((string)$row['content'],$samplePoint['payload']['content'])
                                    ||!hash_equals((string)$row['content_hash'],hash('sha256',$samplePoint['payload']['content']))))
                                    throw new VectorStoreException('remote_text_payload_mismatch');
                                if(self::cosine($expected,$samplePoint['vector'])<self::COSINE_MIN)throw new VectorStoreException('remote_vector_mismatch');
                                $sampled++;
                            }
                        }else{
                            if((int)$row['vector_revision']<1)throw new VectorStoreException('invalid_sql_revision');
                            if($row['vector_state']!=='ready')throw new VectorStoreException('sql_point_not_ready');
                            if(strlen((string)$row['content'])>40000||!hash_equals((string)$row['content_hash'],hash('sha256',(string)$row['content'])))
                                throw new VectorStoreException('invalid_sql_chunk');
                            $sampled++;
                        }
                        $hash=hash('sha256',$hash.':'.$row['id'].':'.$row['document_id'].':'.$row['vector_revision'].':'.$row['content_hash'].':'.hash('sha256',json_encode($expected,JSON_THROW_ON_ERROR)));
                    }
                    $last=$rows[count($rows)-1]['id'];$checked=count($rows);
                    $this->verifications->withLease($claim,function(array$current)use($claim,$last,$checked,$sampled,$hash):void{
                        if($current['phase']!=='points')throw new VectorStoreException('stale_verification_lease');
                        $this->verifications->checkpoint($claim,['after_chunk_id'=>$last,'checked_count'=>$current['checked_count']+$checked,
                            'sample_count'=>$current['sample_count']+$sampled,'verification_hash'=>$hash]);
                    });
                    $stats['checked']+=$checked;$stats['sampled']+=$sampled;
                    continue;
                }
                if($state['phase']==='sources'){
                    $rows=$this->verifications->sourcePage($state,100);
                    if($rows===[]){
                        if($this->verifications->documentsBeyondSnapshot($claim['user_id'],$state['upper_document_id'])>0){
                            $requeued=$this->verifications->withLease($claim,function()use($claim,$state):bool{
                                if($this->verifications->documentsBeyondSnapshot($claim['user_id'],$state['upper_document_id'])===0)return false;
                                if(!$this->verifications->requeueStagedBuildForLateSources($claim))
                                    throw new VectorStoreException('new_sources_after_snapshot');
                                return true;
                            });
                            if($requeued){$stats['requeued']=true;return $stats;}
                        }
                        $this->verifications->withLease($claim,fn()=> $this->verifications->checkpoint($claim,['phase'=>'complete']));
                        $state=$this->verifications->find($claim['user_id'],$claim['generation_id']);
                        continue;
                    }
                    foreach($rows as$row){
                        $snapshot=VectorDocumentSnapshot::fromRow($row);
                        if($row['source_stamp']===null||!hash_equals($row['source_stamp'],$snapshot->stamp)
                            ||$row['chunk_count']===null||$row['chunk_count']!==$row['current_chunks'])throw new VectorStoreException('source_reconciliation_required');
                    }
                    $last=$rows[count($rows)-1]['id'];$hash=$state['verification_hash'];
                    foreach($rows as$row)$hash=hash('sha256',$hash.':source:'.$row['id'].':'.$row['source_stamp'].':'.$row['chunk_count']);
                    $this->verifications->withLease($claim,fn(array$current)=>$this->verifications->checkpoint($claim,
                        ['after_document_id'=>$last,'verification_hash'=>$hash]));
                    continue;
                }
                return $stats;
            }
            $this->verifications->release($claim);
        }catch(\Throwable$error){
            $reason=$error instanceof VectorStoreException&&preg_match('/^[a-z0-9_]{1,48}$/D',$error->reason)?$error->reason:'verification_failed';
            $stats['error']=$reason;
            $this->verifications->release($claim,in_array($reason,['verification_budget','build_disabled','stale_verification_lease'],true)?null:$reason);
        }
        return $stats;
    }

    private static function cosine(array$a,array$b):float {
        if(count($a)!==count($b)||$a===[])throw new VectorStoreException('point_dimension_mismatch');
        $dot=0.0;$aa=0.0;$bb=0.0;
        foreach($a as$i=>$x){$y=$b[$i]??null;if(!is_int($x)&&!is_float($x)||!is_int($y)&&!is_float($y)||!is_finite((float)$x)||!is_finite((float)$y))throw new VectorStoreException('invalid_point_vector');
            $dot+=$x*$y;$aa+=$x*$x;$bb+=$y*$y;}
        if($aa<=0||$bb<=0)return 0.0;return $dot/sqrt($aa*$bb);
    }
}
