<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Short-lived correlation context for one chat execution. */
final class TraceContext {
    private ?string $traceId = null;

    public function id(): ?string {
        return $this->traceId;
    }

    /** Keep nested service calls on the same trace and always restore the prior context. */
    public function run(callable $operation, ?string $requestedId = null): mixed {
        $previous = $this->traceId;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? bin2hex(random_bytes(16));
        }
        try {
            return $operation();
        } finally {
            $this->traceId = $previous;
        }
    }

    /** Preserve a trace while a streamed chat generator is being consumed. */
    public function runGenerator(callable $operation, ?string $requestedId = null): \Generator {
        $previous = $this->traceId;
        if ($previous === null) {
            $this->traceId = $this->normalize($requestedId) ?? bin2hex(random_bytes(16));
        }
        try {
            yield from $operation();
        } finally {
            $this->traceId = $previous;
        }
    }

    private function normalize(?string $traceId): ?string {
        return is_string($traceId) && preg_match('/^[a-f0-9]{32}$/D', $traceId) === 1 ? $traceId : null;
    }
}
