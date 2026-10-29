<?php
declare(strict_types=1);
namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Dto\GenerationAdminRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Vector\{GenerationShadowService,VectorStoreException};
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class GenerationShadowAdminController extends OCSController {
    public function __construct(string$appName,IRequest$request,private GenerationShadowService$shadow){parent::__construct($appName,$request);}

    #[AdminRequired]
    public function compare(string$userId,string$id):DataResponse {
        try{
            GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);
            if(!preg_match('~^application/json(?:\s*;|$)~i',$this->request->getHeader('Content-Type')))throw new \InvalidArgumentException();
            $query=$this->request->getParam('query');$topK=$this->request->getParam('top_k',10);$scope=$this->request->getParam('scope_path',null);
            if(!is_string($query)||!is_int($topK)||($scope!==null&&!is_string($scope)))throw new \InvalidArgumentException();
            return new ErrorDataResponse($this->shadow->compare($userId,$id,$query,$topK,$scope));
        }catch(\InvalidArgumentException){return $this->failure('invalid_request','Enter a valid sample query and scope.',400);}
        catch(VectorStoreException$error){
            $message=match($error->reason){
                'shadow_prerequisites_missing'=>'Complete verification and enable the configured vector point store before comparing results.',
                'shadow_prerequisites_changed'=>'The verified generation changed during this comparison. Refresh its status and retry.',
                'shadow_busy'=>'A comparison is already running for this generation. Wait for it to finish.',
                'shadow_query_too_long'=>'Keep the sample query within this generation’s chunk size.',
                'sql_shadow_rows_exceeded','sql_shadow_budget_exceeded'=>'The SQL comparison reached its bounded scan budget. Use a smaller generation or a narrower scope.',
                'shadow_stale_point'=>'A vector result no longer matches its current SQL revision. Reconcile the point queue and run verification again.',
                default=>'The comparison stopped safely. Check the pinned embedding provider and vector storage connection.',
            };
            return $this->failure('shadow_comparison_failed',$message,409);
        }catch(\Throwable){return $this->failure('shadow_unavailable','The comparison could not be completed. Check the app migrations and storage connection.',503);}
    }

    private function failure(string$code,string$message,int$status):DataResponse{return new ErrorDataResponse(['error'=>['code'=>$code,'message'=>$message]],$status);}
}
