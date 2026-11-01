<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use OCA\EvaAi\Vector\VectorGeneration;

/** Internal prepared chunk; no raw upload/HTTP endpoint accepts these vectors. */
final readonly class PreparedVectorChunk {
    public function __construct(public int $index, public string $content, public array $vector,
        public int $tokens, public array $provenance = []) {
        if ($index < 0 || $index > 10000000 || trim($content) === '' || strlen($content) > 40000
            || $tokens < 1 || $tokens > 10000 || array_key_exists('_eva_vector', $provenance)
            || strlen(json_encode($provenance, JSON_THROW_ON_ERROR)) > 3000) throw new \InvalidArgumentException('Invalid prepared vector chunk.');
    }

    public function validate(VectorGeneration $generation): void {
        if (mb_strlen($this->content) > $generation->chunkSize) throw new \InvalidArgumentException('Chunk exceeds its generation size.');
        $generation->validateVector($this->vector);
    }
}
