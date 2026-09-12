<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\BackgroundJob\IndexRequestJob;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EvaAi\Http\StreamTraversableResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;

class ApiController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private RagService $ragService,
        private ActionExecutor $executor,
        private Indexer $indexer,
        private Ollama $ollama,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private IJobList $jobList,
        private ILockingProvider $lockingProvider,
        private ChatStore $chatStore,
        private FileContextChatService $fileContextChat,
        private IAppManager $appManager,
        private KnowledgeInitializer $knowledgeInitializer,
        private LockGuard $lockGuard,
        private \OCA\EvaAi\Service\UserDataService $userDataService,
        private \OCA\EvaAi\Service\ChatLearner $chatLearner,
        private ICacheFactory $cacheFactory,
        private \OCA\EvaAi\Service\IndexScheduler $indexScheduler,
        private \OCA\EvaAi\Service\UsageMetrics $usageMetrics,
        private \OCA\EvaAi\Service\BackgroundChatQueue $backgroundChatQueue
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
    }

    /**
     * Read JSON bodies once without shadowing framework/controller parameter APIs.
     * IRequest exposes query/form parameters directly, while JSON bodies need
     * this small fallback on the supported Nextcloud versions.
     */
    private ?array $bodyParams = null;

    private function requestBody(): array {
        if ($this->bodyParams !== null) {
            return $this->bodyParams;
        }
        $raw = (string)file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $this->bodyParams = is_array($decoded) ? $decoded : [];
        return $this->bodyParams;
    }

    private function requestParam(string $key, mixed $default = null): mixed {
        $value = $this->request->getParam($key, null);
        if ($value !== null) {
            return $value;
        }
        $body = $this->requestBody();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    private function requireUser(): ?string {
        return $this->userId ?: null;
    }

    /**
     * Map chat-storage failures to an actionable response (Issue #184). A
     * corrupt store is preserved by design (the #173 fail-safe) and points
     * the user to the admin recovery command instead of a generic 500.
     */
    private function chatErrorResponse(\Throwable $e): DataResponse {
        // A temporarily locked chat store is a busy condition, not an error:
        // the page load fires several chat reads in parallel and a crashed
        // request can hold the lock until the backend TTL expires. A 503 lets
        // the frontend show "please retry" instead of taking the app down
        // with an opaque 500.
        if ($e instanceof \OCA\EvaAi\Service\ChatStoreBusyException) {
            return new DataResponse(['error' => 'busy', 'message' => $e->getMessage()], 503);
        }
        $message = $e->getMessage();
        if (str_contains($message, 'Invalid EVA chat data')
            || str_contains($message, 'Invalid EVA folder registry')) {
            return new DataResponse([
                'error' => 'corrupt_store',
                'message' => 'The EVA chat storage for this user is corrupt and was preserved. An administrator can recover it with: occ eva_ai:repair-chats <user>',
            ], 500);
        }
        return new DataResponse(['error' => 'Unable to persist chat data'], 500);
    }

    #[NoAdminRequired]
    public function status(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        $status = $this->ragService->buildStatus($user);
        try {
            $status['genericApi'] = [
                'tokenConfigured' => \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->nextcloudTokenConfigured($user),
            ];
        } catch (\Throwable) {
            $status['genericApi'] = ['tokenConfigured' => false];
        }
        // Fair multi-user scheduling snapshot (Issue #142): global running
        // count, limit and this user's queue position - cheap, no polling.
        $status['scheduler'] = $this->indexScheduler->snapshot($user);
        return new DataResponse($status);
    }

    /**
     * Dashboard summary (Issue: app home): document/chunk/size aggregates,
     * chat counts + recent chats, folder count and a slim status snapshot.
     */
    #[NoAdminRequired]
    public function stats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $this->knowledgeInitializer->ensureInitialized($user);
            $agg = $this->documentMapper->aggregateForUser($user);
            $status = $this->ragService->buildStatus($user);
            $chats = $this->chatStore->list($user, null, true);
            $active = array_values(array_filter($chats, static fn($c) => empty($c['archived'])));
            $recent = $active;
            usort($recent, static fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
            return new DataResponse([
                'documents' => [
                    'count' => $agg['count'],
                    'chunks' => $agg['chunks'],
                    'size' => $agg['size'],
                ],
                'chats' => [
                    'total' => count($chats),
                    'active' => count($active),
                    'archived' => count($chats) - count($active),
                    'recent' => array_slice($recent, 0, 6),
                ],
                'folders' => count($this->chatStore->listFolders($user)),
                'status' => [
                    'ollamaOnline' => (bool)($status['ollamaOnline'] ?? false),
                    'ollamaError' => $status['ollamaError'] ?? null,
                    'chatModel' => $status['chatModel'] ?? '',
                    'embeddingModel' => $status['embeddingModel'] ?? '',
                    'indexing' => (bool)($status['indexing'] ?? false),
                    'lastFinished' => $status['lastFinished'] ?? null,
                    'lastError' => $status['lastError'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof \OCA\EvaAi\Service\ChatStoreBusyException) {
                return new DataResponse(['error' => 'busy', 'message' => $e->getMessage()], 503);
            }
            return new DataResponse(['error' => 'Unable to build dashboard summary'], 500);
        }
    }

    /** Privacy-preserving model usage for the signed-in user. */
    #[NoAdminRequired]
    public function metrics(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        return new DataResponse($this->usageMetrics->summaryForUser($user));
    }

    /** Lightweight diagnostics for troubleshooting a slow or incomplete install. */
    #[NoAdminRequired]
    public function health(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $cache = $this->cacheFactory->createDistributed('eva_ai_health_');
        $cacheKey = 'health_' . substr(hash('sha256', $user), 0, 24);
        $cached = $cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) return new DataResponse($decoded, !empty($decoded['ok']) ? 200 : 503);
        }
        $provider = $this->ollama->status();
        $queue = $this->backgroundChatQueue->status($user);
        $activeQueue = count(array_filter($queue, static fn(array $item): bool => in_array($item['status'] ?? '', ['pending', 'running'], true)));
        $lastIndexError = (string)$this->config->get('index_error');
        $checks = [
            'provider' => (bool)($provider['ping']['ok'] ?? false),
            'chat_model' => $this->ollama->selectedChatModel() !== '',
            'index' => $lastIndexError === '',
            'queue' => $activeQueue < 10,
        ];
        $payload = [
            'ok' => !in_array(false, $checks, true),
            'checks' => $checks,
            'provider' => ['online' => $checks['provider'], 'model' => $this->ollama->selectedChatModel(), 'latency_ms' => $provider['meta']['latencyMs'] ?? null],
            'queue' => ['active' => $activeQueue, 'total' => count($queue)],
            'index' => ['last_error' => $lastIndexError !== '' ? $lastIndexError : null],
            'generated_at' => time(),
        ];
        try { $cache->set($cacheKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 10); } catch (\Throwable) { }
        return new DataResponse($payload, !in_array(false, $checks, true) ? 200 : 503);
    }

    /**
     * Time-of-day aware greeting for the dashboard hero. The text is generated
     * by the configured chat model once per user+period and cached for several
     * hours, so a dashboard load never blocks on Ollama; when the model is
     * unreachable a static greeting in the user's UI language is returned.
     */
    #[NoAdminRequired]
    public function greeting(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $period = $this->dayPeriod();
        $lang = $this->uiLanguage();
        $cacheKey = 'greeting_' . substr(hash('sha256', $user), 0, 16) . '_' . $period;
        $cache = $this->cacheFactory->createDistributed('eva_ai_greeting_');
        $cached = $cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return new DataResponse(['greeting' => $cached, 'period' => $period]);
        }
        $greeting = $this->generateGreeting($period, $lang);
        if ($greeting === '') {
            $greeting = $this->staticGreeting($period, $lang);
        }
        $cache->set($cacheKey, $greeting, 6 * 3600);
        return new DataResponse(['greeting' => $greeting, 'period' => $period]);
    }

    /** 'night'|'morning'|'afternoon'|'evening' based on the server's clock. */
    private function dayPeriod(): string {
        $h = (int)date('G');
        if ($h < 5) {
            return 'night';
        }
        if ($h < 12) {
            return 'morning';
        }
        if ($h < 18) {
            return 'afternoon';
        }
        return 'evening';
    }

    /** The user's Nextcloud UI language ('de', 'en', ...). */
    private function uiLanguage(): string {
        try {
            return \OCP\Server::get(\OCP\L10N\IFactory::class)->findLanguage('eva_ai');
        } catch (\Throwable $e) {
            return 'en';
        }
    }

    /**
     * One short AI-generated greeting sentence for the given period. Returns
     * an empty string when Ollama is offline or produced no usable text.
     */
    private function generateGreeting(string $period, string $lang): string {
        // Preserve the Groq quota for explicit user requests.
        if ($this->config->get('chat_provider') === 'groq') return '';
        try {
            $periodLabel = [
                'night' => 'at night',
                'morning' => 'in the morning',
                'afternoon' => 'in the afternoon',
                'evening' => 'in the evening',
            ][$period] ?? $period;
            $chat = $this->ollama->chat([
                ['role' => 'system', 'content' => 'You are EVA, the friendly assistant built into the user\'s Nextcloud. Reply with ONE short, warm greeting sentence (max 12 words) appropriate for the current time of day, in the user\'s language. No markdown, no emojis, no quotes, no question, no explanation.'],
                ['role' => 'user', 'content' => 'Current time of day: ' . $periodLabel . '. Language: ' . $lang],
            ], [], 30);
            if (isset($chat['error']) || empty($chat['answer'])) {
                return '';
            }
            $text = trim((string)$chat['answer']);
            $text = preg_replace('/["\r\n]+/', ' ', $text) ?? $text;
            return mb_substr($text, 0, 120);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Static fallback greeting in the user's UI language. */
    private function staticGreeting(string $period, string $lang): string {
        $de = ['night' => 'Gute Nacht', 'morning' => 'Guten Morgen', 'afternoon' => 'Guten Tag', 'evening' => 'Guten Abend'];
        $en = ['night' => 'Good night', 'morning' => 'Good morning', 'afternoon' => 'Good afternoon', 'evening' => 'Good evening'];
        $map = str_starts_with($lang, 'de') ? $de : $en;
        return $map[$period] ?? $map['morning'];
    }

    #[NoAdminRequired]
    public function settings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        return new DataResponse($this->config->all());
    }

    #[NoAdminRequired]
    public function saveSettings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Settings are locked while indexing is running.'], 409);
        }
        $allowed = [
            'chat_provider', 'groq_model', 'custom_provider_url', 'custom_provider_model', 'ollama_url', 'embedding_model', 'chat_model', 'chat_model_fallback',
            'embedding_model_fallback', 'summary_model', 'top_k', 'chunk_size',
            'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path', 'context_size', 'temperature',
            'actions_enabled', 'background_actions_enabled', 'learning_enabled', 'safe_commands_enabled', 'agent_max_tool_rounds',
            'exec_write_types', 'exec_write_max_chars', 'exec_delete_mode',
            'notify_on_complete',
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
        $validationErrors = [];
        $pending = [];
        foreach ($allowed as $key) {
            $value = $this->requestParam($key);
            if ($value === null) {
                continue;
            }
            $pending[$key] = $value;
            $limitError = $this->config->validateValue($key, $value);
            if ($limitError !== null) {
                $validationErrors[$key] = $key . ' ' . $limitError . '.';
            }
            if ($key === 'index_enrolled' && $this->config->validateValue($key, $value) !== null) {
                $validationErrors[$key] = $key . ' must be a boolean value.';
            }
            if ($key === 'ollama_url') {
                $urlError = $this->validateOllamaUrl((string)$value);
                if ($urlError !== null) {
                    $validationErrors[$key] = $urlError;
                }
            }
            if (in_array($key, ['scope_path', 'exclude_paths'], true)
                && preg_match('~(^|[\\\\/])\\.\\.?([\\\\/]|$)~', (string)$value)) {
                $validationErrors[$key] = $key . ' may not contain relative path traversal.';
            }
            if ($key === 'proactive_schedules') {
                $scheduleError = $this->validateProactiveSchedules($value);
                if ($scheduleError !== null) {
                    $validationErrors[$key] = $scheduleError;
                }
            }
        }
        if ($validationErrors !== []) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($validationErrors),
            ], 400);
        }
        $selectedProvider = (string)($pending['chat_provider'] ?? $this->config->get('chat_provider'));
        if ($selectedProvider !== 'ollama' && $selectedProvider !== 'groq') {
            $customUrl = trim((string)($pending['custom_provider_url'] ?? $this->config->get('custom_provider_url')));
            $customModel = trim((string)($pending['custom_provider_model'] ?? $this->config->get('custom_provider_model')));
            if ($customUrl === '' || $customModel === '') {
                return new DataResponse(['error' => 'Custom provider requires both an endpoint URL and model name.'], 400);
            }
            $parts = parse_url($customUrl);
            if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || empty($parts['host'])) {
                return new DataResponse(['error' => 'Custom provider endpoint must be a plain URL without credentials, query or fragment.'], 400);
            }
        }
        $groqKey = $this->requestParam('groq_api_key');
        $removeGroqKey = $this->requestParam('remove_groq_api_key', false);
        if (($groqKey !== null && (!is_string($groqKey) || ($groqKey !== '' && !preg_match('/^gsk_[A-Za-z0-9_-]{16,256}$/D', $groqKey))))
            || !in_array($removeGroqKey, [true, false, 0, 1, '0', '1'], true)) {
            return new DataResponse(['error' => 'Invalid Groq credential input.'], 400);
        }
        $roleErrors = $this->validateModelRoles($pending);
        if ($roleErrors !== []) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($roleErrors),
            ], 400);
        }
        if ($removeGroqKey) $this->ollama->saveGroqKey('');
        elseif (is_string($groqKey) && $groqKey !== '') $this->ollama->saveGroqKey($groqKey);
        $customKey = $this->requestParam('custom_provider_api_key');
        $removeCustomKey = $this->requestParam('remove_custom_provider_api_key', false);
        $providerId = (string)($pending['chat_provider'] ?? $this->config->get('chat_provider'));
        if ($customKey !== null && (!is_string($customKey) || strlen($customKey) > 512)) return new DataResponse(['error' => 'Invalid custom provider credential input.'], 400);
        if (!in_array($removeCustomKey, [true, false, 0, 1, '0', '1'], true)) return new DataResponse(['error' => 'Invalid custom provider credential input.'], 400);
        if ($providerId !== 'ollama' && $providerId !== 'groq') {
            $credentials = \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class);
            if (!$removeCustomKey && (!is_string($customKey) || $customKey === '') && !$credentials->customConfigured($user, $providerId)) {
                return new DataResponse(['error' => 'Save an API key for the selected custom provider first.'], 400);
            }
            if ($removeCustomKey) $credentials->saveCustom($user, $providerId, '');
            elseif (is_string($customKey) && $customKey !== '') $credentials->saveCustom($user, $providerId, $customKey);
        }
        $nextcloudToken = $this->requestParam('nextcloud_api_token');
        $removeNextcloudToken = $this->requestParam('remove_nextcloud_api_token', false);
        if ($nextcloudToken !== null && (!is_string($nextcloudToken) || strlen($nextcloudToken) > 512 || preg_match('/\s/', $nextcloudToken))) {
            return new DataResponse(['error' => 'Invalid Nextcloud app token input.'], 400);
        }
        if (!in_array($removeNextcloudToken, [true, false, 0, 1, '0', '1'], true)) {
            return new DataResponse(['error' => 'Invalid Nextcloud app token input.'], 400);
        }
        if ($removeNextcloudToken || (is_string($nextcloudToken) && $nextcloudToken !== '')) {
            \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->saveNextcloudToken(
                $user,
                $removeNextcloudToken ? '' : $nextcloudToken
            );
        }
        foreach ($pending as $key => $value) {
                if (in_array($key, ['top_k', 'chunk_size', 'chunk_overlap', 'max_file_size', 'max_files_per_run', 'context_size', 'exec_write_max_chars', 'mail_index_max', 'talk_history_size', 'talk_index_max_rooms', 'talk_index_max_messages', 'chat_retention_days', 'embed_batch_size', 'agent_max_tool_rounds', 'web_search_max_results', 'web_search_timeout', 'web_search_content_chars', 'web_search_candidates', 'web_search_browser_timeout'], true)) {
                    $value = (string)$value;
                }
                if ($key === 'exec_delete_mode') {
                    $value = in_array($value, ['off', 'own', 'all'], true) ? $value : 'own';
                }
                if ($key === 'exec_write_types') {
                    $value = $this->config->normalizeValue($key, $value);
                }
                if ($key === 'ocr_enabled' || $key === 'notify_on_complete' || $key === 'proactive_enabled' || $key === 'mail_index_enabled' || $key === 'index_enrolled' || $key === 'talk_classify_all' || $key === 'talk_index_enabled' || $key === 'talk_write_enabled' || $key === 'background_actions_enabled' || $key === 'learning_enabled' || $key === 'safe_commands_enabled' || $key === 'web_search_enabled' || $key === 'web_search_safe_search' || $key === 'web_search_fetch_content' || $key === 'web_search_images' || $key === 'web_search_browser') {
                    $value = in_array((string)$value, ['1', 'true', 'on'], true) ? '1' : '0';
                }
                if ($key === 'temperature') {
                    $value = (string)max(0.0, min(2.0, (float)$value));
                }
                if ($key === 'web_search_provider') {
                    $value = trim((string)$value);
                    if (!in_array($value, ['duckduckgo', 'bing', 'searxng', 'brave', 'tavily'], true)) {
                        $value = 'duckduckgo';
                    }
                }
                if ($key === 'ollama_keep_alive' || $key === 'followups_mode') {
                    $value = trim((string)$value);
                    if ($key === 'ollama_keep_alive' && $value === '') {
                        $value = '5m'; // Ollama server default
                    }
                    if ($key === 'followups_mode' && !in_array($value, ['fast', 'llm'], true)) {
                        $value = 'fast';
                    }
                }
                if ($key === 'talk_bot_trigger') {
                    $value = trim((string)$value);
                    if ($value === '') {
                        $value = 'EVA'; // Default falls leer
                    }
                }
                $this->config->set($key, (string)$value);
        }
        return new DataResponse($this->config->all());
    }

    /** Validate the small, deliberately data-only scheduler format. */
    private function validateProactiveSchedules(mixed $value): ?string {
        if (!is_string($value) || strlen($value) > 20000) {
            return 'Scheduled briefings must be a small JSON list.';
        }
        $rows = json_decode($value, true);
        if (!is_array($rows) || count($rows) > 20) {
            return 'Scheduled briefings must contain at most 20 entries.';
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)($row['id'] ?? '')) !== 1
                || !is_string($row['prompt'] ?? null) || mb_strlen(trim((string)$row['prompt'])) < 1 || mb_strlen((string)$row['prompt']) > 2000
                || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', (string)($row['time'] ?? '')) !== 1
                || !is_array($row['days'] ?? null) || $row['days'] === []) {
                return 'Every scheduled briefing needs an id, prompt, HH:MM time and at least one weekday.';
            }
            if (array_key_exists('allow_actions', $row) && !is_bool($row['allow_actions'])) {
                return 'Scheduled briefing allow_actions must be a boolean.';
            }
            foreach ($row['days'] as $day) {
                if (!is_int($day) && !ctype_digit((string)$day) || (int)$day < 1 || (int)$day > 7) {
                    return 'Scheduled briefing weekdays must be between 1 (Monday) and 7 (Sunday).';
                }
            }
        }
        return null;
    }

    /**
     * Reject role-mismatched model selections before they are stored (Issue
     * #148): when the endpoint reports capabilities for an installed model,
     * an embedding model must actually produce vectors and a chat model must
     * accept chat/completion. Selections for models that are not installed
     * yet (or an offline endpoint) are allowed - the pull may still be
     * pending, and indexing/chat will surface the real error.
     */
    private function validateModelRoles(array $pending): array {
        $modelKeys = ['embedding_model', 'chat_model', 'summary_model'];
        if (!array_intersect(array_keys($pending), [...$modelKeys, 'ollama_url'])) {
            return [];
        }
        $errors = [];
        $previousUrl = null;
        $urlPending = isset($pending['ollama_url']) && is_scalar($pending['ollama_url']);
        if ($urlPending) {
            // Capability checks must run against the endpoint the user is
            // about to save, not the previously stored one.
            $previousUrl = $this->config->get('ollama_url');
            $this->config->set('ollama_url', trim((string)$pending['ollama_url']));
        }
        try {
            $caps = $this->ollama->capabilities();
            if (!$caps['available'] || $caps['models'] === []) {
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
                if (($pending['chat_provider'] ?? $this->config->get('chat_provider')) === 'groq' && $role === 'chat') continue;
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
        } finally {
            if ($urlPending && $previousUrl !== null) {
                $this->config->set('ollama_url', $previousUrl);
            }
        }
        return $errors;
    }

    private function validateOllamaUrl(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))) {
            return 'Ollama server URL must be a plain http(s) URL without credentials, path, query or fragment.';
        }
        return null;
    }

    #[NoAdminRequired]
    public function resetIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Stop indexing before deleting the index.'], 409);
        }
        $deleted = $this->indexer->reset($user);
        return new DataResponse([
            'result' => $deleted,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    #[NoAdminRequired]
    public function startIndex(): DataResponse {
        return $this->queueIndex('all');
    }

    #[NoAdminRequired]
    public function startMailIndex(): DataResponse {
        return $this->queueIndex('mail');
    }

    /**
     * Index the user's Nextcloud Talk chat histories.
     *
     * An explicit start always runs, even when the automatic Talk indexing
     * toggle is off: the user asked for it now. Only rooms the user is a member
     * of are read, and membership is checked again at answer time.
     */
    #[NoAdminRequired]
    public function startTalkIndex(): DataResponse {
        return $this->queueIndex('talk');
    }

    #[NoAdminRequired]
    public function stopIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') !== '1') {
            return new DataResponse(['stopped' => true, 'status' => $this->ragService->buildStatus($user)]);
        }
        // Keep the run claim and heartbeat until the worker confirms that it
        // has stopped. Clearing them here makes the UI report a false
        // completion and allows a second worker to race with the first one.
        // The worker observes this durable cancellation flag at its next
        // boundary and owns the terminal-state transition in its finally block.
        $this->config->set('index_cancel_requested', '1');
        return new DataResponse([
            'stopped' => true,
            'stopping' => true,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    private function queueIndex(string $mode): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            if ($this->config->get('index_cancel_requested') === '1') {
                // A restart requested while the previous worker is stopping
                // is queued behind it. The queued job claims a fresh run only
                // after the old worker has released its lock and terminal state.
                try {
                    $this->jobList->add(IndexRequestJob::class, [
                        'userId' => $user,
                        'mode' => $mode,
                        'waitForCancellation' => true,
                    ]);
                    return new DataResponse([
                        'queued' => true,
                        'waitingForStop' => true,
                        'mode' => $mode,
                        'status' => $this->ragService->buildStatus($user),
                    ]);
                } catch (\Throwable $e) {
                    return new DataResponse(['error' => 'The follow-up index job could not be queued.'], 500);
                }
            }
            return new DataResponse([
                'queued' => false,
                'alreadyRunning' => true,
                'message' => 'Indexing is already running for this user.',
                'status' => $this->ragService->buildStatus($user),
            ]);
        }
        $runId = bin2hex(random_bytes(16));
        // Bounded key: the full sha256 would exceed the varchar(64) key column
        // of Nextcloud's file_locks table and break acquire/release.
        $lockPath = LockGuard::indexLockPath($user);
        try {
            // Serialize the initial claim as well. IConfig's precondition is
            // atomic only after the first missing value has been created. The
            // guard reclaims an expired row a crashed worker left behind;
            // without it such a row blocks every future start until a cron
            // maintenance job happens to clean file_locks.
            $this->lockGuard->acquireIndexLock($user, $lockPath);
        } catch (\Throwable $e) {
            return new DataResponse([
                'queued' => false,
                'error' => 'Indexing is currently locked by another worker. Please retry shortly.',
                'status' => $this->ragService->buildStatus($user),
            ], 409);
        }
        if (!$this->config->tryClaimIndex($user)) {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            return new DataResponse([
                'queued' => false,
                'error' => 'Indexing could not be claimed because another worker is active. Please retry shortly.',
                'status' => $this->ragService->buildStatus($user),
            ], 409);
        }
        try {
            $this->config->setUserId($user);
            $this->config->set('index_started', (string)time());
            $this->config->set('index_heartbeat', (string)time());
            $this->config->set('index_finished', '0');
            $this->config->set('last_index_error', '');
            $this->config->set('index_mode', $mode);
            $this->config->set('index_cancel_requested', '0');
            $this->config->set('index_run_id', $runId);
            $this->jobList->add(IndexRequestJob::class, [
                'userId' => $user,
                'mode' => $mode,
                'runId' => $runId,
            ]);
            // A successful explicit start enrolls this user even if the
            // current pass eventually finds zero indexable documents.
            $this->config->setIndexEnrolled($user, true);
            return new DataResponse([
                'queued' => true,
                'mode' => $mode,
                'status' => $this->ragService->buildStatus($user),
            ]);
        } catch (\Throwable $e) {
            $this->config->setUserId($user);
            if ($this->config->get('index_run_id') === $runId) {
                $this->config->set('index_running', '0');
                $this->config->set('index_mode', 'idle');
                $this->config->set('index_run_id', '');
                $this->config->set('index_heartbeat', '');
            }
            return new DataResponse(['error' => 'The background index job could not be queued.'], 500);
        } finally {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }    private function recoverStaleIndex(): void
    {
        // The rule itself lives with the run state (AppConfig), because the cron
        // job and the indexer need the same one; this call is only the HTTP
        // entry point into it.
        $this->config->recoverAbandonedRun();
    }

    #[NoAdminRequired]
    public function documents(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $search = (string)($this->requestParam('search') ?? '');
        $limit = max(1, min(500, (int)($this->requestParam('limit') ?? 100)));
        $offset = max(0, (int)($this->requestParam('offset') ?? 0));
        // Document filters and sorting (Issue #88). Every value is validated
        // and bounded server-side; unknown sort keys fall back to the default.
        $filters = [];
        $type = trim((string)($this->requestParam('type') ?? ''));
        if ($type !== '') {
            // MIME group (text) or full MIME type (application/pdf), safe charset.
            if (preg_match('/^[a-z0-9.+-]+(?:\/[a-z0-9.+-]+)?$/i', $type)) {
                $filters['type'] = $type;
            }
        }
        $folder = trim((string)($this->requestParam('folder') ?? ''));
        if ($folder !== '') {
            // Relative folder path without traversal or wildcards.
            $folder = trim($folder, '/');
            if (preg_match('#^(?:[^/\\]{1,120}/)*[^/\\]{1,120}$#', $folder) && !str_contains($folder, '..')) {
                $filters['folder'] = $folder;
            }
        }
        $dateFrom = (int)($this->requestParam('dateFrom') ?? 0);
        if ($dateFrom > 0) {
            $filters['dateFrom'] = $dateFrom;
        }
        $dateTo = (int)($this->requestParam('dateTo') ?? 0);
        if ($dateTo > 0) {
            $filters['dateTo'] = $dateTo;
        }
        $sizeMin = (int)($this->requestParam('sizeMin') ?? 0);
        if ($sizeMin > 0) {
            $filters['sizeMin'] = $sizeMin;
        }
        $sizeMax = (int)($this->requestParam('sizeMax') ?? 0);
        if ($sizeMax > 0) {
            $filters['sizeMax'] = $sizeMax;
        }
        $sort = (string)($this->requestParam('sort') ?? '');
        $dir = strtolower((string)($this->requestParam('dir') ?? 'desc'));
        $docs = $this->documentMapper->findByUser($user, $search, $limit, $offset, $filters, $sort !== '' ? $sort : null, $dir);
        $out = array_map(static function ($d) {
            return [
                'id' => (int)$d->getId(),
                'path' => $d->getPath(),
                'name' => $d->getName(),
                'mime' => $d->getMime(),
                'size' => (int)$d->getSize(),
                'chunks' => (int)$d->getChunkCount(),
                'indexedAt' => $d->getIndexedAt(),
            ];
        }, $docs);
        // Totals describe the whole filtered index, independent of the page
        // that was requested (Issue #74).
        $aggregates = $this->documentMapper->aggregateForUser($user, $search, $filters);
        return new DataResponse([
            'documents' => $out,
            'total' => $aggregates['count'],
            'totalChunks' => $aggregates['chunks'],
            'totalSize' => $aggregates['size'],
        ]);
    }

    #[NoAdminRequired]
    public function documentChunks(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $id = (int)($this->requestParam('id') ?? 0);
        $doc = $id > 0 ? $this->documentMapper->findById($id) : null;
        if ($doc === null || $doc->getUserId() !== $user
            || !$this->fileContextChat->fileAccessible($user, (int)$doc->getFileId())) {
            return new DataResponse(['error' => 'Document not found'], 404);
        }
        // Bounded pagination (Issues #91/#140): a huge document must not be
        // transferred all at once. The client streams pages of LIMIT chunks
        // until it reached document.chunks.
        $limit = max(1, min(500, (int)($this->requestParam('limit') ?? 200)));
        $offset = max(0, (int)($this->requestParam('offset') ?? 0));
        $rows = $this->chunkMapper->findByDocument($id, $limit, $offset);
        $totalChunks = (int)$doc->getChunkCount();
        $nextOffset = $offset + count($rows);
        return new DataResponse([
            'document' => [
                'id' => (int)$doc->getId(),
                'path' => $doc->getPath(),
                'chunks' => $totalChunks,
            ],
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => $nextOffset < $totalChunks,
            'nextOffset' => $nextOffset,
            'chunks' => array_map(static fn($c) => [
                'index' => (int)$c['chunk_index'],
                'content' => (string)$c['content'],
                'provenance' => json_decode((string)($c['provenance'] ?? '{}'), true) ?: [],
            ], $rows),
        ]);
    }

    #[NoAdminRequired]
    public function chat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $message = trim((string)($this->requestParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->requestParam('history') ?? [];
        if (is_string($history)) {
            $history = json_decode($history, true) ?? [];
        }
        if (!is_array($history)) {
            $history = [];
        }
        // Per-chat folder scope (Issue #88) and custom instructions
        // (Issue #90) are resolved from the chat's stored metadata.
        $chatId = $this->requestParam('chatId');
        $custom = $this->customFor($user, $chatId);
        return new DataResponse($this->ragService->ask($user, $message, $history, $this->scopePathFor($user, $chatId), $custom['instructions'], $custom['persona']));
    }

    /** Queue a chat request so a closed browser tab cannot lose it. */
    #[NoAdminRequired]
    public function backgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $chatId = trim((string)($this->requestParam('chatId') ?? ''));
        $message = trim((string)($this->requestParam('message') ?? ''));
        $history = $this->requestParam('history', []);
        $requestId = $this->requestParam('requestId');
        if (is_string($history)) $history = json_decode($history, true) ?? [];
        if ($chatId === '' || $message === '' || !is_array($history)) return new DataResponse(['error' => 'chatId, message and history are required'], 400);
        $chat = $this->chatStore->get($user, $chatId);
        if ($chat === null) return new DataResponse(['error' => 'Chat not found'], 404);
        // pagehide may fire before the normal user-message persistence call;
        // append it only when it is not already the final stored user message.
        $stored = $chat['messages'] ?? [];
        $last = $stored !== [] ? $stored[count($stored) - 1] : null;
        if (($last['role'] ?? '') !== 'user' || trim((string)($last['text'] ?? '')) !== $message) {
            $this->chatStore->append($user, $chatId, 'user', $message);
        }
        $id = $this->backgroundChatQueue->enqueue($user, $chatId, $message, $history, is_string($requestId) ? $requestId : null);
        if ($id === null) return new DataResponse(['error' => 'Background queue is full or the message is too large'], 429);
        return new DataResponse(['queued' => true, 'id' => $id]);
    }

    #[NoAdminRequired]
    public function externalConnectors(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        return new DataResponse($this->executor->run($user, 'list_external_connectors', []));
    }

    #[NoAdminRequired]
    public function saveExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $body = $this->requestBody();
        $result = $this->executor->runConfirmed($user, 'configure_external_connector', $body);
        return new DataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function discoverExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $result = $this->executor->run($user, 'discover_external_connector', $this->requestBody());
        return new DataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function deleteExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $id = strtolower(trim((string)($this->requestParam('id') ?? $this->requestBody()['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id)) return new DataResponse(['error' => 'Valid connector id required'], 400);
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $raw = json_decode($config->getUserValue($user, AppConfig::APP, 'external_connectors', '{}'), true);
        if (!is_array($raw) || !array_key_exists($id, $raw)) return new DataResponse(['error' => 'Connector not found'], 404);
        unset($raw[$id]); $config->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($raw, JSON_UNESCAPED_SLASHES) ?: '{}');
        \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->saveCustom($user, 'connector_' . $id, '');
        return new DataResponse(['ok' => true, 'deleted' => $id]);
    }

    /** Inspect queued background-agent work without exposing conversation history. */
    #[NoAdminRequired]
    public function backgroundChatStatus(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        return new DataResponse(['items' => $this->backgroundChatQueue->status($user)]);
    }

    #[NoAdminRequired]
    public function cancelBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $id = trim((string)($this->requestParam('id') ?? ''));
        if ($id === '') return new DataResponse(['error' => 'Queue id required'], 400);
        if (!$this->backgroundChatQueue->cancel($user, $id)) return new DataResponse(['error' => 'Background job not found'], 404);
        return new DataResponse(['ok' => true, 'cancelRequested' => true]);
    }

    #[NoAdminRequired]
    public function pauseBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $id = trim((string)($this->requestParam('id') ?? ''));
        if ($id === '' || !$this->backgroundChatQueue->pause($user, $id)) return new DataResponse(['error' => 'Pending background job not found'], 404);
        return new DataResponse(['ok' => true, 'paused' => true]);
    }

    #[NoAdminRequired]
    public function resumeBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $id = trim((string)($this->requestParam('id') ?? ''));
        if ($id === '' || !$this->backgroundChatQueue->resume($user, $id)) return new DataResponse(['error' => 'Paused background job not found'], 404);
        return new DataResponse(['ok' => true, 'resumed' => true]);
    }

    /** Requeue a failed background run without losing its original context. */
    #[NoAdminRequired]
    public function retryBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $id = trim((string)($this->requestParam('id') ?? ''));
        if ($id === '') return new DataResponse(['error' => 'Queue id required'], 400);
        $known = false;
        foreach ($this->backgroundChatQueue->status($user) as $item) {
            if (($item['id'] ?? '') === $id) { $known = true; break; }
        }
        if (!$known) return new DataResponse(['error' => 'Background job not found'], 404);
        if ($this->backgroundChatQueue->retry($user, $id, 'Retry limit reached.')) {
            return new DataResponse(['error' => 'This background run has reached its retry limit.'], 409);
        }
        return new DataResponse(['ok' => true, 'queued' => true]);
    }

    /**
     * Resolve the folder scope stored on a chat (Issue #88). Empty string
     * when the chat is unknown, missing or not scoped — the caller then
     * falls back to the user's global index.
     */
    private function scopePathFor(?string $user, mixed $chatId): string {
        if ($user === null || !is_string($chatId) || trim($chatId) === '') {
            return '';
        }
        $chat = $this->chatStore->get($user, trim($chatId));
        return $chat !== null ? trim((string)($chat['scopePath'] ?? '')) : '';
    }

    /**
     * Resolve the custom instructions + persona stored on a chat (Issue #90).
     * Empty strings when the chat is unknown or not customised — the caller
     * then builds the default prompt.
     * @return array{instructions:string,persona:string}
     */
    private function customFor(?string $user, mixed $chatId): array {
        if ($user === null || !is_string($chatId) || trim($chatId) === '') {
            return ['instructions' => '', 'persona' => ''];
        }
        $chat = $this->chatStore->get($user, trim($chatId));
        if ($chat === null) {
            return ['instructions' => '', 'persona' => ''];
        }
        return [
            'instructions' => trim((string)($chat['instructions'] ?? '')),
            'persona' => trim((string)($chat['persona'] ?? '')),
        ];
    }

    /**
     * Kontext-Chat ueber explizit ausgewaehlte Dateien ("Mit AI oeffnen"
     * bzw. "Mit diesen Dateien chatten"). Antwort wird ausschliesslich
     * aus den Chunks der uebergebenen fileIds erzeugt.
     */
    #[NoAdminRequired]
    public function fileContextChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $fileIds = $this->requestParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        $message = trim((string)($this->requestParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->requestParam('history') ?? [];
        if (is_string($history)) {
            $history = json_decode($history, true) ?? [];
        }
        if (!is_array($history)) {
            $history = [];
        }
        return new DataResponse($this->fileContextChat->chat($user, $fileIds, $message, $history));
    }

    /**
     * Liefert die indexierten Dokument-IDs zu einer Liste von File-IDs
     * (fuer die UI, damit vor dem Chat geprueft werden kann, ob die
     * Auswahl bereits indexiert ist).
     */
    #[NoAdminRequired]
    public function knowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        try {
            $rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
            $home = $rootFolder->getUserFolder($user);
            $content = '';
            if ($home->nodeExists('KNOWLEDGE.md')) {
                $node = $home->get('KNOWLEDGE.md');
                if ($node instanceof \OCP\Files\File) {
                    $content = (string)$node->getContent();
                }
            }
            return new DataResponse(['content' => $content, 'length' => mb_strlen($content)]);
        } catch (\Throwable $e) {
            return new DataResponse(['content' => '', 'length' => 0]);
        }
    }

    #[NoAdminRequired]
    public function saveKnowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $content = (string)($this->requestParam('content', ''));
        if (mb_strlen($content) > 60000) {
            return new DataResponse(['error' => 'Content exceeds 60,000 characters.'], 400);
        }
        try {
            $rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
            $home = $rootFolder->getUserFolder($user);
            $path = 'KNOWLEDGE.md';
            if ($home->nodeExists($path)) {
                $home->get($path)->putContent($content);
            } else {
                $home->newFile($path, $content);
            }
            return new DataResponse(['ok' => true, 'length' => mb_strlen($content)]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not save knowledge file: ' . $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function fileContextStatus(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $fileIds = $this->requestParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        if ($fileIds === []) {
            return new DataResponse(['indexed' => [], 'missing' => [], 'files' => []]);
        }
        $docs = $this->fileContextChat->accessibleDocuments(
            $user,
            $this->documentMapper->findByUserAndFileIds($user, $fileIds)
        );
        $indexed = [];
        $files = [];
        foreach ($docs as $d) {
            $fid = (int)$d->getFileId();
            $indexed[] = $fid;
            $files[] = [
                'fileId' => $fid,
                'name' => $d->getName(),
                'path' => $d->getPath(),
            ];
        }
        return new DataResponse([
            'indexed' => $indexed,
            'missing' => array_values(array_diff($fileIds, $indexed)),
            'files' => $files,
        ]);
    }

    /**
     * Execute one model-proposed mutating action after an explicit click in
     * the authenticated web chat. The tool policy is reset to WEB here so a
     * previous Talk/worker request cannot influence this request's surface.
     */
    #[NoAdminRequired]
    public function confirmTool(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)($this->requestParam('name') ?? ''));
        $args = $this->requestParam('arguments', $this->requestParam('args', []));
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }
        if ($name === '' || !is_array($args)) {
            return new DataResponse(['error' => 'A tool name and argument object are required.'], 400);
        }

        // Idempotency guard (Issue #185): a persisted pending confirmation
        // carries a token; approving the same token twice (e.g. after a reload)
        // must not run a mutating action again.
        $chatId = (string)($this->requestParam('chatId') ?? '');
        $confirmationToken = (string)($this->requestParam('confirmationToken') ?? '');
        if ($chatId !== '' && $confirmationToken !== '') {
            $claim = $this->chatStore->claimConfirmation($user, $chatId, $confirmationToken);
            if ($claim === 'already') {
                return new DataResponse([
                    'ok' => false,
                    'alreadyProcessed' => true,
                    'error' => 'This action was already processed - reload the chat to see its result.',
                ], 409);
            }
        }

        $this->executor->setSurface(\OCA\EvaAi\Service\ToolPolicy::SURFACE_WEB);
        $result = $this->executor->runConfirmed($user, $name, $args);
        return new DataResponse($result, !empty($result['ok']) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function streamChat(): StreamTraversableResponse {
        $user = $this->requireUser();
        $body = json_decode((string)file_get_contents('php://input'), true);
        $message = trim((string)($body['message'] ?? ''));
        $history = isset($body['history']) && is_array($body['history']) ? $body['history'] : [];
        // Per-chat folder scope (Issue #88) and custom instructions (Issue #90)
        // are resolved once, outside the generator, so they cannot change
        // mid-stream.
        $scopePath = $this->scopePathFor($user, $body['chatId'] ?? null);
        $custom = $this->customFor($user, $body['chatId'] ?? null);

        $generator = (function () use ($user, $message, $history, $scopePath, $custom): \Generator {
            // Aber die PHP-Output-Buffering-Schicht (php.ini output_buffering)
            // würde jede erzeugte Zeile bis zum Ende puffern -> keine Live-Streams.
            // Deshalb entfernen wir hier alle Puffer und flush'eriessen wirklich.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            if ($user === null) {
                yield json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
                return;
            }
            if ($this->clientDisconnected()) {
                return;
            }
            $gen = null;
            try {
                $gen = $this->ragService->askStream($user, $message, $history, $scopePath, $custom['instructions'], $custom['persona']);
                foreach ($gen as $line) {
                    if ($this->clientDisconnected()) {
                        return;
                    }
                    try {
                        $ev = json_decode((string)$line, true);
                        if (is_array($ev) && ($ev['type'] ?? '') === 'done') {
                            $answer = (string)($ev['answer'] ?? '');
                            if ($answer !== '' && $this->config->get('notify_on_complete') === '1') {
                                $this->sendAnswerNotification($user, $answer);
                            }
                        }
                        yield $line;
                        if ($this->clientDisconnected()) {
                            return;
                        }
                    } catch (\Throwable $e) {
                        if (!$this->clientDisconnected()) {
                            yield json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n";
                        }
                    }
                }
            } finally {
                // Dropping the generator reference closes nested stream
                // resources on every supported PHP version.
                $gen = null;
            }
        })();

        return new StreamTraversableResponse($generator, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }


    private function clientDisconnected(): bool {
        return function_exists('connection_aborted') && connection_aborted() > 0;
    }

    private function sendAnswerNotification(string $user, string $text): void {
        try {
            $manager = \OCP\Server::get(\OCP\Notification\IManager::class);
            if (!$this->appManager->isInstalled('notifications')) {
                return;
            }
            $url = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('eva_ai.page.app');
            $notification = $manager->createNotification();
            $notification->setApp('eva_ai')
                ->setUser($user)
                ->setObject('chat', 'answer')
                ->setSubject('answer_ready', ['text' => mb_strimwidth($text, 0, 400, '…')])
                ->setLink($url)
                ->setDateTime(new \DateTime());
            $manager->notify($notification);
        } catch (\Throwable $e) {
            // Notifications must never break the chat stream.
        }
    }

    #[NoAdminRequired]
    public function chats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        // Optional text search across chat titles and message content.
        $search = trim((string)($this->requestParam('search') ?? ''));
        // Archived chats are always included: the sidebar splits them into
        // its own section and would otherwise never see them again (Issue #87).
        // The dashboard widget reads the store directly and keeps hiding them.
        try {
            return new DataResponse($this->chatStore->list($user, $search !== '' ? $search : null, true));
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function createChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->create($user, (string)($this->requestParam('title') ?? ''));
            return new DataResponse($chat);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function deleteAllChats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new DataResponse(['ok' => true, 'deleted' => $this->chatStore->deleteAll($user)]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chatDetail(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->get($user, $id);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
        if ($chat === null) {
            return new NotFoundResponse();
        }
        return new DataResponse($chat);
    }

    #[NoAdminRequired]
    public function chatDelete(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if (!$this->chatStore->delete($user, $id)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chatAppend(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->get($user, $id);
            if ($chat === null) {
                return new NotFoundResponse();
            }
            $role = (string)($this->requestParam('role') ?? '');
            $text = trim((string)($this->requestParam('text') ?? ''));
            if ($role === '' || $text === '') {
                return new DataResponse(['error' => 'role and text are required'], 400);
            }
            // Optional follow-up suggestions (assistant messages only).
            $followupsRaw = $this->requestParam('followups');
            $followups = [];
            if (is_array($followupsRaw)) {
                $followups = array_slice(array_map('strval', $followupsRaw), 0, 3);
            } elseif (is_string($followupsRaw) && $followupsRaw !== '') {
                $decoded = json_decode($followupsRaw, true);
                if (is_array($decoded)) {
                    $followups = array_slice(array_map('strval', $decoded), 0, 3);
                }
            }
            $rawRegenerateRev = $this->requestParam('regenerateRev');
            $regenerateRev = null;
            if (is_int($rawRegenerateRev)) {
                $regenerateRev = $rawRegenerateRev;
            } elseif (is_string($rawRegenerateRev) && $rawRegenerateRev !== '' && ctype_digit($rawRegenerateRev)) {
                $regenerateRev = (int)$rawRegenerateRev;
            }
            // Pending tool confirmation persisted with the assistant message so
            // a reload can rebuild the inline panel (Issue #185). The store
            // normalizes the payload and drops arguments after resolution.
            $rawConfirmation = $this->requestParam('confirmation');
            $confirmation = null;
            if (is_array($rawConfirmation)) {
                $confirmation = $rawConfirmation;
            } elseif (is_string($rawConfirmation) && $rawConfirmation !== '') {
                $decoded = json_decode($rawConfirmation, true);
                if (is_array($decoded)) {
                    $confirmation = $decoded;
                }
            }
            $this->chatStore->append($user, $id, $role, $text, $followups, $regenerateRev, $confirmation);
            // Return the bumped revision so the client can validate later
            // regenerate/edit requests against the current state (Issue #182).
            $appended = $this->chatStore->getChat($user, $id);
            $rev = $appended !== null ? (int)($appended['rev'] ?? 0) : 0;

            // After an assistant message is saved, learn from the full chat.
            if ($role === 'assistant') {
                try {
                    $fullChat = $this->chatStore->getChat($user, $id);
                    if ($fullChat !== null && count($fullChat['messages']) >= 4 && $this->config->get('learning_enabled') === '1') {
                        $this->chatLearner->learnFromChat($user, $fullChat['messages']);
                    }
                } catch (\Throwable $e) {
                    // Learning failure must never break chat persistence.
                }
            }

            return new DataResponse(['ok' => true, 'rev' => $rev]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chatTitle(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if ($this->chatStore->get($user, $id) === null) {
                return new NotFoundResponse();
            }
            $title = trim((string)($this->requestParam('title') ?? ''));
            if ($title === '') {
                return new DataResponse(['error' => 'title required'], 400);
            }
            $this->chatStore->setTitle($user, $id, $title);
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    /**
     * Update organisational chat metadata (Issue #87): pin/unpin, assign a
     * folder, archive/unarchive.
     *
     * POST /api/chats/{id}/meta
     * Body: { pinned?: bool, folder?: string, archived?: bool }
     */
    #[NoAdminRequired]
    public function chatMeta(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $meta = [];
        $body = $this->requestBody();
        foreach (['pinned', 'archived'] as $flag) {
            if (array_key_exists($flag, $body)) {
                $meta[$flag] = !empty($body[$flag]);
            }
        }
        if (array_key_exists('folder', $body)) {
            $meta['folder'] = trim((string)$body['folder']);
        }
        if (array_key_exists('scopePath', $body)) {
            $meta['scopePath'] = trim((string)$body['scopePath']);
        }
        if (array_key_exists('instructions', $body)) {
            // Free-text custom instructions (Issue #90); capped server-side.
            $meta['instructions'] = mb_substr(trim((string)$body['instructions']), 0, 2000);
        }
        if (array_key_exists('persona', $body)) {
            // Preset persona slug (Issue #90); only known slugs are stored.
            $persona = trim((string)$body['persona']);
            $meta['persona'] = array_key_exists($persona, RagService::PERSONAS) ? $persona : '';
        }
        if ($meta === []) {
            return new DataResponse(['error' => 'No metadata given'], 400);
        }
        try {
            if (!$this->chatStore->setMeta($user, $id, $meta)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function folders(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new DataResponse($this->chatStore->listFolders($user));
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function createFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)$this->requestParam('name') ?? '');
        if ($name === '') {
            return new DataResponse(['error' => 'Folder name required'], 400);
        }
        try {
            return new DataResponse($this->chatStore->createFolder($user, $name));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to create folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function renameFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $from = trim((string)$this->requestParam('from') ?? '');
        $to = trim((string)$this->requestParam('to') ?? '');
        if ($from === '' || $to === '') {
            return new DataResponse(['error' => 'from and to are required'], 400);
        }
        try {
            if (!$this->chatStore->renameFolder($user, $from, $to)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to rename folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function deleteFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)$this->requestParam('name') ?? '');
        if ($name === '') {
            return new DataResponse(['error' => 'Folder name required'], 400);
        }
        try {
            if (!$this->chatStore->deleteFolder($user, $name)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to delete folder'], 500);
        }
    }

    /**
     * Truncate a chat after a given message index and re-run the assistant.
     * Used for regenerate (truncate after user msg, re-ask) and edit
     * (truncate after edited user msg, re-ask).
     *
     * POST /api/chats/{id}/regenerate
     * Body: { messageIndex: int, message?: string }
     *
     * If messageIndex points to a user message and `message` is provided,
     * the user message text is replaced before re-running.
     */
    #[NoAdminRequired]
    public function chatRegenerate(string $id): StreamTraversableResponse {
        $headers = [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];
        $user = $this->requireUser();
        if ($user === null) {
            $body = json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 401, $headers);
        }
        $rawIndex = $this->requestParam('messageIndex', -1);
        $messageIndex = is_int($rawIndex) ? $rawIndex : -1;
        $rawText = $this->requestParam('message');
        if ($rawText !== null && !is_string($rawText)) {
            $body = json_encode(['type' => 'error', 'message' => 'A valid user message index and non-empty message are required']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
        }
        $newText = is_string($rawText) ? trim($rawText) : null;
        // The revision the client loaded (Issue #182): a mismatch means the
        // chat was modified in another tab and the regenerate is rejected.
        $rawRev = $this->requestParam('rev');
        $expectedRev = null;
        if (is_int($rawRev)) {
            $expectedRev = $rawRev;
        } elseif (is_string($rawRev) && $rawRev !== '' && ctype_digit($rawRev)) {
            $expectedRev = (int)$rawRev;
        }

        $result = $this->chatStore->beginRegenerate($user, $id, $messageIndex, $newText, $expectedRev);
        if (!($result['ok'] ?? false)) {
            $error = (string)($result['error'] ?? 'invalid');
            if ($error === 'conflict') {
                // Streamed with HTTP 200 like the normal error events so the
                // chat UI can show the message inline instead of a network error.
                $body = json_encode(['type' => 'error', 'message' => 'This chat was modified in another tab - reload to continue']) . "\n";
                return new StreamTraversableResponse(new \ArrayIterator([$body]), 200, $headers);
            }
            if ($error === 'not_found') {
                $body = json_encode(['type' => 'error', 'message' => 'Chat not found']) . "\n";
                return new StreamTraversableResponse(new \ArrayIterator([$body]), 404, $headers);
            }
            $body = json_encode(['type' => 'error', 'message' => 'A valid user message index and non-empty message are required']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
        }

        // Nothing has been truncated yet: the pending regeneration is committed
        // by append() only when the new answer is persisted (Issue #182), so a
        // failed model call leaves the stored history fully intact.
        $chat = $this->chatStore->getChat($user, $id);
        $messages = $chat['messages'] ?? [];

        // Build history from the messages before the target (unchanged state).
        $history = [];
        for ($i = 0; $i < $result['messageIndex']; $i++) {
            $m = $messages[$i];
            $history[] = ['role' => $m['role'] ?? 'user', 'content' => $m['text'] ?? ''];
        }

        // Re-apply the chat's custom instructions and persona on regenerate
        // (Issue #90), resolved from the stored metadata.
        $gen = $this->ragService->askStream(
            $user,
            $result['targetText'],
            $history,
            trim((string)($chat['scopePath'] ?? '')),
            trim((string)($chat['instructions'] ?? '')),
            trim((string)($chat['persona'] ?? ''))
        );
        // The leading regenerate event hands the client the revision that the
        // persisted answer must carry to commit the truncation atomically.
        $rev = (int)$result['rev'];
        $stream = (function () use ($gen, $rev): \Generator {
            yield json_encode(['type' => 'regenerate', 'rev' => $rev]) . "\n";
            yield from $gen;
        })();
        return new StreamTraversableResponse($stream, 200, $headers);
    }

    #[NoAdminRequired]
    public function calendars(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        // Read-only calendar metadata for the tool-confirmation dialogs
        // (calendar picker). Empty list when the calendar backend is absent.
        return new DataResponse(['calendars' => $this->ragService->calendarList($user)]);
    }

    #[NoAdminRequired]
    public function models(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $endpoint = trim((string)($this->requestParam('endpoint') ?? $this->config->ollamaUrl()));
        $urlError = $this->validateOllamaUrl($endpoint);
        if ($urlError !== null) {
            return new DataResponse(['error' => $urlError], 400);
        }
        $models = $this->ollama->listModels($endpoint);
        $names = array_values(array_filter(array_map(static fn($m) => (string)($m['name'] ?? ''), $models)));
        $roles = [];
        foreach ($models as $entry) {
            $name = (string)($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $declared = array_values(array_filter(array_map('strval', $entry['capabilities'] ?? [])));
            $entryRoles = $this->ollama->rolesForModelEntry($entry);
            $roles[$name] = ['roles' => $entryRoles, 'declared' => $declared];
        }
        return new DataResponse([
            'models' => $names,
            'roles' => $roles,
            'embedding' => $this->config->get('embedding_model'),
            'chat' => $this->config->get('chat_model'),
            'details' => $models,
        ]);
    }

    #[NoAdminRequired]
    public function check(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        if ($this->config->get('chat_provider') === 'groq') return new DataResponse(['provider' => 'groq', 'groq' => $this->ollama->checkGroq()]);
        if ($this->config->get('chat_provider') !== 'ollama') return new DataResponse(['provider' => $this->config->get('chat_provider'), 'custom' => $this->ollama->checkCustomProvider()]);
        return new DataResponse($this->ollama->testAll());
    }

    /**
     * GDPR data export (Issue #83): the user downloads their chats, personal
     * knowledge and index metadata as one JSON file. Read-only, no admin needed.
     */
    #[NoAdminRequired]
    public function exportData(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $payload = $this->userDataService->export($user);
        $response = new DataResponse($payload);
        $response->addHeader('Content-Disposition', 'attachment; filename="eva_ai_export_' . $user . '.json"');
        return $response;
    }

}
