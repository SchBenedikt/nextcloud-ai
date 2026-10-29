<?php
declare(strict_types=1);
namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorStoreException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Durable, tenant-bound verifier cursor. Never performs network I/O. */
class GenerationVerificationRepository {
    public const LEASE_SECONDS=180;
    public function __construct(private IDBConnection $db,private VectorOutboxRepository $outbox,
        private VectorOwnerFenceRepository $owners,private VectorGenerationRepository $generations,
        private GenerationBuildRepository $builds) {}

    public function find(string $user,string $id): ?array {
        $q=$this->db->getQueryBuilder();$q->select('*')->from('eva_ai_vec_verifications')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        if(!$row)return null;
        foreach(['revision','after_chunk_id','after_document_id','upper_document_id','expected_count','checked_count','sample_count','mismatch_count','lease_until','heartbeat_at','created_at','updated_at'] as$key){
            $row[$key]=(int)$row[$key];if($row[$key]<0)throw new VectorStoreException('invalid_verification_state');
        }
        if($row['remote_count']!==null){$row['remote_count']=(int)$row['remote_count'];if($row['remote_count']<0)throw new VectorStoreException('invalid_verification_state');}
        if($row['owner_hash']!==VectorOwnerFenceRepository::ownerHash($user))throw new VectorStoreException('invalid_verification_state');
        if(!in_array($row['status'],['queued','running','paused','cancelled','failed','verified','stale'],true)
            ||!in_array($row['phase'],['points','sources','complete'],true)
            ||($row['error_code']!==null&&preg_match('/^[a-z0-9_]{1,48}$/D',$row['error_code'])!==1)
            ||($row['verification_hash']!==null&&preg_match('/^[a-f0-9]{64}$/D',$row['verification_hash'])!==1))throw new VectorStoreException('invalid_verification_state');
        return $row;
    }

    public function certificateCurrent(string $user,string $id): bool {
        $q=$this->db->getQueryBuilder();$q->select('verified_at','verification_hash')->from('eva_ai_vec_generations')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('id',$q->createNamedParameter($id)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        $verification=$this->find($user,$id);
        return is_array($row)&&$row['verified_at']!==null&&(int)$row['verified_at']>0
            &&is_string($row['verification_hash'])&&preg_match('/^[a-f0-9]{64}$/D',$row['verification_hash'])===1
            &&$verification!==null&&$verification['status']==='verified'&&hash_equals($row['verification_hash'],(string)$verification['verification_hash']);
    }

    /** Personal export omits leases and target details. */
    public function exportMetadata(string $user): array {
        $items=[];$after='';
        do{
            $q=$this->db->getQueryBuilder();$q->select('generation_id','status','phase','expected_count','checked_count','sample_count',
                'remote_count','heartbeat_at','error_code','verification_hash')->from('eva_ai_vec_verifications')
                ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)));
            if($after!=='')$q->andWhere($q->expr()->gt('generation_id',$q->createNamedParameter($after)));
            $q->orderBy('generation_id','ASC')->setMaxResults(100);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
            foreach($rows as$row){$items[]=$row;$after=$row['generation_id'];}
        }while(count($rows)===100);
        return $items;
    }

    public function start(string $user,string $id): array {
        $target=$this->generations->target($user,$id)??throw new VectorStoreException('generation_unprepared');
        return $this->outbox->transaction(function()use($user,$id,$target):array{
            $this->lockGeneration($user,$id,$target);
            if(!$this->builds->available($user,$id,$target))throw new VectorStoreException('build_disabled');
            $build=$this->builds->find($user,$id);
            if($build===null||$build['status']!=='staged')throw new VectorStoreException('build_not_staged');
            $existing=$this->find($user,$id);
            if($existing!==null&&in_array($existing['status'],['queued','running','verified'],true))return $existing;
            $expected=$this->expectedCount($user,$id);
            $values=['generation_id'=>$id,'user_id'=>$user,'owner_hash'=>VectorOwnerFenceRepository::ownerHash($user),'status'=>'queued','phase'=>'points','revision'=>1,'after_chunk_id'=>0,
                'after_document_id'=>0,'upper_document_id'=>$build['upper_document_id'],
                'expected_count'=>$expected,'checked_count'=>0,'sample_count'=>0,'mismatch_count'=>0,'remote_count'=>null,
                'lease_until'=>0,'heartbeat_at'=>0,'created_at'=>time(),'updated_at'=>time(),'verification_hash'=>hash('sha256','eva_ai-verification-v1:'.$id)];
            $this->db->insertIgnoreConflict('eva_ai_vec_verifications',$values);
            if($existing!==null){
                $this->patch($user,$id,['status'=>'queued','phase'=>'points','after_chunk_id'=>0,'after_document_id'=>0,
                    'upper_document_id'=>$build['upper_document_id'],'expected_count'=>$expected,'checked_count'=>0,'sample_count'=>0,
                    'mismatch_count'=>0,'remote_count'=>null,'lease_token'=>null,'lease_until'=>0,'error_code'=>null,'verification_hash'=>$values['verification_hash']]);
            }
            return $this->find($user,$id)??throw new VectorStoreException('verification_unavailable');
        });
    }

    public function control(string $user,string $id,string $operation): array {
        if(!in_array($operation,['pause','resume','cancel','retry'],true))throw new \InvalidArgumentException('Invalid verification control.');
        return $this->outbox->transaction(function()use($user,$id,$operation):array{
            $row=$this->find($user,$id)??throw new VectorStoreException('verification_missing');
            $target=$this->generations->target($user,$id)??throw new VectorStoreException('generation_unprepared');
            $this->lockGeneration($user,$id,$target);$this->lockRow($user,$id);
            $row=$this->find($user,$id)??throw new VectorStoreException('verification_missing');
            if($operation==='resume'||$operation==='retry'){
                if(!$this->builds->available($user,$id,$target))throw new VectorStoreException('build_disabled');
                if($row['status']==='verified')return $row;
                if($operation==='retry')return $this->reset($user,$id,$row['expected_count']);
            }
            if($row['status']==='verified')return $row;
            $status=match($operation){'pause'=>'paused','cancel'=>'cancelled',default=>'queued'};
            $this->patch($user,$id,['status'=>$status,'lease_token'=>null,'lease_until'=>0,'error_code'=>null]);
            return $this->find($user,$id);
        });
    }

    /** One expiring CAS lease; workers never hold SQL transactions over HTTP. */
    public function claim(int $now): ?array {
        if(!$this->outbox->pointsEnabled())return null;
        for($try=0;$try<8;$try++){
            $q=$this->db->getQueryBuilder();$q->select('v.user_id','v.generation_id','v.revision','v.upper_document_id','g.storage_endpoint_hash')
                ->from('eva_ai_vec_verifications','v')
                ->innerJoin('v','eva_ai_vec_generations','g',$q->expr()->eq('g.id','v.generation_id'))
                ->innerJoin('v','eva_ai_vec_builds','b',$q->expr()->eq('b.generation_id','v.generation_id'))
                ->innerJoin('v','eva_ai_vec_owners','o',$q->expr()->eq('o.owner_hash','v.owner_hash'))
                ->where($q->expr()->eq('g.status',$q->createNamedParameter('building')))
                ->andWhere($q->expr()->eq('b.status',$q->createNamedParameter('staged')))
                ->andWhere($q->expr()->eq('b.user_id','v.user_id'))
                ->andWhere($q->expr()->eq('g.user_id','v.user_id'))
                ->andWhere($q->expr()->eq('o.state',$q->createNamedParameter('open')))
                ->andWhere($q->expr()->orX($q->expr()->eq('v.status',$q->createNamedParameter('queued')),
                    $q->expr()->andX($q->expr()->eq('v.status',$q->createNamedParameter('running')),$q->expr()->lte('v.lease_until',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))))
                ->orderBy('v.updated_at','ASC')->addOrderBy('v.generation_id','ASC')->setMaxResults(1);
            $r=$q->executeQuery();try{$candidate=$r->fetch();}finally{$r->closeCursor();}
            if(!$candidate)return null;
            $claim=$this->outbox->transaction(function()use($candidate,$now):?array{
                $user=$candidate['user_id'];$id=$candidate['generation_id'];$target=$candidate['storage_endpoint_hash'];
                if($target===null)return null;
                $this->lockGeneration($user,$id,$target);$this->lockRow($user,$id);$row=$this->find($user,$id);
                if($row===null||$row['revision']!==(int)$candidate['revision']+1
                    ||!($row['status']==='queued'||($row['status']==='running'&&$row['lease_until']<=$now))
                    )return null;
                if(!$this->builds->available($user,$id,$target)){
                    $this->patch($user,$id,['status'=>'failed','error_code'=>'build_disabled','lease_token'=>null,'lease_until'=>0]);
                    return null;
                }
                $this->patch($user,$id,['status'=>'running','lease_token'=>bin2hex(random_bytes(16)),'lease_until'=>$now+self::LEASE_SECONDS,'heartbeat_at'=>$now]);
                $claimed=$this->find($user,$id);$claimed['target_hash']=$target;return $claimed;
            });
            if($claim!==null)return $claim;
        }
        return null;
    }

    /** @return list<array{id:int,document_id:int,vector_revision:int,embedding:string,content_hash:string,source:string}> */
    public function page(array $claim,int $limit=100): array {
        $q=$this->db->getQueryBuilder();$q->select('c.id','c.document_id','c.vector_revision','c.embedding','c.content_hash','d.source')->from('eva_ai_chunks','c')
            ->innerJoin('c','eva_ai_documents','d',$q->expr()->eq('d.id','c.document_id'))
            ->where($q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($claim['generation_id'])))
            ->andWhere($q->expr()->eq('d.user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->gt('c.id',$q->createNamedParameter($claim['after_chunk_id'],IQueryBuilder::PARAM_INT)))
            ->orderBy('c.id','ASC')->setMaxResults(max(1,min(100,$limit)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as&$row){foreach(['id','document_id','vector_revision']as$key)$row[$key]=(int)$row[$key];}unset($row);
        return $rows;
    }

    /** Current source metadata, build manifest and generation chunk count. */
    public function sourcePage(array $claim,int $limit=100): array {
        $q=$this->db->getQueryBuilder();
        $q->select('d.id','d.user_id','d.file_id','d.path','d.name','d.source','d.content_hash','d.file_mtime','d.size','d.mime',
            'm.source_stamp','m.chunk_count')->selectAlias($q->createFunction('COUNT(c.id)'),'current_chunks')
            ->from('eva_ai_documents','d')
            ->leftJoin('d','eva_ai_vec_build_docs','m',$q->expr()->andX(
                $q->expr()->eq('m.document_id','d.id'),$q->expr()->eq('m.generation_id',$q->createNamedParameter($claim['generation_id']))))
            ->leftJoin('d','eva_ai_chunks','c',$q->expr()->andX(
                $q->expr()->eq('c.document_id','d.id'),$q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($claim['generation_id']))))
            ->where($q->expr()->eq('d.user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->lte('d.id',$q->createNamedParameter($claim['upper_document_id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('d.id',$q->createNamedParameter($claim['after_document_id'],IQueryBuilder::PARAM_INT)))
            ->groupBy('d.id','d.user_id','d.file_id','d.path','d.name','d.source','d.content_hash','d.file_mtime','d.size','d.mime','m.source_stamp','m.chunk_count')
            ->orderBy('d.id','ASC')->setMaxResults(max(1,min(100,$limit)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as&$row){$row['id']=(int)$row['id'];$row['current_chunks']=(int)$row['current_chunks'];
            $row['chunk_count']=$row['chunk_count']===null?null:(int)$row['chunk_count'];}unset($row);
        return $rows;
    }

    public function checkpoint(array $claim,array $values): void {
        if(!$this->db->inTransaction())throw new \LogicException('Verification checkpoint requires transaction.');
        $this->assertLease($claim,time());
        $allowed=['after_chunk_id','after_document_id','phase','checked_count','sample_count','mismatch_count','verification_hash','status','error_code','lease_token','lease_until','remote_count'];
        if(array_diff(array_keys($values),$allowed)!==[])throw new \InvalidArgumentException('Invalid verification checkpoint.');
        $this->patch($claim['user_id'],$claim['generation_id'],$values+['heartbeat_at'=>time(),'lease_until'=>time()+self::LEASE_SECONDS]);
    }

    public function withLease(array $claim,callable $change): mixed {
        return $this->outbox->transaction(function()use($claim,$change):mixed{
            $target=$claim['target_hash'];$this->lockGeneration($claim['user_id'],$claim['generation_id'],$target);
            $this->lockRow($claim['user_id'],$claim['generation_id']);$row=$this->assertLease($claim,time());
            return $change($row);
        });
    }

    public function assertLease(array $claim,int $now): array {
        $row=$this->find($claim['user_id'],$claim['generation_id']);
        if($row===null||$row['status']!=='running'||$row['lease_token']!==$claim['lease_token']||$row['lease_until']<=$now)throw new VectorStoreException('stale_verification_lease');
        if(!$this->builds->available($claim['user_id'],$claim['generation_id'],$claim['target_hash']))throw new VectorStoreException('build_disabled');
        return $row;
    }

    public function expectedCount(string $user,string $id): int {
        $q=$this->db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_chunks','c')
            ->innerJoin('c','eva_ai_documents','d',$q->expr()->eq('d.id','c.document_id'))
            ->where($q->expr()->eq('c.vector_generation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('d.user_id',$q->createNamedParameter($user)));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }

    public function currentDocumentCount(string $user,int $upperId): int {
        $q=$this->db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_documents')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->lte('id',$q->createNamedParameter($upperId,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }
    public function documentsBeyondSnapshot(string $user,int $upperId): int {
        $q=$this->db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_documents')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->gt('id',$q->createNamedParameter($upperId,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }

    public function markVerified(array $claim,int $remoteCount): void {
        if($remoteCount<0)throw new \InvalidArgumentException('Invalid verification count.');
        $this->withLease($claim,function(array $row)use($claim,$remoteCount):void{
            if($row['phase']!=='complete'||$row['checked_count']!==$row['expected_count'])throw new VectorStoreException('verification_incomplete');
            $q=$this->db->getQueryBuilder();$q->select('status')->from('eva_ai_vec_builds')
                ->where($q->expr()->eq('user_id',$q->createNamedParameter($claim['user_id'])))
                ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($claim['generation_id'])));
            $r=$q->executeQuery();try{$buildStatus=$r->fetchOne();}finally{$r->closeCursor();}
            if($buildStatus!=='staged'||$remoteCount!==$row['expected_count']||$this->expectedCount($claim['user_id'],$claim['generation_id'])!==$row['expected_count']
                ||$this->documentsBeyondSnapshot($claim['user_id'],$row['upper_document_id'])>0)
                throw new VectorStoreException('remote_count_mismatch');
            $hash=hash('sha256',$row['verification_hash'].':expected:'.$row['expected_count'].':remote:'.$remoteCount.':samples:'.$row['sample_count']);
            $gen=$this->db->getQueryBuilder();$gen->update('eva_ai_vec_generations')
                ->set('verified_at',$gen->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
                ->set('verification_hash',$gen->createNamedParameter($hash))
                ->where($gen->expr()->eq('user_id',$gen->createNamedParameter($claim['user_id'])))
                ->andWhere($gen->expr()->eq('id',$gen->createNamedParameter($claim['generation_id'])))
                ->andWhere($gen->expr()->eq('status',$gen->createNamedParameter('building')))->executeStatement();
            $this->checkpoint($claim,['phase'=>'complete','status'=>'verified','remote_count'=>$remoteCount,'verification_hash'=>$hash,
                'lease_token'=>null,'lease_until'=>0,'error_code'=>null]);
        });
    }

    public function release(array $claim,?string $error=null): void {
        if($error!==null&&preg_match('/^[a-z0-9_]{1,48}$/D',$error)!==1)throw new \InvalidArgumentException('Invalid verification error code.');
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_verifications')
            ->set('status',$q->createNamedParameter($error===null?'queued':'failed'))
            ->set('error_code',$q->createNamedParameter($error,$error===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR))
            ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($claim['generation_id'])))
            ->andWhere($q->expr()->eq('status',$q->createNamedParameter('running')))
            ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))->executeStatement();
    }

    private function reset(string $user,string $id,int $expected): array {
        $build=$this->builds->find($user,$id);
        $this->patch($user,$id,['status'=>'queued','phase'=>'points','after_chunk_id'=>0,'after_document_id'=>0,
            'upper_document_id'=>$build['upper_document_id']??0,'expected_count'=>$expected,'checked_count'=>0,'sample_count'=>0,
            'mismatch_count'=>0,'remote_count'=>null,'lease_token'=>null,'lease_until'=>0,'error_code'=>null,
            'verification_hash'=>hash('sha256','eva_ai-verification-v1:'.$id)]);
        return $this->find($user,$id);
    }
    private function lockGeneration(string $user,string $id,string $target): void {
        $this->owners->lockOpen($user);$q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_generations')
            ->set('build_cursor',$q->func()->add('build_cursor',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        if(!$this->generations->isBuildingBound($user,$id,$target))throw new VectorStoreException('generation_not_building');
    }
    private function lockRow(string $user,string $id): void {
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_verifications')->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)))->executeStatement();
    }
    private function patch(string $user,string $id,array $values): void {
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_verifications')
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)));
        foreach($values as$key=>$value)$q->set($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:($value===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR)));
        $q->executeStatement();
    }
}
