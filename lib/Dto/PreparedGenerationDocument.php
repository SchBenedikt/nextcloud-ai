<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use OCA\EvaAi\Vector\VectorDocumentSnapshot;

/** Internal bounded text plan; it contains no inferred legacy vectors. */
final readonly class PreparedGenerationDocument {
    /** @param list<array{content:string,tokens:int,provenance:array}> $chunks */
    public function __construct(public VectorDocumentSnapshot $source, public array $chunks, public string $planHash) {}
}
