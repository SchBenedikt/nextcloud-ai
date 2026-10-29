<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Dto\PreparedGenerationDocument;
use OCA\EvaAi\Service\Chunker;

/** Canonical source + immutable settings, before provider I/O or SQL mutation. */
class GenerationDocumentPreparer {
    /** Bound pathological sources while accommodating large extracted documents. */
    public const MAX_CHUNKS = 10000;

    public function __construct(private GenerationSourceReader $sources, private Chunker $chunker) {}

    public function prepare(VectorGeneration $generation, VectorDocumentSnapshot $source, ?\Closure $guard = null): PreparedGenerationDocument {
        if ($generation->userId !== $source->userId) throw new VectorStoreException('source_identity_mismatch');
        $text = $this->sources->read($source, $guard);
        try { $chunks = $this->chunker->chunkWithSettings($text, $generation->chunkSize, $generation->chunkOverlap, self::MAX_CHUNKS); }
        catch (\LengthException) { throw new VectorStoreException('source_chunk_limit'); }
        $digest = hash_init('sha256');
        hash_update($digest, json_encode(['chunker' => 2, 'generation' => $generation->toRow(), 'source_stamp' => $source->stamp], JSON_THROW_ON_ERROR));
        foreach ($chunks as $chunk) {
            // Hash each bounded record with an unambiguous separator; no second
            // whole-document JSON allocation alongside the chunk array.
            hash_update($digest, "\n" . json_encode($chunk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        if($guard)$guard();
        return new PreparedGenerationDocument($source, $chunks, hash_final($digest));
    }
}
