<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Reconstructible search index. SQL remains authoritative for text and access. */
interface VectorStore {
    /**
     * Validate all points against the generation before issuing an idempotent write.
     * Implementations enforce tenant and generation payload, never caller overrides.
     * @param list<array{id:int,document_id:int,file_id:int,chunk_index:int,source:string,path:string,content_hash:string,vector:array}> $points
     */
    public function upsert(VectorGeneration $generation, array $points): void;
    public function deleteDocument(VectorGeneration $generation, int $documentId): void;
    /** @return list<array{chunkId:int,score:float}> Tenant-filtered IDs only; hydrate text from owned SQL rows. */
    public function search(VectorGeneration $generation, array $queryVector, int $topK, SearchFilters $filters): array;
    /** @return array{available:bool,code:string} No endpoint secrets or raw remote errors. */
    public function health(): array;
}
