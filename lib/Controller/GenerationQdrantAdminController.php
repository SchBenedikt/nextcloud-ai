<?php
declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Vector\{QdrantConnection, QdrantVectorStore, VectorGeneration, VectorSourceAccess, VectorStoreException};
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Explicit administrator actions for optional Qdrant setup; no document data is read here. */
final class GenerationQdrantAdminController extends OCSController {
    public function __construct(string $appName, IRequest $request, private VectorGenerationRepository $generations,
        private VectorSourceAccess $access, private QdrantConnection $qdrant, private QdrantVectorStore $qdrantStore) {
        parent::__construct($appName,$request);
    }

    #[AdminRequired]
    public function create(string $userId): DataResponse {
        try {
            $this->access->assertOwner($userId);
            $input=$this->jsonBody(4096);
            $sourceId=$input['source_generation_id']??null;
            $includeText=$input['include_text_payload']??null;
            $confirmText=$input['confirm_text_storage']??null;
            if (!is_string($sourceId) || preg_match('/^[a-f0-9]{32}$/D',$sourceId)!==1
                || !is_bool($includeText) || !is_bool($confirmText) || ($includeText && !$confirmText)) {
                throw new \InvalidArgumentException('Select a valid SQL generation and confirm any Qdrant text storage.');
            }
            $connection=$this->qdrant->status();
            if (!$connection['configured'] || !$connection['enabled']) {
                return $this->failure('qdrant_not_enabled','Configure and enable the optional Qdrant connection first.',409);
            }
            $source=$this->generations->activeSqlForUser($userId);
            if ($source===null || $source->id!==$sourceId || $this->generations->status($userId,$sourceId)!=='active') {
                return $this->failure('sql_generation_required','Select this account’s active SQL generation before preparing optional Qdrant.',409);
            }
            $generation=new VectorGeneration(bin2hex(random_bytes(16)),$userId,'qdrant',$source->embeddingProvider,$source->profileId,
                $source->embeddingModel,$source->endpointHash,$source->dimension,$source->chunkSize,$source->chunkOverlap,
                $source->schemaVersion,$includeText);
            $this->generations->create($generation);
            return new ErrorDataResponse(['generation'=>[
                'id'=>$generation->id,'backend'=>'qdrant','status'=>'building','embedding_provider'=>$generation->embeddingProvider,
                'profile_id'=>$generation->profileId,'embedding_model'=>$generation->embeddingModel,'dimension'=>$generation->dimension,
                'chunk_size'=>$generation->chunkSize,'chunk_overlap'=>$generation->chunkOverlap,
                'include_text_payload'=>$generation->includeTextPayload,'created_at'=>time(),
            ],'prepared'=>false,'retrieval_changed'=>false]);
        } catch (\InvalidArgumentException|\JsonException) {
            return $this->failure('invalid_request','Select a valid active SQL generation. Confirm text storage when enabling hybrid search.',400);
        } catch (\Throwable) { return $this->failure('generation_unavailable','The Qdrant generation could not be prepared. Check the owner and app database.',503); }
    }

    #[AdminRequired]
    public function prepare(string $userId,string $id): DataResponse {
        try {
            if ($userId==='' || mb_strlen($userId)>64 || preg_match('/[\x00-\x1f\x7f]/',$userId)
                || preg_match('/^[a-f0-9]{32}$/D',$id)!==1) throw new \InvalidArgumentException();
            $this->access->assertOwner($userId);
            $generation=$this->generations->find($userId,$id);
            if ($generation===null || $generation->backend!=='qdrant' || $this->generations->status($userId,$id)!=='building') {
                return $this->failure('generation_not_preparable','Only a building Qdrant generation can be prepared.',409);
            }
            $target=$this->qdrant->targetFingerprint();
            $this->generations->bindTarget($userId,$id,$target);
            $guard=function()use($userId,$id,$target):void {
                if (!$this->generations->isBuildingBound($userId,$id,$target)) throw new VectorStoreException('generation_not_building');
            };
            $this->qdrantStore->forTarget($target,$guard)->prepare($generation);
            return new ErrorDataResponse(['prepared'=>true,'generation_id'=>$id,'retrieval_changed'=>false,'document_content_sent'=>false]);
        } catch (\InvalidArgumentException) { return $this->failure('invalid_request','Select a valid Qdrant generation.',400); }
        catch (VectorStoreException $e) {
            $message=match($e->reason) {
                'not_enabled','target_missing' => 'Configure and enable the optional Qdrant connection first.',
                'remote_tls_required' => 'Remote Qdrant servers require HTTPS.',
                'egress_denied' => 'The Qdrant destination is blocked by the instance network policy.',
                'unsupported_version' => 'This Qdrant server version is not supported.',
                default => 'The Qdrant collection could not be prepared. Check the saved connection and retry.',
            };
            return $this->failure('qdrant_prepare_failed',$message,503);
        } catch (\Throwable) { return $this->failure('qdrant_prepare_failed','The Qdrant collection could not be prepared. Check the saved connection and retry.',503); }
    }

    private function jsonBody(int $limit): array {
        if (!preg_match('~^application/json(?:\s*;|$)~i',$this->request->getHeader('Content-Type'))) throw new \InvalidArgumentException();
        $raw=file_get_contents('php://input',false,null,0,$limit+1);
        if (!is_string($raw) || strlen($raw)>$limit) throw new \InvalidArgumentException();
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new \InvalidArgumentException();
        return $input;
    }

    private function failure(string $code,string $message,int $status): DataResponse {
        return new ErrorDataResponse(['error'=>['code'=>$code,'message'=>$message]],$status);
    }
}
