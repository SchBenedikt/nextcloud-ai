<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;

class AppConfig {
    public const APP = 'eva_ai';

    private const USER_SETTINGS = [
        'weather_enabled', 'talk_classification_enabled', 'personalization_enabled', 'ocr_enabled',
        'chat_fallback_models', 'summary_model', 'tool_model',
        'ollama_url', 'embedding_model', 'chat_model', 'top_k', 'chunk_size',
        'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path',
        'context_size', 'temperature', 'actions_enabled', 'exec_write_types',
        'exec_write_max_chars', 'exec_delete_mode', 'notify_on_complete',
        'mail_index_enabled', 'mail_index_max', 'talk_history_size',
        'talk_bot_trigger', 'exclude_paths',
        // Per-user index state: progress and hashes must never leak between users.
        'index_running', 'index_started', 'index_heartbeat', 'index_finished', 'last_index_processed',
        'last_index_total', 'last_index_error', 'last_index_cache_hits', 'last_index_cache_misses',
        'last_index_ollama_requests', 'index_config_hash', 'index_mode',
        'index_cancel_requested', 'index_run_id', 'index_enrolled', 'knowledge_initialized',
    ];

    // Only operational defaults may be inherited. State, consent and personal paths stay private.
    private const INHERITED_SETTINGS = ['ollama_url', 'embedding_model', 'chat_model', 'top_k',
        'chunk_size', 'chunk_overlap', 'max_file_size', 'max_files_per_run',
        'context_size', 'temperature', 'exec_write_types', 'exec_write_max_chars', 'exec_delete_mode'];

    private const DEFAULTS = [
        'weather_enabled' => '0', 'talk_classification_enabled' => '0', 'personalization_enabled' => '1',
        'ocr_enabled' => '0' => '0', 'chat_fallback_models' => '', 'summary_model' => '', 'tool_model' => '',
        'index_enabled' => '0',
        'ollama_url' => 'http://127.0.0.1:11434',
        'embedding_model' => 'nomic-embed-text',
        'chat_model' => 'gemma4:cloud',
        'top_k' => '6',
        'chunk_size' => '900',
        'chunk_overlap' => '120',
        'max_file_size' => '20971520',
        'max_files_per_run' => '40',
        'scope_path' => '',
        'index_user' => '',
        'context_size' => '12288',
        'temperature' => '0.1',
        'actions_enabled' => '1',
        'exec_write_types' => '',
        'exec_write_max_chars' => '100000',
        'exec_delete_mode' => 'own',
        'notify_on_complete' => '1',
        'mail_index_enabled' => '1',
        'mail_index_max' => '25',
        'talk_history_size' => '50',
        'talk_bot_trigger' => 'Eva',
        'exclude_paths' => '',
        'index_running' => '0',
        'index_started' => '',
        'index_heartbeat' => '',
        'index_finished' => '0',
        'last_index_processed' => '0',
        'last_index_total' => '0',
        'last_index_error' => '',
        'last_index_cache_hits' => '0',
        'last_index_cache_misses' => '0',
        'last_index_ollama_requests' => '0',
        'index_config_hash' => '',
        'index_mode' => 'idle',
        'index_cancel_requested' => '0',
        'index_run_id' => '',
        'index_enrolled' => '0',
        'knowledge_initialized' => '0',
        // Only the scheduler lock is global; it is not exposed as a user setting.
        'index_job_running' => '0',
        'index_job_started' => '',
    ];

    /**
     * The single source of truth for user-controlled resource limits. Values
     * are deliberately conservative because they are also enforced by the
     * background worker, not just by the settings form.
     */
    public const LIMITS = [
        'top_k' => [1, 8],
        'chunk_size' => [128, 10000],
        'chunk_overlap' => [0, 5000],
        'max_file_size' => [1048576, 2147483648],
        'max_files_per_run' => [1, 10000],
        'context_size' => [256, 131072],
        'temperature' => [0.0, 2.0],
        'exec_write_max_chars' => [1, 10000000],
        'mail_index_max' => [1, 500],
        'talk_history_size' => [1, 500],
    ];

    private ?string $userId = null;

    public function __construct(private IConfig $config) {
    }

    /** Set the user whose personal settings should override instance defaults. */
    public function setUserId(?string $userId): void {
        $this->userId = $userId !== null && $userId !== '' ? $userId : null;
    }

    private function isUserSetting(string $key): bool {
        return in_array($key, self::USER_SETTINGS, true);
    }

    public function get(string $key): string {
        if ($this->userId !== null && $this->isUserSetting($key)) {
            $sentinel = "\0eva_ai_missing\0";
            $userValue = $this->config->getUserValue($this->userId, self::APP, $key, $sentinel);
            if ($userValue !== $sentinel) {
                return (string)$userValue;
            }
            if (in_array($key, self::INHERITED_SETTINGS, true)) {
                return $this->config->getAppValue(self::APP, $key, self::DEFAULTS[$key] ?? '');
            }
            return self::DEFAULTS[$key] ?? '';
        }
        $value = $this->config->getAppValue(self::APP, $key, self::DEFAULTS[$key] ?? '');
        if ($value === '') {
            return self::DEFAULTS[$key] ?? '';
        }
        return $value;
    }

    public function getInt(string $key, ?int $default = null): int {
        $value = $this->get($key);
        $default = $default ?? (int)(self::DEFAULTS[$key] ?? 0);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    public function set(string $key, string $value): void {
        if ($this->userId !== null && $this->isUserSetting($key)) {
            $this->config->setUserValue($this->userId, self::APP, $key, $value);
            return;
        }
        $this->config->setAppValue(self::APP, $key, $value);
    }

    public function increment(string $key): void {
        $this->config->setAppValue(self::APP, $key, (string)((int)$this->get($key) + 1));
    }

    /**
     * Atomically claim a user's index state where possible. The precondition
     * prevents two web/cron workers from both entering the mutation pipeline.
     */
    /** @return list<string> users that explicitly enabled recurring indexing */
    public function enrolledUserIds(): array {
        try {
            $users = $this->config->getUsersForUserValue(self::APP, 'index_enrolled', '1');
            return array_values(array_unique(array_filter(array_map('strval', $users), static fn(string $user): bool => $user !== '')));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function hasIndexEnrollment(string $userId): bool {
        $sentinel = "\0eva_ai_missing\0";
        try {
            return $this->config->getUserValue($userId, self::APP, 'index_enrolled', $sentinel) !== $sentinel;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isIndexEnrolled(string $userId): bool {
        $previous = $this->userId;
        $this->setUserId($userId);
        try {
            return $this->get('index_enrolled') === '1';
        } finally {
            $this->userId = $previous;
        }
    }

    public function setIndexEnrolled(string $userId, bool $enabled): void {
        if ($userId === '') {
            return;
        }
        $previous = $this->userId;
        $this->setUserId($userId);
        try {
            $this->set('index_enrolled', $enabled ? '1' : '0');
        } finally {
            $this->userId = $previous;
        }
    }

    public function tryClaimIndex(string $userId): bool {
        $previous = $this->userId;
        $this->setUserId($userId);
        try {
            $sentinel = "\0eva_ai_missing\0";
            $current = $this->config->getUserValue($userId, self::APP, 'index_running', $sentinel);
            if ($current === $sentinel) {
                // The first claim has no competing stored value yet. Persist a
                // default before using the conditional update on later runs.
                $this->config->setUserValue($userId, self::APP, 'index_running', '0');
            }
            $this->config->setUserValue($userId, self::APP, 'index_running', '1', '0');
            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->userId = $previous;
        }
    }

    /** @return array<string,array{0:int|float,1:int|float}> */
    public function limits(): array {
        return self::LIMITS;
    }

    /**
     * Normalize values whose storage format is shared by the settings UI and
     * action executor.
     */
    public function normalizeValue(string $key, mixed $value): mixed {
        if ($key !== 'exec_write_types' || !is_scalar($value)) {
            return $value;
        }
        $raw = trim((string)$value);
        if ($raw === '' || $raw === '*') {
            return $raw;
        }
        $types = [];
        foreach (explode(',', $raw) as $type) {
            $type = strtolower(trim($type));
            $type = ltrim($type, '.');
            if ($type !== '' && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
        return implode(',', $types);
    }

    /**
     * Validate a value without coercing invalid input. Returns an error for
     * malformed, non-numeric, or out-of-range values.
     */
    public function validateValue(string $key, mixed $value): ?string {
        if (in_array($key, ['chat_fallback_models', 'summary_model', 'tool_model'], true)) {
            if (!is_string($value) || strlen($value) > 400 || preg_match('/[\r\n\x00]/', $value)) { return 'must be a bounded model name or list'; }
            if ($key === 'chat_fallback_models' && count(explode(',', $value)) > 3) { return 'must contain at most three fallback models'; }
            return null;
        }
        if ($key === 'exec_write_types') {
            if (!is_scalar($value)) {
                return 'must be a comma-separated list of file extensions';
            }
            $raw = trim((string)$value);
            if ($raw === '' || $raw === '*') {
                return null;
            }
            $types = array_map(static fn(string $type): string => ltrim(strtolower(trim($type)), '.'), explode(',', $raw));
            if (count($types) > 32 || in_array('*', $types, true)) {
                return 'must contain at most 32 file extensions and may not mix * with extensions';
            }
            foreach ($types as $type) {
                if ($type === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/', $type) !== 1) {
                    return 'must be a comma-separated list of file extensions (for example md,txt,csv)';
                }
            }
            return null;
        }
        if (in_array($key, ['actions_enabled', 'notify_on_complete', 'mail_index_enabled', 'index_enrolled', 'weather_enabled', 'talk_classification_enabled', 'personalization_enabled', 'ocr_enabled'], true)) {
            return is_scalar($value) && in_array((string)$value, ['0', '1', 'true', 'false', 'on', 'off'], true)
                ? null : 'must be a boolean value';
        }
        if ($key === 'exec_delete_mode' && (!is_scalar($value) || !in_array((string)$value, ['off', 'own', 'all'], true))) {
            return 'must be one of: off, own, all';
        }
        if (in_array($key, ['embedding_model', 'chat_model', 'talk_bot_trigger'], true)
            && (!is_scalar($value) || trim((string)$value) === '')) {
            return 'must not be empty';
        }
        if (!array_key_exists($key, self::LIMITS)) {
            return null;
        }
        [$min, $max] = self::LIMITS[$key];
        if ($key === 'temperature') {
            if (!is_numeric($value)) {
                return 'must be a number';
            }
            $number = (float)$value;
        } else {
            if ((is_array($value) || is_object($value) || filter_var($value, FILTER_VALIDATE_INT) === false)
                && !(is_string($value) && preg_match('/^-?\\d+$/', $value))) {
                return 'must be an integer';
            }
            $number = (int)$value;
        }
        if ($number < $min || $number > $max) {
            return 'must be between ' . $min . ' and ' . $max;
        }
        return null;
    }

    /**
     * Return only settings that belong to the current user.
     * Global scheduler/legacy values must never be exposed through the user API.
     */
    public function all(): array {
        $out = [];
        foreach (self::USER_SETTINGS as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function ollamaUrl(): string {
        return rtrim($this->get('ollama_url'), '/');
    }
}