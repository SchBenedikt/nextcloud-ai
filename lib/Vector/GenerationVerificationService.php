<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationBuildRepository,GenerationVerificationRepository,VectorGenerationRepository};

/** Owner-scoped status/control; all remote checks are background-only. */
class GenerationVerificationService {
    public function __construct(private GenerationVerificationRepository $verifications,private GenerationBuildRepository $builds,
        private VectorGenerationRepository $generations,private VectorSourceAccess $access,private GenerationActivationService $activation) {}

    public function status(string $user,string $id): array {
        $this->access->assertOwner($user);
        $generation=$this->generations->find($user,$id)??throw new VectorStoreException('generation_missing');
        $build=$this->builds->find($user,$id);$target=$this->generations->target($user,$id);$row=$this->verifications->find($user,$id);
        $verified=$row!==null&&$row['status']==='verified'&&$this->verifications->certificateCurrent($user,$id);
        $activeGeneration=$this->generations->activeGenerationForUser($user);
        $serving=$activeGeneration?->id===$id;
        $activePointer=$this->generations->activeGenerationId($user)===$id;
        $retrievalBackend=$activeGeneration===null?'sql_legacy':($activeGeneration->backend==='qdrant'?'qdrant_generation':'sql_generation');
        $canActivate=$verified&&$generation->backend==='qdrant'&&$this->generations->status($user,$id)==='building'&&$build!==null&&$build['status']==='staged'&&$target!==null
            &&$this->builds->available($user,$id,$target)
            &&$this->verifications->documentsBeyondSnapshot($user,$build['upper_document_id'])===0
            &&$this->generations->pendingMutationCount($user,$id)===0;
        $state=$row===null?['status'=>'not_started','phase'=>'points','expected_count'=>0,'checked_count'=>0,'sample_count'=>0,
            'remote_count'=>null,'heartbeat_at'=>0,'error_code'=>null]:array_intersect_key($row,array_flip(['status','phase','expected_count','checked_count','sample_count','remote_count','heartbeat_at','error_code']));
        if($row!==null&&$row['status']==='verified'&&!$verified)$state['status']='stale';
        return $state+['backend'=>$generation->backend,'can_start'=>$build!==null&&$build['status']==='staged'&&$target!==null&&$this->builds->available($user,$id,$target),
            'verified'=>$verified,'retrieval_changed'=>false,'active'=>$serving,'can_activate'=>$canActivate,
            'can_rollback'=>$activePointer&&$generation->backend==='qdrant'&&$this->generations->status($user,$id)==='active','retrieval_backend'=>$retrievalBackend];
    }

    public function control(string $user,string $id,string $operation): array {
        $this->access->assertOwner($user);
        if($operation==='start')$this->verifications->start($user,$id);
        elseif($operation==='activate') {
            if(!$this->activation->activateEnrolledQdrant($user,$id))throw new VectorStoreException('generation_not_activatable');
            $state=$this->status($user,$id);$state['retrieval_changed']=true;return $state;
        } elseif($operation==='rollback') {
            if(!$this->activation->rollbackQdrant($user,$id))throw new VectorStoreException('generation_not_active');
            $state=$this->status($user,$id);$state['retrieval_changed']=true;return $state;
        } else $this->verifications->control($user,$id,$operation);
        return $this->status($user,$id);
    }
}
