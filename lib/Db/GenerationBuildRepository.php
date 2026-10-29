<?php
declare(strict_types=1);
namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\{VectorGeneration, VectorStoreException};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Owner -> generation -> build -> source lock order; never performs external I/O. */
class GenerationBuildRepository {
    public const LEASE_SECONDS = 180;

    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox,
        private VectorOwnerFenceRepository $owners, private VectorGenerationRepository $generations) {}

    public function available(string $user, string $id, string $target): bool {
        return $this->indexAllowed($user) && $this->outbox->pointsEnabled() && $this->outbox->pointTargetIsCurrent($target)
            && $this->generations->isBuildingBound($user, $id, $target);
    }

    /** Explicit personal opt-out wins over retained documents and request caches. */
    public function indexAllowed(string $user): bool {
        $q=$this->db->getQueryBuilder();$q->select('configvalue')->from('preferences')
            ->where($q->expr()->eq('userid',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->eq('appid',$q->createNamedParameter('eva_ai')))
            ->andWhere($q->expr()->eq('configkey',$q->createNamedParameter('index_enrolled')));
        $r=$q->executeQuery();try{$value=$r->fetchOne();}finally{$r->closeCursor();}
        return $value===false || $value==='1';
    }

    public function find(string $user, string $id): ?array {
        $q=$this->db->getQueryBuilder();
        $q->select('*')->from('eva_ai_vec_builds')->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)));
        $r=$q->executeQuery(); try { $row=$r->fetch(); } finally { $r->closeCursor(); }
        if (!$row) return null;
        foreach (['revision','upper_document_id','after_document_id','document_id','chunk_cursor','documents_done','total_documents',
            'lease_until','heartbeat_at','created_at','updated_at'] as $key) {
            $row[$key]=(int)$row[$key];
            if ($row[$key]<0) throw new VectorStoreException('invalid_build_state');
        }
        if (!in_array($row['status'],['queued','running','paused','cancelled','failed','staged'],true)) throw new VectorStoreException('invalid_build_state');
        if ($row['error_code']!==null && preg_match('/^[a-z0-9_]{1,48}$/D',$row['error_code'])!==1) throw new VectorStoreException('invalid_build_state');
        return $row;
    }

    public function start(string $user, string $id, string $target): array {
        return $this->outbox->transaction(function()use($user,$id,$target):array {
            $this->lockGeneration($user,$id,$target);
            if (!$this->available($user,$id,$target)) throw new VectorStoreException('build_disabled');
            $existing=$this->find($user,$id);
            if ($existing!==null) return $existing; // Idempotent; never reset progress.
            $q=$this->db->getQueryBuilder();
            $q->selectAlias($q->createFunction('MAX(id)'),'last_id')->selectAlias($q->createFunction('COUNT(*)'),'total')
                ->from('eva_ai_documents')->where($q->expr()->eq('user_id',$q->createNamedParameter($user)));
            $r=$q->executeQuery();try{$source=$r->fetch();}finally{$r->closeCursor();}
            $values=['generation_id'=>$id,'user_id'=>$user,'owner_hash'=>VectorOwnerFenceRepository::ownerHash($user),'target_hash'=>$target,
                'status'=>'queued','revision'=>1,'upper_document_id'=>(int)($source['last_id']??0),'total_documents'=>(int)($source['total']??0),
                'created_at'=>time(),'updated_at'=>time()];
            $this->db->insertIgnoreConflict('eva_ai_vec_builds',$values);
            return $this->find($user,$id) ?? throw new VectorStoreException('build_unavailable');
        });
    }

    public function control(string $user,string $id,string $action): array {
        if (!in_array($action,['pause','resume','cancel'],true)) throw new \InvalidArgumentException('Invalid build control.');
        return $this->outbox->transaction(function()use($user,$id,$action):array {
            $row=$this->find($user,$id) ?? throw new VectorStoreException('build_missing');
            $this->lockGeneration($user,$id,$row['target_hash']);
            $this->lockRow($user,$id);
            $row=$this->find($user,$id) ?? throw new VectorStoreException('build_missing');
            if ($row['status']==='staged') return $row;
            if ($action==='resume') {
                if (!$this->available($user,$id,$row['target_hash'])) throw new VectorStoreException('build_disabled');
                if (in_array($row['status'],['queued','running'],true)) return $row;
            }
            $this->patch($user,$id,['status'=>match($action){'pause'=>'paused','cancel'=>'cancelled',default=>'queued'},
                'lease_token'=>null,'lease_until'=>0,'error_code'=>null]);
            return $this->find($user,$id);
        });
    }

    /** At most one valid token; an expired process cannot checkpoint its successor. */
    public function claim(int $now): ?array {
        if (!$this->outbox->pointsEnabled()) return null;
        for($attempt=0;$attempt<8;$attempt++) {
            $q=$this->db->getQueryBuilder();
            $q->select('b.user_id','b.generation_id','b.target_hash','b.revision')->from('eva_ai_vec_builds','b')
                ->innerJoin('b','eva_ai_vec_generations','g',$q->expr()->eq('b.generation_id','g.id'))
                ->innerJoin('b','eva_ai_vec_owners','o',$q->expr()->eq('b.owner_hash','o.owner_hash'))
                ->where($q->expr()->eq('g.status',$q->createNamedParameter('building')))
                ->andWhere($q->expr()->eq('g.user_id','b.user_id'))
                ->andWhere($q->expr()->eq('o.state',$q->createNamedParameter('open')))
                ->andWhere($q->expr()->orX($q->expr()->eq('b.status',$q->createNamedParameter('queued')),
                    $q->expr()->andX($q->expr()->eq('b.status',$q->createNamedParameter('running')),
                        $q->expr()->lte('b.lease_until',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))))
                ->orderBy('b.updated_at','ASC')->addOrderBy('b.generation_id','ASC')->setMaxResults(1);
            $r=$q->executeQuery();try{$candidate=$r->fetch();}finally{$r->closeCursor();}
            if (!$candidate) return null;
            $claim=$this->outbox->transaction(function()use($candidate,$now):?array {
                $user=$candidate['user_id'];$id=$candidate['generation_id'];
                $this->lockGeneration($user,$id,$candidate['target_hash']);
                $this->lockRow($user,$id);
                $row=$this->find($user,$id);
                // lockRow increments once; no other control/checkpoint may intervene.
                if ($row===null || $row['revision']!==(int)$candidate['revision']+1
                    || !($row['status']==='queued' || ($row['status']==='running' && $row['lease_until']<=$now))) return null;
                if (!$this->available($user,$id,$row['target_hash'])) {
                    if ($this->outbox->pointsEnabled()) $this->patch($user,$id,['status'=>'failed','error_code'=>$this->indexAllowed($user)?'build_target_changed':'owner_index_paused','lease_token'=>null,'lease_until'=>0]);
                    return null;
                }
                $this->patch($user,$id,['status'=>'running','lease_token'=>bin2hex(random_bytes(16)),
                    'lease_until'=>$now+self::LEASE_SECONDS,'heartbeat_at'=>$now]);
                return $this->find($user,$id);
            });
            if ($claim!==null) return $claim;
        }
        return null;
    }

    /** Fresh SQL guard for provider transfers; no transaction or native source I/O. */
    public function assertLease(array $claim,int $now): array {
        $row=$this->find($claim['user_id'],$claim['generation_id']);
        if ($row===null || $row['status']!=='running' || $row['lease_token']!==$claim['lease_token'] || $row['lease_until']<=$now) throw new VectorStoreException('stale_build_lease');
        if (!$this->indexAllowed($claim['user_id'])) throw new VectorStoreException('owner_index_paused');
        if (!$this->available($claim['user_id'],$claim['generation_id'],$claim['target_hash'])) throw new VectorStoreException('build_disabled');
        return $row;
    }

    public function withLease(array $claim,callable $mutation): mixed {
        return $this->outbox->transaction(function()use($claim,$mutation):mixed {
            $this->lockGeneration($claim['user_id'],$claim['generation_id'],$claim['target_hash']);
            $this->lockRow($claim['user_id'],$claim['generation_id']);
            $row=$this->assertLease($claim,time());
            return $mutation($row);
        });
    }

    /** Internal checkpoint; caller must hold withLease(). */
    public function checkpoint(array $claim,array $values): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Build checkpoint requires its mutation transaction.');
        $this->assertLease($claim,time());
        $allowed=['after_document_id','document_id','chunk_cursor','documents_done','plan_hash','status','error_code','lease_token','lease_until','heartbeat_at'];
        if (array_diff(array_keys($values),$allowed)!==[]) throw new \InvalidArgumentException('Invalid build checkpoint fields.');
        $this->patch($claim['user_id'],$claim['generation_id'],$values+['heartbeat_at'=>time(),'lease_until'=>time()+self::LEASE_SECONDS]);
        if (isset($values['after_document_id'])) {
            $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_generations')
                ->set('build_cursor',$q->createNamedParameter($values['after_document_id'],IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',$q->createNamedParameter($claim['generation_id'])))
                ->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($claim['user_id'])))->executeStatement();
        }
    }

    public function release(array $claim,?string $error=null): void {
        if ($error!==null && preg_match('/^[a-z0-9_]{1,48}$/D',$error)!==1) throw new \InvalidArgumentException('Invalid build failure code.');
        // A pause/erase/disable may already have invalidated this process. Never
        // overwrite a successor or require transport permission merely to stop.
        $q=$this->db->getQueryBuilder();
        $q->update('eva_ai_vec_builds')->set('status',$q->createNamedParameter($error===null?'queued':'failed'))
            ->set('error_code',$q->createNamedParameter($error,$error===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR))
            ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($claim['generation_id'])))
            ->andWhere($q->expr()->eq('status',$q->createNamedParameter('running')))
            ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))
            ->andWhere($q->expr()->gt('lease_until',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    public function nextDocument(array $claim): ?int {
        if ($claim['document_id']>0) return $claim['document_id'];
        $q=$this->db->getQueryBuilder();
        $q->select('id')->from('eva_ai_documents')->where($q->expr()->eq('user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->gt('id',$q->createNamedParameter($claim['after_document_id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->lte('id',$q->createNamedParameter($claim['upper_document_id'],IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->setMaxResults(1);
        $r=$q->executeQuery();try{$id=$r->fetchOne();}finally{$r->closeCursor();}
        return $id===false?null:(int)$id;
    }

    public function completeDocument(array $claim,int $documentId,string $stamp,string $plan,int $chunks): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Build manifest requires the checkpoint transaction.');
        $this->assertLease($claim,time());
        $this->db->insertIgnoreConflict('eva_ai_vec_build_docs',['generation_id'=>$claim['generation_id'],'user_id'=>$claim['user_id'],
            'document_id'=>$documentId,'source_stamp'=>$stamp,'plan_hash'=>$plan,'chunk_count'=>$chunks]);
    }

    public function stagedCount(string $id): int {
        $q=$this->db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_chunks')
            ->where($q->expr()->eq('vector_generation_id',$q->createNamedParameter($id)));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }

    /** Personal export includes progress/manifests, never runtime leases or targets. */
    public function exportMetadata(string $user): array {
        $result=['builds'=>[],'documents'=>[]];
        foreach (['builds'=>'eva_ai_vec_builds','documents'=>'eva_ai_vec_build_docs'] as $kind=>$table) {
            $after='';$document=0;
            do {
                $fields=$kind==='builds'?['generation_id','status','upper_document_id','after_document_id','document_id','chunk_cursor',
                    'documents_done','total_documents','heartbeat_at','created_at','updated_at','error_code']:
                    ['generation_id','document_id','source_stamp','plan_hash','chunk_count'];
                $q=$this->db->getQueryBuilder();$q->select(...$fields)->from($table)
                    ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)));
                if ($after!=='') {
                    $filter=$q->expr()->gt('generation_id',$q->createNamedParameter($after));
                    if ($kind==='documents') $filter=$q->expr()->orX($filter,$q->expr()->andX(
                        $q->expr()->eq('generation_id',$q->createNamedParameter($after)),
                        $q->expr()->gt('document_id',$q->createNamedParameter($document,IQueryBuilder::PARAM_INT))));
                    $q->andWhere($filter);
                }
                $q->orderBy('generation_id','ASC');if($kind==='documents')$q->addOrderBy('document_id','ASC');$q->setMaxResults(250);
                $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
                foreach($rows as$row){$result[$kind][]=$row;$after=$row['generation_id'];if($kind==='documents')$document=(int)$row['document_id'];}
            } while(count($rows)===250);
        }
        return $result;
    }

    private function lockGeneration(string $user,string $id,string $target): void {
        $this->owners->lockOpen($user);
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_generations')
            ->set('build_cursor',$q->func()->add('build_cursor',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        if (!$this->generations->isBuildingBound($user,$id,$target)) throw new VectorStoreException('generation_not_building');
    }

    private function lockRow(string $user,string $id): void {
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_builds')
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)))->executeStatement();
    }

    private function patch(string $user,string $id,array $values): void {
        $q=$this->db->getQueryBuilder();$q->update('eva_ai_vec_builds')
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->set('revision',$q->func()->add('revision',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($user)))->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($id)));
        foreach($values as$key=>$value) $q->set($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:($value===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR)));
        $q->executeStatement();
    }
}
