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

    public function all(): array {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function ollamaUrl(): string {
        return rtrim($this->get('ollama_url'), '/');
    }
}