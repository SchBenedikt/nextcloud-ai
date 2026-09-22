<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

/** Immutable input for one RAG chat request. */
final class ChatRequest {
    /** @param array<int,array{role:string,content:string}> $history */
    public function __construct(
        public readonly string $userId,
        public readonly string $message,
        public readonly array $history = [],
        public readonly ?string $scopePath = null,
        public readonly ?string $instructions = null,
        public readonly ?string $persona = null,
        public readonly ?string $extraContext = null,
        public readonly bool $allowActions = true,
        public readonly bool $autonomousActions = false,
        ?callable $shouldStop = null,
        ?callable $onProgress = null,
    ) {
        $this->shouldStop = $shouldStop !== null ? \Closure::fromCallable($shouldStop) : null;
        $this->onProgress = $onProgress !== null ? \Closure::fromCallable($onProgress) : null;
    }

    public readonly ?\Closure $shouldStop;
    public readonly ?\Closure $onProgress;
}
