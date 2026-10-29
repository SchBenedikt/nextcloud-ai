<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationBuildRepository,GenerationChunkRepository,GenerationVerificationRepository,VectorGenerationRepository,VectorOutboxRepository};
use OCA\EvaAi\Service\{AppConfig,ProviderException};
use OCP\Lock\{ILockingProvider,LockedException};
use Psr\Log\LoggerInterface;

/** Explicit administrator-only, read-only comparison; never called by chat. */
final class GenerationShadowService {
    public function __construct(private AppConfig $config,private GenerationBuildRepository $builds,
        private GenerationChunkRepository $chunks,private GenerationEmbeddingProvider $embeddings,
        private GenerationVerificationRepository $verifications,private VectorGenerationRepository $generations,
        private VectorOutboxRepository $outbox,private VectorSourceAccess $access,private QdrantVectorStore $store,
        private ILockingProvider $locks,private LoggerInterface $logger) {}

    /** @return array<string,int|float|bool|null> */
    public function compare(string $user,string $id,string $query,int $topK=10,?string $scopePath=null): array {
        if($query===''||strlen($query)>8192||!mb_check_encoding($query,'UTF-8')||trim($query)===''||$topK<1||$topK>20)
            throw new VectorStoreException('invalid_shadow_query');
        $filters=new SearchFilters($scopePath);
        $this->access->assertOwner($user);
        $generation=$this->generations->find($user,$id)??throw new VectorStoreException('generation_missing');
        if($generation->backend!=='qdrant')throw new VectorStoreException('shadow_backend_unsupported');
        if(mb_strlen($query)>$generation->chunkSize)throw new VectorStoreException('shadow_query_too_long');
        $target=$this->generations->target($user,$id);
        if($target===null||!$this->outbox->pointsEnabled()||!$this->builds->available($user,$id,$target)
            ||!$this->verifications->certificateCurrent($user,$id))throw new VectorStoreException('shadow_prerequisites_missing');
        $lock='eva_ai/shadow/'.substr(hash('sha256',$id),0,40);$locked=false;$previousUser=$this->config->userId();$started=hrtime(true);
        try{
            try{$this->locks->acquireLock($lock,ILockingProvider::LOCK_EXCLUSIVE,'EVA generation shadow probe');$locked=true;}
            catch(LockedException){throw new VectorStoreException('shadow_busy');}
            $this->config->setUserId($user);
            $guard=function()use($user,$id,$target,$generation,$started):void{
                if((hrtime(true)-$started)/1_000_000_000>12)throw new VectorStoreException('shadow_budget_exceeded');
                if(!$this->outbox->pointsEnabled()||!$this->builds->available($user,$id,$target)
                    ||$this->generations->find($user,$id)?->toRow()!==$generation->toRow()
                    ||$this->generations->target($user,$id)!==$target||!$this->verifications->certificateCurrent($user,$id))
                    throw new VectorStoreException('shadow_prerequisites_changed');
            };
            $queryStart=hrtime(true);
            $queryVector=$this->embeddings->embed($generation,[$query],$guard,true,3)[0];
            $embeddingMs=(int)round((hrtime(true)-$queryStart)/1_000_000);$guard();
            $sql=$this->chunks->shadowDenseRanking($generation,$queryVector,$filters,$topK,20000,3.0);
            $store=$this->store->forTarget($target,$guard);$remoteStart=hrtime(true);
            $remote=$store->search($generation,$queryVector,$topK,$filters,2);
            $qdrantMs=(int)round((hrtime(true)-$remoteStart)/1_000_000);$guard();
            $ids=array_column($remote,'chunkId');$revisions=$this->chunks->shadowRevisions($generation,$ids);
            foreach($remote as$point)if(($revisions[$point['chunkId']]??null)!==$point['revision'])throw new VectorStoreException('shadow_stale_point');
            $sqlIds=array_column($sql['points'],'id');$remoteIds=$ids;$intersection=count(array_intersect($sqlIds,$remoteIds));
            $metrics=['sql_candidates'=>count($sqlIds),'vector_candidates'=>count($remoteIds),'overlap_at_k'=>$intersection,
                'recall_at_k'=>$sqlIds===[]?null:round($intersection/count($sqlIds),4),
                'precision_at_k'=>$remoteIds===[]?null:round($intersection/count($remoteIds),4),
                'top_result_matches'=>$sqlIds!==[]&&$remoteIds!==[]&&$sqlIds[0]===$remoteIds[0],
                'sql_scanned'=>$sql['scanned'],'sql_ms'=>$sql['duration_ms'],'embedding_ms'=>$embeddingMs,'vector_ms'=>$qdrantMs,
                'retrieval_changed'=>false];
            $guard();
            try{$this->logger->info('eva_ai: generation shadow comparison',$metrics+['generation_id'=>$id]);}catch(\Throwable){}
            return $metrics;
        }catch(VectorStoreException$error){
            try{$this->logger->info('eva_ai: generation shadow comparison stopped',['generation_id'=>$id,'reason'=>$error->reason]);}catch(\Throwable){}
            throw$error;
        }catch(\Throwable$error){
            $reason=$error instanceof ProviderException?'shadow_embedding_unavailable':'shadow_unavailable';
            try{$this->logger->info('eva_ai: generation shadow comparison stopped',['generation_id'=>$id,'reason'=>$reason]);}catch(\Throwable){}
            throw new VectorStoreException($reason);
        }finally{
            $this->config->setUserId($previousUser);
            if($locked)try{$this->locks->releaseLock($lock,ILockingProvider::LOCK_EXCLUSIVE);}
            catch(\Throwable){try{$this->logger->warning('eva_ai: generation shadow lock release failed',['generation_id'=>$id]);}catch(\Throwable){}}
        }
    }
}
