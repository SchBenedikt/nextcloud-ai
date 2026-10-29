<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Short-lived correlation context for one chat execution. */
final class TraceContext {
    private ?string $traceId = null;
    private ?string $spanId = null;
    /** @var array{backend:string,embedding_provider:string,dense_sql_rows_scanned:int,stage_mask:int,count_ms:int,embedding_ms:int,candidates_ms:int,hydrate_ms:int,scope_ms:int,ranking_ms:int,total_ms:int}|null */
    private ?array $retrievalMeasurements = null;

    public function id(): ?string {
        return $this->traceId;
    }

    public function spanId(): ?string {
        return $this->spanId;
    }

    /** W3C context for configured downstream AI/vector services; no baggage. */
    public function outboundHeaders(): array {
        if ($this->traceId === null || $this->spanId === null) return [];
        return ['traceparent' => '00-' . $this->traceId . '-' . $this->spanId . '-00'];
    }

    /** Keep only bounded numeric retrieval stages for the matching chat trace. */
    public function recordRetrievalMeasurements(string $traceId, array $measurements): void {
        if ($this->traceId === null || !hash_equals($this->traceId, $traceId)) return;
        $backend = $measurements['backend'] ?? null;
        if (!in_array($backend, ['sql_legacy', 'sql_generation', 'qdrant_generation'], true)) return;
        $embeddingProvider = $measurements['embedding_provider'] ?? 'none';
        if (!in_array($embeddingProvider, ['none', 'frozen', 'mixed', 'ollama', 'openai_compatible'], true)) $embeddingProvider = 'none';
        $denseSqlRowsScanned = $measurements['dense_sql_rows_scanned'] ?? 0;
        if (!is_int($denseSqlRowsScanned) && !is_float($denseSqlRowsScanned) || !is_finite((float)$denseSqlRowsScanned) || $denseSqlRowsScanned < 0) $denseSqlRowsScanned = 0;
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
            ['backend' => $backend, 'embedding_provider' => $embeddingProvider, 'dense_sql_rows_scanned' => min(2147483647, (int)$denseSqlRowsScanned), 'stage_mask' => $stageMask],
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
        $previousSpanId = $this->spanId;
        $previousMeasurements = $this->retrievalMeasurements;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? $this->randomHex(16);
            $this->spanId = $this->randomHex(8);
            $this->retrievalMeasurements = null;
        }
        try {
            return $operation();
        } finally {
            $this->traceId = $previous;
            $this->spanId = $previousSpanId;
            $this->retrievalMeasurements = $previousMeasurements;
        }
    }

    /** Preserve a trace while a streamed chat generator is being consumed. */
    public function runGenerator(callable $operation, ?string $requestedId = null): \Generator {
        $previous = $this->traceId;
        $previousSpanId = $this->spanId;
        $previousMeasurements = $this->retrievalMeasurements;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? $this->randomHex(16);
            $this->spanId = $this->randomHex(8);
            $this->retrievalMeasurements = null;
        }
        try {
            yield from $operation();
        } finally {
            $this->traceId = $previous;
            $this->spanId = $previousSpanId;
            $this->retrievalMeasurements = $previousMeasurements;
        }
    }

    private function normalize(?string $traceId): ?string {
        return is_string($traceId) && preg_match('/^[a-f0-9]{32}$/D', $traceId) === 1 && $traceId !== str_repeat('0', 32) ? $traceId : null;
    }

    private function randomHex(int $bytes): string {
        do { $value = bin2hex(random_bytes($bytes)); } while ($value === str_repeat('0', $bytes * 2));
        return $value;
    }
}
