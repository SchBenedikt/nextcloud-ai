<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Extension point for other Nextcloud apps to add a namespaced EVA tool.
 *
 * Plugins must return definitions with `name`, `description`, `parameters`,
 * `risk`, `surfaces` and `requiresConfirmation`. The executor still applies
 * the confirmation gate before calling execute(); plugins never bypass EVA's
 * normal user/surface boundary.
 */
interface ToolPluginInterface {
    /** @return list<array<string,mixed>> */
    public function getToolDefinitions(): array;

    /** @return array{ok:bool,result?:mixed,error?:string} */
    public function execute(string $userId, string $toolName, array $arguments): array;
}
