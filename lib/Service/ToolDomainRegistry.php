<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Dispatches service-backed tools independently from ActionExecutor's file
 * and policy plumbing. Handlers always receive the effective user and args.
 */
final class ToolDomainRegistry {
    /** @var array<string,callable(string,array):array> */
    private array $handlers = [];

    /** @param callable(string,array):array $handler */
    public function register(string $tool, callable $handler): void {
        $this->handlers[$tool] = $handler;
    }

    public function has(string $tool): bool {
        return isset($this->handlers[$tool]);
    }

    /** @return array{ok:bool,result?:mixed,error?:string}|null */
    public function execute(string $tool, string $userId, array $args): ?array {
        if (!$this->has($tool)) return null;
        return ($this->handlers[$tool])($userId, $args);
    }
}
