<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Immutable identity; lifecycle updates live in a tenant-scoped repository. */
final readonly class VectorGeneration {
    public function __construct(
        public string $id,
        public string $userId,
        public string $backend,
        public string $embeddingProvider,
        public ?string $profileId,
        public string $embeddingModel,
        public string $endpointHash,
        public int $dimension,
        public int $chunkSize,
        public int $chunkOverlap,
        public int $schemaVersion = 1,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || $userId === '' || mb_strlen($userId) > 64 || preg_match('/[\x00-\x1f\x7f]/', $userId)
            || $backend !== 'qdrant' || !in_array($embeddingProvider, ['ollama', 'openai_compatible'], true)
            || trim($embeddingModel) === '' || mb_strlen($embeddingModel) > 128 || preg_match('/[\x00-\x1f\x7f]/', $embeddingModel)
            || preg_match('/^[a-f0-9]{64}$/D', $endpointHash) !== 1 || $dimension < 1 || $dimension > 16384
            || $chunkSize < 128 || $chunkSize > 10000 || $chunkOverlap < 0 || $chunkOverlap >= $chunkSize || $chunkOverlap > 5000 || $schemaVersion !== 1) {
            throw new \InvalidArgumentException('Invalid immutable vector-generation identity.');
        }
        if (($embeddingProvider === 'ollama' && $profileId !== null)
            || ($embeddingProvider === 'openai_compatible' && ($profileId === null || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $profileId) !== 1))) {
            throw new \InvalidArgumentException('Embedding profile does not match the selected provider type.');
        }
    }

    public function collection(): string { return 'eva_g_' . $this->id; }
    public function distance(): string { return 'cosine'; }

    public function validateVector(array $vector): void {
        if (!array_is_list($vector) || count($vector) !== $this->dimension) throw new \InvalidArgumentException('Vector dimension does not match its generation.');
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) throw new \InvalidArgumentException('Vector must contain finite numeric values.');
            $norm += $value * $value;
        }
        if (!is_finite($norm) || $norm <= 0) throw new \InvalidArgumentException('Vector magnitude must be finite and nonzero.');
    }

    public function toRow(): array {
        return ['id' => $this->id, 'user_id' => $this->userId, 'backend' => $this->backend,
            'collection_name' => $this->collection(), 'embedding_provider' => $this->embeddingProvider,
            'profile_id' => $this->profileId, 'embedding_model' => $this->embeddingModel, 'endpoint_hash' => $this->endpointHash,
            'dimension' => $this->dimension, 'distance_metric' => $this->distance(), 'chunk_size' => $this->chunkSize,
            'chunk_overlap' => $this->chunkOverlap, 'schema_version' => $this->schemaVersion];
    }

    public static function fromRow(array $row): self {
        $generation = new self($row['id'], $row['user_id'], $row['backend'], $row['embedding_provider'], $row['profile_id'],
            $row['embedding_model'], $row['endpoint_hash'], (int)$row['dimension'], (int)$row['chunk_size'], (int)$row['chunk_overlap'], (int)$row['schema_version']);
        if ($generation->collection() !== $row['collection_name'] || $generation->distance() !== $row['distance_metric']) throw new \RuntimeException('Stored generation identity is inconsistent.');
        return $generation;
    }
}
