<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Short-lived correlation context for one chat execution. */
final class TraceContext {
    private ?string $traceId = null;
    /** @var array{backend:string,stage_mask:int,count_ms:int,embedding_ms:int,candidates_ms:int,hydrate_ms:int,scope_ms:int,ranking_ms:int,total_ms:int}|null */
    private ?array $retrievalMeasurements = null;

    public function id(): ?string {
        return $this->traceId;
    }

    /** Keep only bounded numeric retrieval stages for the matching chat trace. */
    public function recordRetrievalMeasurements(string $traceId, array $measurements): void {
        if ($this->traceId === null || !hash_equals($this->traceId, $traceId)) return;
        $backend = $measurements['backend'] ?? null;
        if (!in_array($backend, ['sql_legacy', 'sql_generation', 'qdrant_generation'], true)) return;
        $stages = [];
        $stageNames = ['count_ms', 'embedding_ms', 'candidates_ms', 'hydrate_ms', 'scope_ms', 'ranking_ms', 'total_ms'];
        $stageMask = 0;
        foreach ($stageNames as $index => $stage) {
            $value = $measurements[$stage] ?? null;
            if (!is_int($value) && !is_float($value) || !is_finite((float)$value) || $value < 0) continue;
            $stages[$stage] = min(2147483647, (int)round($value));
            $stageMask |= 1 << $index;
        }
        if ($stages === []) return;
        $this->retrievalMeasurements = array_merge(
            ['backend' => $backend, 'stage_mask' => $stageMask],
            array_fill_keys($stageNames, 0),
            $stages,
        );
    }

    /** Consume once when the provider records the corresponding chat request. */
    public function consumeRetrievalMeasurements(): ?array {
        $measurements = $this->retrievalMeasurements;
        $this->retrievalMeasurements = null;
        return $measurements;
    }

    /** Keep nested service calls on the same trace and always restore the prior context. */
    public function run(callable $operation, ?string $requestedId = null): mixed {
        $previous = $this->traceId;
        $previousMeasurements = $this->retrievalMeasurements;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? bin2hex(random_bytes(16));
            $this->retrievalMeasurements = null;
        }
        try {
            return $operation();
        } finally {
            $this->traceId = $previous;
            $this->retrievalMeasurements = $previousMeasurements;
        }
    }

    /** Preserve a trace while a streamed chat generator is being consumed. */
    public function runGenerator(callable $operation, ?string $requestedId = null): \Generator {
        $previous = $this->traceId;
        $previousMeasurements = $this->retrievalMeasurements;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? bin2hex(random_bytes(16));
            $this->retrievalMeasurements = null;
        }
        try {
            yield from $operation();
        } finally {
            $this->traceId = $previous;
            $this->retrievalMeasurements = $previousMeasurements;
        }
    }

    private function normalize(?string $traceId): ?string {
        return is_string($traceId) && preg_match('/^[a-f0-9]{32}$/D', $traceId) === 1 ? $traceId : null;
    }
}
