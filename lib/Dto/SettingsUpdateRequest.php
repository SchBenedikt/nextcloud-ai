<?php

declare(strict_types=1);
namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** The settings wire format is decoded once; storage never receives raw JSON. */
final class SettingsUpdateRequest {
    public const FIELDS = [
            'chat_provider', 'groq_model', 'custom_provider_url', 'custom_provider_model', 'provider_profiles', 'generation_embedding_profile_id', 'ollama_url', 'embedding_model', 'chat_model', 'chat_model_fallback',
            'embedding_model_fallback', 'summary_model', 'top_k', 'chunk_size',
            'chunk_overlap', 'max_file_size', 'max_files_per_run', 'index_storage_quota', 'scope_path', 'context_size', 'temperature',
            'actions_enabled', 'background_actions_enabled', 'learning_enabled', 'safe_commands_enabled', 'terminal_commands_enabled', 'terminal_command_any', 'terminal_command_allowlist', 'agent_max_tool_rounds',
            'exec_write_types', 'exec_write_max_chars', 'exec_delete_mode',
            'notify_on_complete',
            'voice_input_enabled',
            'proactive_enabled', 'proactive_schedules',
            'mail_index_enabled',
            'mail_index_max',
            'embed_batch_size', 'ocr_enabled', 'ocr_language',
            'ollama_keep_alive', 'followups_mode',
            // Shared provider infrastructure stays on the admin endpoint;
            // tool permissions and web search behavior are user settings.
            'talk_history_size',
            'talk_bot_trigger',
            'talk_classify_all',
            // Posting into Talk as the signed-in user (off by default).
            'talk_write_enabled',
            'exclude_paths',
            'index_enrolled',
            'chat_retention_days',
            // Per-user web search settings (Issue #187): each user may
            // individually enable web search and choose their provider.
            'web_search_enabled',
            'web_search_provider',
            'web_search_max_results', 'web_search_timeout', 'web_search_safe_search',
            'web_search_fetch_content', 'web_search_content_chars', 'web_search_candidates',
            'web_search_images', 'web_search_browser', 'web_search_browser_timeout',
        ];
    private function __construct(public readonly array $values) {}

    public static function fromArray(array $input): self {
        $values = [];
        foreach (self::FIELDS as $key) {
            $value = $input[$key] ?? null;
            if ($value === null) continue;
            if ($key === 'provider_profiles') {
                if (is_string($value)) {
                    try { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
                    catch (\JsonException) { throw new InvalidArgumentException('Provider profiles must contain valid JSON.'); }
                }
                if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException('Provider profiles must be a list.');
            } elseif (!is_scalar($value)) {
                throw new InvalidArgumentException($key . ' must be a scalar setting.');
            }
            $values[$key] = $value;
        }
        return new self($values);
    }
}
