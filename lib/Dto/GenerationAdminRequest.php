<?php
declare(strict_types=1);
namespace OCA\EvaAi\Dto;

final readonly class GenerationAdminRequest {
    private function __construct(public string $userId, public ?string $id, public string $after) {}
    public static function fromArray(array $input): self {
        $user=$input['userId']??null; $id=$input['id']??null; $after=$input['after']??'';
        if (!is_string($user) || $user==='' || mb_strlen($user)>64 || preg_match('/[\x00-\x1f\x7f]/',$user)
            || ($id!==null && (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1))
            || !is_string($after) || ($after!=='' && preg_match('/^[a-f0-9]{32}$/D',$after)!==1)) throw new \InvalidArgumentException('Invalid generation selection.');
        return new self($user,$id,$after);
    }
}
