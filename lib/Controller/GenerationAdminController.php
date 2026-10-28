<?php
declare(strict_types=1);
namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Dto\GenerationAdminRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Vector\{GenerationEmbeddingCheck, VectorSourceAccess, VectorStoreException};
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Read bounded generation metadata; only an explicit POST may probe a provider. */
class GenerationAdminController extends OCSController {
    public function __construct(string $appName, IRequest $request, private VectorGenerationRepository $generations,
        private VectorSourceAccess $access, private GenerationEmbeddingCheck $embeddingCheck) { parent::__construct($appName,$request); }

    #[AdminRequired]
    public function listing(string $userId): DataResponse {
        try {
            $query=GenerationAdminRequest::fromArray(['userId'=>$userId,'after'=>$this->request->getParam('after','')]);
            $this->access->assertOwner($userId);
            $rows = $this->generations->page($userId,$query->after);
            $items = array_map(static function(array $row): array {
                $item=array_intersect_key($row,array_flip(['id','status','embedding_provider','profile_id','embedding_model','dimension','chunk_size','chunk_overlap','created_at']));
                foreach (['dimension','chunk_size','chunk_overlap','created_at'] as $key) $item[$key]=(int)$item[$key];
                return $item;
            },$rows);
            return new ErrorDataResponse(['items'=>$items,'next_after'=>count($rows)===50?$rows[49]['id']:null]);
        } catch (\InvalidArgumentException) { return $this->failure('invalid_request','Select a valid user and generation cursor.',400); }
        catch (\Throwable) { return $this->failure('generation_unavailable','Generation metadata could not be loaded. Check the user and app migrations.',503); }
    }

    #[AdminRequired]
    public function check(string $userId, string $id): DataResponse {
        try {
            GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);
            return new ErrorDataResponse($this->embeddingCheck->check($userId,$id));
        } catch (\InvalidArgumentException) { return $this->failure('invalid_request','Select a valid user and generation.',400); }
        catch (VectorStoreException $e) {
            $message = match ($e->reason) {
                'embedding_profile_changed' => 'The embedding endpoint changed. Restore its configuration or create a new generation.',
                'embedding_model_mismatch','invalid_embedding_vector' => 'The provider returned a different model or vector space. Check the generation model and dimension.',
                'embedding_key_unavailable','invalid_embedding_key' => 'The selected provider key is unavailable. Save it again in the owner account.',
                'embedding_egress_denied' => 'The embedding destination is not allowed. Check the administrator origin policy.',
                'generation_missing','generation_unavailable','source_unavailable' => 'This generation or its owner is unavailable for checking.',
                default => 'The embedding check failed. Check the provider connection and supported embedding API.',
            };
            return $this->failure('embedding_check_failed',$message,503);
        } catch (\Throwable) { return $this->failure('embedding_check_failed','The embedding check could not be completed.',503); }
    }

    private function failure(string $code,string $message,int $status): DataResponse {
        return new ErrorDataResponse(['error'=>['code'=>$code,'message'=>$message]],$status);
    }
}
