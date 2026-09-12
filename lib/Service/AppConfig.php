<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;

class AppConfig {
    public const APP = 'eva_ai';

    /**
     * User-facing personal settings. These are stored per user, but when a
     * user has no personal value they fall back to the admin-configured
     * instance value (occ config:app:set eva_ai …) instead of the hardcoded
     * default (Issue #73).
     */
    private const USER_SETTINGS = [
        'chat_provider', 'groq_model', 'ollama_url', 'embedding_model', 'chat_model', 'chat_model_fallback',
        'embedding_model_fallback', 'summary_model', 'top_k', 'chunk_size',
        'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path',
        'context_size', 'temperature', 'actions_enabled', 'exec_write_types',
        'exec_write_max_chars', 'exec_delete_mode',        'notify_on_complete',
        'mail_index_enabled', 'mail_index_max', 'talk_history_size',
        'talk_bot_trigger', 'talk_classify_all', 'exclude_paths',
        // Indexing Nextcloud Talk chat histories so answers can quote older
        // parts of a conversation. Off by default: a chat log is the most
        // personal data in the instance and must be opted into.
        'talk_index_enabled', 'talk_index_max_rooms', 'talk_index_max_messages',
        // Posting into Talk as the signed-in user. Off by default: writing a
        // message is an act in somebody's name, so the user opts in.
        'talk_write_enabled',
        'chat_retention_days', 'embed_batch_size', 'ocr_enabled', 'ocr_language',
        'ollama_keep_alive', 'followups_mode',
        // Web search: each user can enable/disable and choose their provider.
        // DuckDuckGo works without any API key; SearxNG/Brave/Tavily need
        // credentials configured at instance level.
        'web_search_enabled', 'web_search_provider',
    ];

    /**
     * Per-user runtime state: progress and hashes must never leak between
     * users and are never user-facing configuration, so they always fall
     * back to the hardcoded defaults - never to an instance value.
     */
    private const USER_STATE_KEYS = [
        'index_running', 'index_started', 'index_heartbeat', 'index_finished', 'last_index_processed',
        'last_index_total', 'last_index_error', 'last_index_cache_hits', 'last_index_cache_misses',
        'last_index_ollama_requests', 'last_index_failed', 'index_config_hash', 'index_mode',
        'index_cancel_requested', 'index_run_id', 'index_enrolled', 'knowledge_initialized',
    ];

    /** All keys that are stored on the per-user scope. */
    private const USER_SCOPED_KEYS = [...self::USER_SETTINGS, ...self::USER_STATE_KEYS];

    /**
     * Instance-wide keys that only an administrator may read or change.
     * Per-user web search settings (enabled, provider) have moved to USER_SETTINGS
     * so each user can individually enable DuckDuckGo or other providers.
     * Admin-only: weather tool, instance-wide web search infra (URL, key, limits).
     */
    public const ADMIN_SETTINGS = [
        'weather_tool_enabled',
        // Instance-level web search infrastructure: SearxNG URL, API keys,
        // result limits. Individual users choose whether to use them.
        'web_search_url',
        'web_search_max_results',
        'web_search_timeout',
        'web_search_safe_search',
        // Content enrichment: reading the result pages is what turns a list of
        // teasers into an answer, but it costs one request per page.
        'web_search_fetch_content',
        'web_search_content_chars',
        // How many hits are read and compared before the best ones are chosen,
        // and whether page images are collected and offered to the model.
        'web_search_candidates',
        'web_search_images',
        // Reading pages that only exist after JavaScript has run. This runs a
        // headless browser process on the server, so it is opt-in and the
        // settings page reports whether the server can actually do it.
        'web_search_browser',
        'web_search_browser_node',
        'web_search_browser_browsers_path',
        'web_search_browser_timeout',
        // Indexing throughput controls. These were previously only reachable
        // through `occ config:app:set`; the admin page exposed fields for them
        // that silently saved nothing because they were missing here.
        'index_max_concurrent',
        'index_job_max_seconds',
        'index_job_interval_minutes',
    ];

    public function isAdminSetting(string $key): bool {
        return in_array($key, self::ADMIN_SETTINGS, true);
    }

    private const DEFAULTS = [
        'index_enabled' => '0',
        'chat_provider' => 'ollama',
        'groq_model' => 'openai/gpt-oss-20b',
        'ollama_url' => 'http://127.0.0.1:11434',
        'embedding_model' => 'nomic-embed-text',
        'chat_model' => 'gemma4:cloud',
        // Optional comma-separated fallback chains (Issue #86): when the
        // primary model is not installed Ollama resolves the first installed
        // candidate of the matching capability instead of failing hard.
        'chat_model_fallback' => '',
        'embedding_model_fallback' => '',
        // Optional dedicated model for heavy text tasks (summarize, translate,
        // proofread, …). Empty means the chat chain is used (Issue #86).
        'summary_model' => '',
        'embed_batch_size' => '24',
        // How long Ollama keeps a model resident after the last request
        // ('5m', '30m', '1h', -1 = never unload). The default matches the
        // Ollama server default; a higher value avoids paying model load
        // latency on every chat message when the instance chats regularly.
        'ollama_keep_alive' => '5m',
        // 'fast' renders follow-up chips from language-aware templates without
        // a second model call; 'llm' generates them with a small extra request.
        'followups_mode' => 'fast',
        'ocr_enabled' => '0',
        'ocr_language' => 'eng',
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
        'talk_index_enabled' => '0',
        'talk_write_enabled' => '0',
        'talk_index_max_rooms' => '20',
        'talk_index_max_messages' => '200',
        'talk_history_size' => '50',
        'talk_bot_trigger' => 'Eva',
        'talk_classify_all' => '0',
        'exclude_paths' => '',
        // Automatic deletion of chats after N days of inactivity (0 = never,
        // Issue: chat retention). The background job removes the chats.
        'chat_retention_days' => '0',
        // Privacy-sensitive switches that only an administrator controls.
        // Opt-in by design: the weather and web search tools call external
        // services, so a fresh install never sends anything off the server.
        'weather_tool_enabled' => '1',
        'web_search_enabled' => '0',
        'web_search_provider' => 'duckduckgo',
        'web_search_url' => '',
        'web_search_max_results' => '8',
        'web_search_timeout' => '10',
        'web_search_safe_search' => '1',
        'web_search_fetch_content' => '1',
        'web_search_content_chars' => '2000',
        // Twelve hits are read and scored on their real content before the best
        // ones are returned: the first three engine hits are frequently the
        // wrong page, so the ranking must see enough candidates to reject them.
        'web_search_candidates' => '12',
        'web_search_images' => '1',
        // Off by default: it needs Node and Playwright on the server, and an
        // administrator should decide that rather than discover it.
        'web_search_browser' => '0',
        'web_search_browser_node' => '',
        // Empty means Playwright's own location (the web server account's
        // ~/.cache/ms-playwright). It is only needed when the browser build
        // lives somewhere that account's home does not point at.
        'web_search_browser_browsers_path' => '',
        'web_search_browser_timeout' => '20',
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
        'last_index_failed' => '0',
        'index_config_hash' => '',
        'index_mode' => 'idle',
        'index_cancel_requested' => '0',
        'index_run_id' => '',
        'index_enrolled' => '0',
        'knowledge_initialized' => '0',
        // Only the scheduler lock is global; it is not exposed as a user setting.
        'index_job_running' => '0',
        'index_job_started' => '',
        // Maximum wall-clock seconds one periodic IndexJob run may spend before
        // the next cron tick continues with the remaining users (Issue #112).
        'index_job_max_seconds' => '50',
        // Round-robin continuation marker: the last user a periodic run
        // finished, so later users are not starved by earlier slow ones.
        'index_job_last_user' => '',
        // Fair multi-user scheduling (Issue #142): how many index passes may
        // run concurrently across all users. Instance-wide (not per user);
        // the IndexScheduler clamps it to 1..16.
        'index_max_concurrent' => '2',
        // Scheduler queue state (Issue #142): JSON blobs kept at app scope so
        // every worker sees the same FIFO order.
        'index_scheduler_active' => '{}',
        'index_scheduler_queue' => '[]',
        // Durable stop request for the periodic background IndexJob (admin
        // action): the running tick aborts at the next user boundary and the
        // following tick acknowledges (clears) the flag without starting.
        'index_job_stop_requested' => '0',
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
        'talk_index_max_rooms' => [1, 200],
        'talk_index_max_messages' => [10, 1000],
        'talk_history_size' => [1, 500],
        'chat_retention_days' => [0, 3650],
        'embed_batch_size' => [1, 200],
        'web_search_max_results' => [1, 20],
        'web_search_timeout' => [1, 30],
        'web_search_content_chars' => [200, 8000],
        'web_search_candidates' => [3, 20],
        'web_search_browser_timeout' => [3, 60],
        'index_max_concurrent' => [1, 16],
        'index_job_max_seconds' => [10, 600],
        'index_job_interval_minutes' => [1, 60],
    ];

    /** Accepted formats for the Ollama keep_alive setting (Issue: model residency). */
    private const KEEP_ALIVE_PATTERN = '/^(?:-1|(?:[1-9][0-9]{0,4}(?:ms|s|m|h)?))$/D';

    private ?string $userId = null;

    public function __construct(private IConfig $config) {
    }

    /** Set the user whose personal settings should override instance defaults. */
    public function setUserId(?string $userId): void {
        $this->userId = $userId !== null && $userId !== '' ? $userId : null;
    }

    private function isUserSetting(string $key): bool {
        return in_array($key, self::USER_SCOPED_KEYS, true);
    }

    private function isUserStateKey(string $key): bool {
        return in_array($key, self::USER_STATE_KEYS, true);
    }

    public function userId(): ?string { return $this->userId; }

    public function get(string $key): string {
        if ($this->userId !== null && $this->isUserSetting($key)) {
            $sentinel = "\0eva_ai_missing\0";
            $userValue = $this->config->getUserValue($this->userId, self::APP, $key, $sentinel);
            if ($userValue !== $sentinel) {
                return (string)$userValue;
            }
            if ($this->isUserStateKey($key)) {
                // Runtime state never inherits an instance-wide value.
                return self::DEFAULTS[$key] ?? '';
            }
            // Personal settings fall back to the admin-configured instance
            // value, and only if that is empty to the built-in default. This
            // makes `occ config:app:set eva_ai <key> <value>` effective for
            // every user who has not explicitly chosen their own value while
            // never leaking another user's personal value (Issue #73).
            return $this->appValue($key);
        }
        return $this->appValue($key);
    }

    private function appValue(string $key): string {
        $value = $this->config->getAppValue(self::APP, $key, self::DEFAULTS[$key] ?? '');
        if (!is_string($value) || $value === '') {
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

    /**
     * How long a run may go without a liveness signal before it is abandoned.
     *
     * Shared on purpose: this rule used to be written out three times with
     * slightly different details (the API controller, the cron job and the lock
     * guard), so a run left behind by a dead worker could be recovered by one
     * caller and still block another.
     */
    public const STALE_RUN_SECONDS = 900;

    /**
     * The shorter window that applies while a cancellation is pending.
     *
     * A worker is expected to notice a stop request promptly, so waiting the
     * full window would keep a stopped run looking alive.
     */
    public const CANCEL_GRACE_SECONDS = 300;

    /**
     * Release the claim of a run whose worker is gone.
     *
     * A process that is killed mid-run - a fatal error, a timeout, a reboot -
     * never reaches its cleanup, so its claim stays behind and every later pass
     * reports "already running" and does nothing. The heartbeat is the only
     * liveness signal there is, so a claim whose signal is older than the
     * window is declared abandoned. Called by every entry point that starts a
     * run, so a stale claim can never block one of them only.
     *
     * @return bool whether an abandoned claim was released
     */
    public function recoverAbandonedRun(): bool
    {
        if ($this->get('index_running') !== '1') {
            return false;
        }
        $heartbeat = (int)$this->get('index_heartbeat');
        // A run that never wrote a heartbeat falls back to its start time; a run
        // with neither is treated as old rather than as fresh.
        $since = $heartbeat > 0 ? $heartbeat : (int)$this->get('index_started');
        $age = $since > 0 ? time() - $since : PHP_INT_MAX;
        $cancelling = $this->get('index_cancel_requested') === '1';
        if ($age <= self::STALE_RUN_SECONDS && !($cancelling && $age > self::CANCEL_GRACE_SECONDS)) {
            return false;
        }
        $this->set('index_running', '0');
        $this->set('index_mode', 'idle');
        $this->set('index_cancel_requested', '0');
        $this->set('index_run_id', '');
        $this->set('index_heartbeat', '');
        return true;
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

    /**
     * Remove every eva_ai value stored for one user (personal settings AND
     * per-user runtime state) when their account is deleted (Issue #83).
     */
    public function deleteUserValues(string $userId): void {
        foreach ([...self::USER_SCOPED_KEYS, ProviderCredentials::KEY] as $key) {
            try {
                $this->config->deleteUserValue($userId, self::APP, $key);
            } catch (\Throwable $e) {
                // Best effort per key; a failing deletion must not abort the rest.
            }
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
        if ($key === 'chat_provider') return is_string($value) && in_array($value, ['ollama', 'groq'], true) ? null : 'must be ollama or groq';
        if ($key === 'groq_model') return is_string($value) && in_array($value, Groq::MODELS, true) ? null : 'must be a supported Groq free-plan chat model';
        if ($key === 'ocr_language') {
            return is_string($value) && preg_match('/^[a-zA-Z0-9_]{1,24}(?:\+[a-zA-Z0-9_]{1,24}){0,3}$/D', $value)
                ? null : 'must be a Tesseract language identifier or up to four identifiers joined with +';
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
        if ($key === 'web_search_provider') {
            return is_string($value) && in_array($value, WebSearchService::PROVIDERS, true)
                ? null : 'must be one of: ' . implode(', ', WebSearchService::PROVIDERS);
        }
        if ($key === 'web_search_url') {
            if (!is_scalar($value)) {
                return 'must be an http(s) URL or empty';
            }
            $url = trim((string)$value);
            if ($url === '') {
                return null;
            }
            if (preg_match('~^https?://[^\s]+$~i', $url) !== 1) {
                return 'must be an http(s) URL or empty';
            }
            return null;
        }
        if ($key === 'web_search_browser_browsers_path') {
            if (!is_scalar($value)) {
                return 'must be a path to the Playwright browsers directory, or empty';
            }
            $path = trim((string)$value);
            if ($path === '') {
                return null;
            }
            // Passed to the renderer as an environment variable rather than
            // through a shell, and still restricted to a plain absolute path:
            // this value chooses every executable the browser loads, so nothing
            // with whitespace or metacharacters belongs in it.
            if (preg_match('~^/[A-Za-z0-9._/+\-]{1,255}$~', $path) === 1) {
                return null;
            }
            return 'must be an absolute path without spaces, or empty';
        }
        if ($key === 'web_search_browser_node') {
            if (!is_scalar($value)) {
                return 'must be a path to the Node.js executable, or empty';
            }
            $path = trim((string)$value);
            if ($path === '') {
                return null;
            }
            // This value becomes the executable of a child process. It is passed
            // to proc_open as an argument array, so no shell ever parses it, and
            // it is still restricted to a plain path or command name: a stored
            // value with spaces or shell metacharacters can only be a mistake or
            // an attempt to smuggle one.
            if (preg_match('~^/[A-Za-z0-9._/+\-]{1,255}$~', $path) === 1) {
                return null;
            }
            if (preg_match('~^[A-Za-z0-9._+\-]{1,64}$~', $path) === 1) {
                return null;
            }
            return 'must be an absolute path or a command name, without spaces';
        }
        if (in_array($key, ['ocr_enabled', 'actions_enabled', 'notify_on_complete', 'mail_index_enabled', 'talk_index_enabled', 'talk_write_enabled', 'index_enrolled', 'talk_classify_all', 'weather_tool_enabled', 'web_search_enabled', 'web_search_safe_search', 'web_search_fetch_content', 'web_search_images', 'web_search_browser'], true)) {
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
        if (in_array($key, ['chat_model_fallback', 'embedding_model_fallback'], true)) {
            if (!is_scalar($value)) {
                return 'must be a comma-separated list of model names';
            }
            $raw = trim((string)$value);
            if ($raw === '') {
                return null;
            }
            $models = explode(',', $raw);
            if (count($models) > 8) {
                return 'must contain at most 8 models';
            }
            foreach ($models as $model) {
                $model = trim($model);
                if ($model === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/', $model) !== 1) {
                    return 'must be a comma-separated list of model names';
                }
            }
            return null;
        }
        if ($key === 'summary_model'
            && is_scalar($value) && trim((string)$value) !== ''
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/', trim((string)$value)) !== 1) {
            return 'must be a model name or empty';
        }
        if ($key === 'ollama_keep_alive'
            && (!is_scalar($value) || preg_match(self::KEEP_ALIVE_PATTERN, trim((string)$value)) !== 1)) {
            return 'must be -1, seconds, or a duration like 10m, 1h, 500ms';
        }
        if ($key === 'followups_mode'
            && (!is_scalar($value) || !in_array((string)$value, ['fast', 'llm'], true))) {
            return 'must be fast or llm';
        }
        // Numeric limits are validated by range before the generic admin-scope
        // fallback, so an admin-scope integer (index_max_concurrent,
        // index_job_max_seconds, web_search_max_results, …) is range-checked
        // instead of being rejected as a non-boolean.
        if (array_key_exists($key, self::LIMITS)) {
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
        if (self::isAdminSettingStatic($key)) {
            // Any remaining admin-scope key is a boolean toggle.
            return is_scalar($value) && in_array((string)$value, ['0', '1', 'true', 'false', 'on', 'off'], true)
                ? null : 'must be a boolean value';
        }
        return null;
    }

    private static function isAdminSettingStatic(string $key): bool {
        return in_array($key, self::ADMIN_SETTINGS, true);
    }

    /**
     * Read the instance-wide admin settings (web search, weather tool).
     * The web search API key is intentionally absent: it is write-only and
     * surfaced only as a boolean through the admin API.
     *
     * @return array<string,string>
     */
    public function adminAll(): array {
        $out = [];
        foreach (self::ADMIN_SETTINGS as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    /**
     * Return only settings that belong to the current user.
     * Global scheduler/legacy values must never be exposed through the user API.
     */
    public function all(): array {
        $out = [];
        foreach (self::USER_SCOPED_KEYS as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function ollamaUrl(): string {
        return rtrim($this->get('ollama_url'), '/');
    }
}