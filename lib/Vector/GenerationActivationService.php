<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationBuildRepository,GenerationVerificationRepository,VectorGenerationRepository,VectorOutboxRepository,VectorOwnerFenceRepository};
use OCP\IDBConnection;

/** Atomically activates verified SQL generations and explicitly requested Qdrant canaries. */
final class GenerationActivationService {
    public function __construct(
        private IDBConnection $db,
        private VectorOutboxRepository $outbox,
        private VectorOwnerFenceRepository $owners,
        private VectorGenerationRepository $generations,
        private GenerationBuildRepository $builds,
        private GenerationVerificationRepository $verifications,
    ) {}

    /** Activate at most one eligible generation per recurring job tick. */
    public function activateNextEnrolledSql(): bool {
        $q=$this->db->getQueryBuilder();
        $q->select('g.user_id','g.id')->from('eva_ai_vec_generations','g')
            ->innerJoin('g','eva_ai_vec_builds','b',$q->expr()->andX(
                $q->expr()->eq('b.user_id','g.user_id'),$q->expr()->eq('b.generation_id','g.id')))
            ->innerJoin('g','eva_ai_vec_verifications','v',$q->expr()->andX(
                $q->expr()->eq('v.user_id','g.user_id'),$q->expr()->eq('v.generation_id','g.id')))
            ->innerJoin('g','preferences','p',$q->expr()->andX(
                $q->expr()->eq('p.userid','g.user_id'),$q->expr()->eq('p.appid',$q->createNamedParameter('eva_ai')),
                $q->expr()->eq('p.configkey',$q->createNamedParameter('index_enrolled')),
                $q->expr()->eq('p.configvalue',$q->createNamedParameter('1'))))
            ->leftJoin('g','eva_ai_vec_state','s',$q->expr()->eq('s.user_id','g.user_id'))
            ->where($q->expr()->eq('g.backend',$q->createNamedParameter('sql')))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('building')))
            ->andWhere($q->expr()->eq('b.status',$q->createNamedParameter('staged')))
            ->andWhere($q->expr()->eq('v.status',$q->createNamedParameter('verified')))
            ->andWhere($q->expr()->orX($q->expr()->isNull('s.retrieval_backend'),$q->expr()->neq('s.retrieval_backend',$q->createNamedParameter('qdrant_generation'))))
            ->andWhere($q->expr()->eq('g.verification_hash','v.verification_hash'))
            ->andWhere($q->expr()->isNotNull('g.verified_at'))
            ->orderBy('b.updated_at','ASC')->addOrderBy('g.id','ASC')->setMaxResults(1);
        $r=$q->executeQuery();try{$candidate=$r->fetch();}finally{$r->closeCursor();}
        if(!$candidate)return false;
        return $this->activateEnrolledSql((string)$candidate['user_id'],(string)$candidate['id']);
    }

    public function activateEnrolledSql(string $userId,string $generationId): bool {
        return $this->outbox->transaction(function()use($userId,$generationId):bool {
            $this->owners->lockOpen($userId);
            if($this->generations->retrievalBackendForUser($userId)==='qdrant_generation')return false;
            $generation=$this->generations->find($userId,$generationId);
            if($generation===null||$generation->backend!=='sql'||$this->generations->status($userId,$generationId)!=='building')return false;
            if(!$this->isEnrolled($userId))return false;
            $build=$this->builds->find($userId,$generationId);
            $target=$this->generations->target($userId,$generationId);
            if($build===null||$build['status']!=='staged'||$target===null||!$this->builds->available($userId,$generationId,$target)
                ||!$this->verifications->certificateCurrent($userId,$generationId)
                ||$this->verifications->documentsBeyondSnapshot($userId,$build['upper_document_id'])>0
                ||$this->generations->pendingMutationCount($userId,$generationId)>0)return false;

            $state=$this->db->getQueryBuilder();
            $state->select('active_generation_id')->from('eva_ai_vec_state')
                ->where($state->expr()->eq('user_id',$state->createNamedParameter($userId)));
            $r=$state->executeQuery();try{$previous=$r->fetchOne();}finally{$r->closeCursor();}
            $activate=$this->db->getQueryBuilder();
            $activate->update('eva_ai_vec_generations')->set('status',$activate->createNamedParameter('active'))
                ->where($activate->expr()->eq('user_id',$activate->createNamedParameter($userId)))
                ->andWhere($activate->expr()->eq('id',$activate->createNamedParameter($generationId)))
                ->andWhere($activate->expr()->eq('status',$activate->createNamedParameter('building')));
            if($activate->executeStatement()!==1)return false;

            $this->db->insertIgnoreConflict('eva_ai_vec_state',['user_id'=>$userId,'active_generation_id'=>null,'retrieval_backend'=>'sql_legacy']);
            $update=$this->db->getQueryBuilder();
            $update->update('eva_ai_vec_state')->set('active_generation_id',$update->createNamedParameter($generationId))
                ->set('retrieval_backend',$update->createNamedParameter('sql_generation'))
                ->where($update->expr()->eq('user_id',$update->createNamedParameter($userId)));
            if($update->executeStatement()!==1)throw new \RuntimeException('Could not update the active SQL generation pointer.');
            if(is_string($previous)&&$previous!==''&&$previous!==$generationId){
                $retire=$this->db->getQueryBuilder();
                $retire->update('eva_ai_vec_generations')->set('status',$retire->createNamedParameter('retired'))
                    ->where($retire->expr()->eq('user_id',$retire->createNamedParameter($userId)))
                    ->andWhere($retire->expr()->eq('id',$retire->createNamedParameter($previous)))
                    ->andWhere($retire->expr()->eq('status',$retire->createNamedParameter('active')))->executeStatement();
            }
            return true;
        });
    }

    /** Explicitly opt a verified, consented Qdrant generation into primary retrieval. */
    public function activateEnrolledQdrant(string $userId,string $generationId): bool {
        return $this->outbox->transaction(function()use($userId,$generationId):bool {
            $this->owners->lockOpen($userId);
            $generation=$this->generations->find($userId,$generationId);
            if($generation===null||$generation->backend!=='qdrant'||$this->generations->status($userId,$generationId)!=='building'||!$this->isEnrolled($userId))return false;
            $build=$this->builds->find($userId,$generationId);$target=$this->generations->target($userId,$generationId);
            if($build===null||$build['status']!=='staged'||$target===null||!$this->builds->available($userId,$generationId,$target)
                ||!$this->verifications->certificateCurrent($userId,$generationId)
                ||$this->verifications->documentsBeyondSnapshot($userId,$build['upper_document_id'])>0
                ||$this->generations->pendingMutationCount($userId,$generationId)>0)return false;
            $previous=$this->generations->activeGenerationId($userId);
            $activate=$this->db->getQueryBuilder();
            $activate->update('eva_ai_vec_generations')->set('status',$activate->createNamedParameter('active'))
                ->where($activate->expr()->eq('user_id',$activate->createNamedParameter($userId)))
                ->andWhere($activate->expr()->eq('id',$activate->createNamedParameter($generationId)))
                ->andWhere($activate->expr()->eq('status',$activate->createNamedParameter('building')));
            if($activate->executeStatement()!==1)return false;
            $this->db->insertIgnoreConflict('eva_ai_vec_state',['user_id'=>$userId,'active_generation_id'=>null,'retrieval_backend'=>'sql_legacy']);
            $update=$this->db->getQueryBuilder();
            $update->update('eva_ai_vec_state')->set('active_generation_id',$update->createNamedParameter($generationId))
                ->set('retrieval_backend',$update->createNamedParameter('qdrant_generation'))
                ->where($update->expr()->eq('user_id',$update->createNamedParameter($userId)));
            if($update->executeStatement()!==1)throw new \RuntimeException('Could not update the active Qdrant generation pointer.');
            $this->retirePrevious($userId,$previous,$generationId);
            return true;
        });
    }

    /** Immediate one-step rollback to the built-in SQL retrieval path. */
    public function rollbackQdrant(string $userId,string $generationId): bool {
        return $this->outbox->transaction(function()use($userId,$generationId):bool {
            $this->owners->lockOpen($userId);
            if($this->generations->retrievalBackendForUser($userId)!=='qdrant_generation'||$this->generations->activeGenerationId($userId)!==$generationId)return false;
            $generation=$this->generations->find($userId,$generationId);
            if($generation===null||$generation->backend!=='qdrant'||$this->generations->status($userId,$generationId)!=='active')return false;
            $update=$this->db->getQueryBuilder();
            $update->update('eva_ai_vec_state')->set('active_generation_id',$update->createNamedParameter(null,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL))
                ->set('retrieval_backend',$update->createNamedParameter('sql_legacy'))
                ->where($update->expr()->eq('user_id',$update->createNamedParameter($userId)))
                ->andWhere($update->expr()->eq('retrieval_backend',$update->createNamedParameter('qdrant_generation')))
                ->andWhere($update->expr()->eq('active_generation_id',$update->createNamedParameter($generationId)));
            if($update->executeStatement()!==1)throw new \RuntimeException('Could not roll back Qdrant retrieval.');
            $retire=$this->db->getQueryBuilder();
            $retire->update('eva_ai_vec_generations')->set('status',$retire->createNamedParameter('retired'))
                ->where($retire->expr()->eq('user_id',$retire->createNamedParameter($userId)))
                ->andWhere($retire->expr()->eq('id',$retire->createNamedParameter($generationId)))
                ->andWhere($retire->expr()->eq('status',$retire->createNamedParameter('active')))->executeStatement();
            return true;
        });
    }

    private function retirePrevious(string $userId,?string $previous,string $next): void {
        if(!is_string($previous)||$previous===''||$previous===$next)return;
        $retire=$this->db->getQueryBuilder();
        $retire->update('eva_ai_vec_generations')->set('status',$retire->createNamedParameter('retired'))
            ->where($retire->expr()->eq('user_id',$retire->createNamedParameter($userId)))
            ->andWhere($retire->expr()->eq('id',$retire->createNamedParameter($previous)))
            ->andWhere($retire->expr()->eq('status',$retire->createNamedParameter('active')))->executeStatement();
    }

    private function isEnrolled(string $userId): bool {
        $q=$this->db->getQueryBuilder();
        $q->select('configvalue')->from('preferences')
            ->where($q->expr()->eq('userid',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('appid',$q->createNamedParameter('eva_ai')))
            ->andWhere($q->expr()->eq('configkey',$q->createNamedParameter('index_enrolled')));
        $r=$q->executeQuery();try{return $r->fetchOne()==='1';}finally{$r->closeCursor();}
    }
}
