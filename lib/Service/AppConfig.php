<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;

class AppConfig {
    public const APP = 'eva-ai';

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

    public function __construct(private IConfig $config) {
    }

    public function get(string $key): string {
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