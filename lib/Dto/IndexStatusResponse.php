<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

/** The progress contract deliberately excludes credentials and provider probes. */
final class IndexStatusResponse {
    public function __construct(
        public readonly bool $indexing,
        public readonly bool $indexStopping,
        public readonly string $indexMode,
        public readonly string $lastStarted,
        public readonly string $lastFinished,
        public readonly string $lastError,
        public readonly string $retrievalBackend,
    ) {}

    public function toArray(): array {
        return get_object_vars($this);
    }
}
