<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Capability checks must never temporarily write a user's endpoint. */
final class ModelSelectionValidator {
    public function __construct(private AppConfig $config, private Ollama $ollama) {}
    public function validate(array $pending): array {
        $modelKeys = ['embedding_model', 'chat_model', 'summary_model'];
        if (!array_intersect(array_keys($pending), [...$modelKeys, 'ollama_url'])) {
            return [];
        }
        $errors = [];
        $endpoint = isset($pending['ollama_url']) ? trim((string)$pending['ollama_url']) : null;
        $caps = $this->ollama->capabilities($endpoint);
        if (empty($caps['available']) || empty($caps['models'])) {
            // Cannot verify capabilities (offline or still starting): do
            // not block saving, the connection check explains the state.
            return [];
        }
        $byLower = [];
        foreach ($caps['models'] as $name => $info) {
            $lower = strtolower($name);
            $byLower[$lower] = $info['roles'] ?? [];
            if (str_ends_with($lower, ':latest')) {
                $byLower[substr($lower, 0, -7)] = $info['roles'] ?? [];
            }
        }
        $expect = [
            'embedding_model' => 'embedding',
            'chat_model' => 'chat',
            'summary_model' => 'chat',
        ];
        foreach ($expect as $key => $role) {
            if (($pending['chat_provider'] ?? $this->config->get('chat_provider')) !== 'ollama' && $role === 'chat') continue;
            if (!isset($pending[$key]) || !is_scalar($pending[$key])) {
                continue;
            }
            $model = trim((string)$pending[$key]);
            if ($model === '') {
                continue;
            }
            $roles = $byLower[strtolower($model)] ?? null;
            if ($roles === null) {
                continue; // Not installed yet: allow, pull may be pending.
            }
            if (!in_array($role, $roles, true)) {
                $errors[] = $key . ' model "' . $model . '" does not support ' . $role
                    . ' (provider reports: ' . implode(', ', $roles) . ').';
            }
        }
        return $errors;
    }
}
