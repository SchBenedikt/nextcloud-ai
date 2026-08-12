<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;

class AppConfig {
    public const APP = 'eva_ai';

    private const USER_SETTINGS = [
        'ollama_url', 'embedding_model', 'chat_model', 'top_k', 'chunk_size',
        'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path',
        'context_size', 'temperature', 'actions_enabled', 'exec_write_types',
        'exec_write_max_chars', 'exec_delete_mode', 'notify_on_complete',
        'mail_index_enabled', 'mail_index_max', 'talk_history_size',
        'talk_bot_trigger', 'exclude_paths',
        // Per-user index state: progress and hashes must never leak between users.
        'index_running', 'index_started', 'index_finished', 'last_index_processed',
        'last_index_total', 'last_index_error', 'index_config_hash', 'index_mode',
        'index_cancel_requested', 'index_run_id',
    ];

    private const DEFAULTS = [
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
        'index_finished' => '0',
        'last_index_processed' => '0',
        'last_index_total' => '0',
        'last_index_error' => '',
        'index_config_hash' => '',
        'index_mode' => 'idle',
        'index_cancel_requested' => '0',
        'index_run_id' => '',
        // Only the scheduler lock is global; it is not exposed as a user setting.
        'index_job_running' => '0',
        'index_job_started' => '',
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
            // Personal settings must never inherit another user's or an old
            // instance-wide value. New users receive only the app default.
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
        return (int)$value ?: $default;
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