<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Runtime registry for third-party EVA tools.
 *
 * Apps register plugins from a ToolPluginRegisterEvent. Names are required to
 * be prefixed with `plugin_` so a plugin can never shadow a built-in tool.
 */
class ToolPluginRegistry {
    /** @var array<string,array{plugin:ToolPluginInterface,definition:array<string,mixed>}> */
    private array $tools = [];

    public function register(ToolPluginInterface $plugin): void {
        foreach ($plugin->getToolDefinitions() as $definition) {
            if (!is_array($definition)) continue;
            $name = strtolower(trim((string)($definition['name'] ?? '')));
            if (!preg_match('/^plugin_[a-z0-9][a-z0-9_-]{1,63}$/', $name)) continue;
            $description = trim((string)($definition['description'] ?? ''));
            $parameters = $definition['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if ($description === '' || !is_array($parameters)) continue;
            $risk = (string)($definition['risk'] ?? ToolPolicy::RISK_READONLY);
            if (!in_array($risk, [ToolPolicy::RISK_READONLY, ToolPolicy::RISK_MUTATING, ToolPolicy::RISK_DESTRUCTIVE], true)) continue;
            $surfaces = array_values(array_filter(array_map('strval', (array)($definition['surfaces'] ?? [ToolPolicy::SURFACE_WEB])), static fn(string $surface): bool => in_array($surface, [ToolPolicy::SURFACE_WEB, ToolPolicy::SURFACE_TALK, ToolPolicy::SURFACE_TASKPROCESSING_CONFIRMED], true)));
            if ($surfaces === []) continue;
            $this->tools[$name] = ['plugin' => $plugin, 'definition' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
                'risk' => $risk,
                'surfaces' => $surfaces,
                'requiresConfirmation' => (bool)($definition['requiresConfirmation'] ?? $risk !== ToolPolicy::RISK_READONLY),
            ]];
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array {
        return $this->tools[$name] ?? null;
    }

    /** @return list<array{type:string,function:array<string,mixed>}> */
    public function definitionsForSurface(string $surface): array {
        $result = [];
        foreach ($this->tools as $entry) {
            $definition = $entry['definition'];
            if (!in_array($surface, $definition['surfaces'], true)) continue;
            $result[] = ['type' => 'function', 'function' => [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'parameters' => $definition['parameters'],
            ]];
        }
        return $result;
    }

    /** @return array{ok:bool,result?:mixed,error?:string} */
    public function execute(string $userId, string $name, array $arguments): array {
        $entry = $this->tools[$name] ?? null;
        if ($entry === null) return ['ok' => false, 'error' => 'Unknown plugin tool: ' . $name];
        try {
            return $entry['plugin']->execute($userId, $name, $arguments);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Plugin tool failed: ' . $e->getMessage()];
        }
    }
}
