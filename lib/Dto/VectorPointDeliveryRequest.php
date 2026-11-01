<?php
declare(strict_types=1);
namespace OCA\EvaAi\Dto;

final readonly class VectorPointDeliveryRequest {
    private function __construct(public bool $enabled) {}
    public static function fromArray(array $input): self {
        if (!is_bool($input['enabled'] ?? null)) throw new \InvalidArgumentException('Supply enabled as a JSON boolean.');
        return new self($input['enabled']);
    }
}
