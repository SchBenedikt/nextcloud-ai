<?php
declare(strict_types=1);
namespace OCA\EvaAi\Dto;

final readonly class GenerationBuildRequest {
    private function __construct(public string $action,public bool $confirmed,public bool $confirmedText) {}
    public static function fromArray(string $action,array $input): self {
        if (!in_array($action,['start','pause','resume','cancel'],true)
            || (isset($input['confirm_document_transfer']) && !is_bool($input['confirm_document_transfer']))
            || (isset($input['confirm_text_transfer']) && !is_bool($input['confirm_text_transfer']))
            || ($action==='start' && ($input['confirm_document_transfer']??false)!==true)) throw new \InvalidArgumentException('Confirm document transfer before starting a build.');
        return new self($action,($input['confirm_document_transfer']??false)===true,($input['confirm_text_transfer']??false)===true);
    }
}
