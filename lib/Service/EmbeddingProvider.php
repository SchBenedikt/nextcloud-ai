<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Embedding operations shared by ingestion and retrieval, independent of chat. */
interface EmbeddingProvider {
    /**
     * Preserve input order, including duplicate inputs. A rejected individual
     * input occupies a null slot; a failed batch returns [null, error].
     * Implementations must isolate caches by user and embedding identity.
     *
     * @param list<string> $texts
     * @return array{0: ?array, 1: ?string}
     */
    public function embedBatch(array $texts, ?string $userId = null): array;

    /**
     * Query vectors must use the same embedding space as indexed documents.
     * @param list<string> $texts
     * @return array{0: ?array, 1: ?string}
     */
    public function embedQuery(array $texts, ?string $userId = null): array;

    /**
     * Counters describe the most recent batch, not cumulative process state.
     * The historical ollama_requests key is retained for status DTO compatibility;
     * a future adapter must not count its own requests as Ollama requests.
     * @return array{cache_hits:int, cache_misses:int, ollama_requests:int}
     */
    public function lastEmbeddingStats(): array;

    /** Exact model used for the most recent successful uncached embedding batch, when known. */
    public function lastEmbeddingModel(): ?string;
}
