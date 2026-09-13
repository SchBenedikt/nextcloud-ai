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
            $surfaces = array_values(array_filter(array_map('strval', (array)($definition['surfaces'] ?? [ToolPolicy::SURFACE_WEB])), static fn(string $surface): bool => in_array($surface, [
                ToolPolicy::SURFACE_WEB,
                ToolPolicy::SURFACE_TALK,
                ToolPolicy::SURFACE_RAG,
                ToolPolicy::SURFACE_TASKPROCESSING,
                ToolPolicy::SURFACE_TASKPROCESSING_CONFIRMED,
            ], true)));
            if ($surfaces === []) continue;
            // The first registration wins. This makes tool ownership
            // deterministic and prevents a later app from silently replacing
            // another app's implementation for the same namespaced tool.
            if (isset($this->tools[$name])) continue;
            // Mutating and destructive tools are always confirmation-gated.
            // A plugin must not be able to opt out of EVA's safety boundary by
            // declaring requiresConfirmation=false in its metadata.
            $requiresConfirmation = $risk !== ToolPolicy::RISK_READONLY
                || (bool)($definition['requiresConfirmation'] ?? false);
            $this->tools[$name] = ['plugin' => $plugin, 'definition' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
                'risk' => $risk,
                'surfaces' => $surfaces,
                'requiresConfirmation' => $requiresConfirmation,
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
        $validationError = $this->validateArguments($entry['definition']['parameters'], $arguments);
        if ($validationError !== null) return ['ok' => false, 'error' => $validationError];
        try {
            $result = $entry['plugin']->execute($userId, $name, $arguments);
            if (!is_array($result)) return ['ok' => false, 'error' => 'Plugin tool returned an invalid result.'];
            $safe = ['ok' => !empty($result['ok'])];
            if (array_key_exists('result', $result)) {
                $budget = 50000;
                $safe['result'] = $this->sanitizeResult($result['result'], $budget);
            }
            if (isset($result['error'])) {
                $error = is_scalar($result['error']) ? (string)$result['error'] : 'Plugin tool failed.';
                $safe['error'] = mb_substr($this->sanitizeString($error), 0, 2000);
            }
            return $safe;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Plugin tool failed: ' . $e->getMessage()];
        }
    }

    /** Keep optional app output safe for chat history and execution traces. */
    private function sanitizeResult(mixed $value, int &$budget, int $depth = 0): mixed {
        if ($budget <= 0) return '[truncated]';
        if ($depth > 6) return '[nested result omitted]';
        if (is_string($value)) {
            $safe = $this->sanitizeString($value);
            $safe = mb_substr($safe, 0, min(10000, $budget));
            $budget -= mb_strlen($safe);
            return $safe;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        if (!is_array($value)) return '[unsupported result value]';
        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= 200 || $budget <= 0) { $out['…'] = '[truncated]'; break; }
            $name = (string)$key;
            if (preg_match('/(?:api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|password|secret|cookie|credential)/i', $name)) {
                $out[$name] = '[redacted]';
                continue;
            }
            $out[$name] = $this->sanitizeResult($item, $budget, $depth + 1);
        }
        return $out;
    }

    private function sanitizeString(string $value): string {
        return preg_replace('/(Bearer\s+|(?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)\s*[:=]\s*)([^\s,;]+)/i', '$1[redacted]', $value) ?? $value;
    }

    /** Validate the bounded JSON-schema subset exposed to the model. */
    private function validateArguments(array $schema, array $arguments): ?string {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
            foreach ($arguments as $key => $_value) {
                if (!array_key_exists((string)$key, $properties)) return 'Unknown plugin argument: ' . (string)$key;
            }
        }
        foreach ((array)($schema['required'] ?? []) as $required) {
            $required = (string)$required;
            if (!array_key_exists($required, $arguments)) return 'Missing required plugin argument: ' . $required;
        }
        foreach ($properties as $name => $definition) {
            if (!array_key_exists((string)$name, $arguments) || !is_array($definition)) continue;
            $value = $arguments[(string)$name];
            $type = (string)($definition['type'] ?? '');
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value),
                default => true,
            };
            if (!$valid) return 'Invalid type for plugin argument: ' . (string)$name;
            if (is_string($value)) {
                $length = mb_strlen($value);
                if (isset($definition['minLength']) && $length < (int)$definition['minLength']) return 'Plugin argument is too short: ' . (string)$name;
                if (isset($definition['maxLength']) && $length > (int)$definition['maxLength']) return 'Plugin argument is too long: ' . (string)$name;
            }
        }
        return null;
    }
}
