<?php
declare(strict_types=1);
namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Dto\GenerationAdminRequest;
use OCA\EvaAi\Vector\{GenerationVerificationService,VectorStoreException};
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class GenerationVerificationAdminController extends OCSController {
    public function __construct(string $appName,IRequest $request,private GenerationVerificationService $verification){parent::__construct($appName,$request);}

    #[AdminRequired]
    public function status(string$userId,string$id):DataResponse {
        try{GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);return new ErrorDataResponse($this->verification->status($userId,$id));}
        catch(\InvalidArgumentException){return $this->failure('invalid_request','Select a valid generation.',400);}
        catch(\Throwable){return $this->failure('verification_unavailable','Verification progress could not be loaded.',503);}
    }

    #[AdminRequired]
    public function control(string$userId,string$id,string$operation):DataResponse {
        try{
            GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);
            if(!preg_match('~^application/json(?:\s*;|$)~i',$this->request->getHeader('Content-Type')))throw new \InvalidArgumentException();
            if(!in_array($operation,['start','pause','resume','cancel','retry','activate','rollback'],true))throw new \InvalidArgumentException();
            return new ErrorDataResponse($this->verification->control($userId,$id,$operation));
        }catch(\InvalidArgumentException){return $this->failure('invalid_request','Select a valid verification action.',400);}
        catch(VectorStoreException$error){
            $message=match($error->reason){
                'build_not_staged'=>'Complete source preparation before verifying the generation.',
                'build_disabled'=>'Enable source indexing and ensure the selected generation backend is available before starting or resuming verification.',
                'verification_missing'=>'Start verification before changing its state.',
                'generation_not_activatable'=>'This Qdrant generation is not fully built, current and verified. Refresh its progress before activation.',
                'generation_not_active'=>'This Qdrant generation is no longer active. Refresh its state before retrying.',
                default=>'This generation or its owner is unavailable. Refresh the current state before retrying.',
            };
            return $this->failure('verification_control_failed',$message,409);
        }catch(\Throwable){return $this->failure('verification_control_failed','Verification could not be updated. Refresh its current state before retrying.',503);}
    }

    private function failure(string$code,string$message,int$status):DataResponse{return new ErrorDataResponse(['error'=>['code'=>$code,'message'=>$message]],$status);}
}
