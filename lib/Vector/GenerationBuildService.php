<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationBuildRepository, GenerationVerificationRepository, VectorGenerationRepository};

/** Shared native/OCC control; starting schedules work, never makes a provider request. */
class GenerationBuildService {
    public function __construct(private GenerationBuildRepository $builds,private VectorGenerationRepository $generations,private VectorSourceAccess $access,
        private ?GenerationVerificationRepository $verifications=null) {}

    public function status(string $user,string $id): array {
        $this->access->assertOwner($user);
        $generation=$this->generations->find($user,$id) ?? throw new VectorStoreException('generation_missing');
        $target=$this->generations->target($user,$id);
        $row=$this->builds->find($user,$id);
        $result=$row===null?['status'=>'not_started','documents_done'=>0,'total_documents'=>0,'heartbeat_at'=>0,'error_code'=>null]:
            array_intersect_key($row,array_flip(['status','documents_done','total_documents','heartbeat_at','error_code']));
        $activeGeneration=$this->generations->activeGenerationForUser($user);
        $serving=$activeGeneration?->id===$id;
        $retrievalBackend=$activeGeneration===null?'sql_legacy':($activeGeneration->backend==='qdrant'?'qdrant_generation':'sql_generation');
        return $result+['backend'=>$generation->backend,'staged_chunks'=>$this->builds->stagedCount($id),'can_start'=>$target!==null && $this->builds->available($user,$id,$target),
            'retrieval_changed'=>false,'verified'=>$this->verifications?->certificateCurrent($user,$id)??false,
            'active'=>$serving,'retrieval_backend'=>$retrievalBackend];
    }

    public function control(string $user,string $id,string $action,bool $confirmed=false): array {
        $this->access->assertOwner($user);
        if ($action==='start') {
            if (!$confirmed) throw new \InvalidArgumentException('Explicit document-transfer consent is required.');
            $target=$this->generations->target($user,$id) ?? throw new VectorStoreException('generation_unprepared');
            $this->builds->start($user,$id,$target);
        } else $this->builds->control($user,$id,$action);
        return $this->status($user,$id);
    }
}
