<?php
declare(strict_types=1);
namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Dto\{GenerationAdminRequest, GenerationBuildRequest};
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Vector\{GenerationBuildService, VectorStoreException};
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class GenerationBuildAdminController extends OCSController {
    public function __construct(string $appName,IRequest $request,private GenerationBuildService $builds) {parent::__construct($appName,$request);}

    #[AdminRequired]
    public function status(string $userId,string $id): DataResponse {
        try {
            GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);
            return new ErrorDataResponse($this->builds->status($userId,$id));
        } catch(\InvalidArgumentException) {return $this->failure('invalid_request','Select a valid generation.',400);}
        catch(\Throwable) {return $this->failure('build_unavailable','Build progress could not be loaded. Check the account and app migrations.',503);}
    }

    #[AdminRequired]
    public function control(string $userId,string $id,string $operation): DataResponse {
        try {
            GenerationAdminRequest::fromArray(['userId'=>$userId,'id'=>$id]);
            if (!preg_match('~^application/json(?:\s*;|$)~i',$this->request->getHeader('Content-Type'))) throw new \InvalidArgumentException();
            $input=['confirm_document_transfer'=>$this->request->getParam('confirm_document_transfer',false)];
            $dto=GenerationBuildRequest::fromArray($operation,$input);
            return new ErrorDataResponse($this->builds->control($userId,$id,$dto->action,$dto->confirmed));
        } catch(\InvalidArgumentException) {return $this->failure('invalid_request','Confirm document transfer and select a valid build action.',400);}
        catch(VectorStoreException $error) {
            $message=match($error->reason) {
                'build_disabled','build_target_changed'=>'Enable the source index, configured storage connection and point delivery before starting or resuming.',
                'generation_unprepared','generation_not_building'=>'Prepare an owned building generation on the current storage destination first.',
                'build_missing'=>'Start this build before changing its state.',
                default=>'This build or its owner is unavailable. Refresh the current state before retrying.',
            };
            return $this->failure('build_control_failed',$message,409);
        } catch(\Throwable) {return $this->failure('build_control_failed','The build could not be updated. Refresh its current state before retrying.',503);}
    }

    private function failure(string $code,string $message,int $status): DataResponse {
        return new ErrorDataResponse(['error'=>['code'=>$code,'message'=>$message]],$status);
    }
}
