<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class Ollama {
    private const TIMEOUT = 600;
    /**
     * Bounded total timeout for non-streaming chat calls (web endpoints,
     * file-context chat, Talk classification). One slow model response must
     * not hold a PHP-FPM or TaskProcessing worker for the full streaming
     * timeout - a request without visible progress is capped much earlier
     * (Issue #92). Streaming paths may pass their own larger budget.
     */
    private const CHAT_TIMEOUT = 120;
    private const STATUS_CACHE_TTL = 30;
    private const STATUS_CACHE_TTL_FALLBACK = 15;

    /** @var array<string,array{expires:int,ping:array,models:array}> */
    private static array $statusCache = [];

    /**
     * Model capability markers for Ollama versions that do not yet report an
     * explicit `capabilities` array in /api/tags. Names alone are a heuristic
     * (Issue #148) - it is only used when the provider metadata is absent,
     * never to override what the endpoint itself declares.
     */
    private const EMBEDDING_NAME_MARKERS = [
        'embed', 'bge', 'e5', 'gte', 'jina', 'minilm', 'nomic', 'snowflake',
        'mxbai', 'arctic', 'retriev', 'instructor', 'voyage', 'text-embedding',
    ];
    private const EMBEDDING_FAMILIES = [
        'nomic-bert', 'bert', 'bge', 'e5', 'gte', 'jina', 'minilm',
        'snowflake-arctic-embed', 'mxbai-embed', 'qwen3-embedding', 'granite-embedding',
    ];

    /**
     * Bounded process-local resolution memo: candidates are verified against
     * the (already cached) /api/tags snapshot, so a configured fallback chain
     * is evaluated at most once per unique input combination per worker.
     * @var array<string,array{model:?string,usedFallback:bool,error:?string,at:int}>
     */
    private static array $resolutionCache = [];

    private ?ICache $statusStore = null;
    private bool $statusStoreInitialized = false;
    /** @var array{cache_hits:int,cache_misses:int,ollama_requests:int} */
    private array $lastEmbeddingStats = ['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0];

    /** Floor for one embedding request; a cold model load alone can take a while. */
    private const EMBED_TIMEOUT_FLOOR = 30;
    /** Ceiling so a stalled request cannot occupy a worker indefinitely. */
    private const EMBED_TIMEOUT_CEILING = 300;
    /**
     * Budgeted seconds per input text when sizing the request deadline. Local
     * embedding models realistically need around a second per text, and slower
     * hardware more, so the deadline is not derived from a best case.
     */
    private const EMBED_SECONDS_PER_TEXT = 10;
    /**
     * Seconds without any progress before an embedding request is dropped.
     * The total deadline above is what lets a slow model finish a cold batch;
     * this bound is what keeps an admin "stop indexing" request responsive when
     * the model server has stopped answering altogether.
     */
    private const EMBED_READ_TIMEOUT = 45;

    public function __construct(
        private AppConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
        private ICacheFactory $cacheFactory,
        private EmbeddingCache $embeddingCache,
        private ?Groq $groq = null,
        private ?UsageMetrics $usageMetrics = null,
        private ?ProviderCredentials $providerCredentials = null
    ) {
    }

    private function groqClient(): Groq {
        $credentials = $this->providerCredentials ?? \OCP\Server::get(ProviderCredentials::class);
        return $this->groq ??= new Groq($this->config, $this->clientService, $credentials, $this->usageMetrics);
    }
    private function openAiCompatible(): OpenAICompatible {
        $credentials = $this->providerCredentials ?? \OCP\Server::get(ProviderCredentials::class);
        return new OpenAICompatible($this->config, $this->clientService, $credentials, $this->usageMetrics);
    }
    public function groqInfo(): array { return $this->groqClient()->info(); }
    public function saveGroqKey(string $key): void { $this->groqClient()->saveKey($key); }
    public function checkGroq(): array { return $this->groqClient()->check(); }
    public function checkCustomProvider(): array { return $this->openAiCompatible()->check(); }
    public function selectedChatModel(): string {
        $provider = strtolower(trim((string)$this->config->get('chat_provider')));
        if ($provider === '') $provider = 'ollama';
        if ($provider === 'groq') return $this->config->get('groq_model');
        if ($provider !== 'ollama') return $this->config->get('custom_provider_model');
        return $this->config->get('chat_model');
    }

    private function client() {
        return $this->clientService->newClient();
    }

    private function base(): string {
        return $this->config->ollamaUrl();
    }

    /**
     * How long Ollama should keep the model resident after a request
     * (Issue: model residency). A cold model load on every chat message costs
     * seconds; a longer keep_alive amortises that for regularly used
     * instances. Validated by AppConfig::validateValue, so a malformed value
     * simply falls back to Ollama's server default here.
     */
    private function keepAlive(): ?string {
        $value = trim($this->config->get('ollama_keep_alive'));
        if ($value === '' || preg_match('/^(?:-1|(?:[1-9][0-9]{0,4}(?:ms|s|m|h)?))$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    /**
     * Shared sampler/context options for /api/chat requests. Both call sites
     * (streaming and non-streaming) must produce identical payloads so cache
     * behaviour and generation parameters never diverge between paths.
     */
    private function ollamaOptions(): array {
        return [
            'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))),
            'num_ctx' => max(256, min(131072, (int)$this->config->get('context_size'))),
        ];
    }

    /** @return array|string[] error => message on failure */
    public function ping(): array {
        try {
            $r = $this->client()->get($this->base() . '/api/tags', ['timeout' => 10]);
            $status = $r->getStatusCode();
            if ($status !== 200) {
                return [
                    'ok' => false,
                    'url' => $this->base(),
                    'error' => 'Ollama returned HTTP ' . $status . '.',
                ];
            }
            return ['ok' => true, 'url' => $this->base()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $this->base(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Capability snapshot of the currently installed models (Issue #148/#151).
     * Reuses the same cached /api/tags response as status(); it never issues
     * an extra request. When the endpoint itself declares capabilities (Ollama
     * >= 0.33) those are authoritative; name/family heuristics are only a
     * fallback for older servers and are marked as heuristic.
     *
     * @return array{available:bool,online:bool,error:?string,models:array<string,array{roles:list<string>,declared:list<string>,heuristic:bool}>}
     */
    public function capabilities(): array {
        $status = $this->status();
        $ping = $status['ping'];
        if (!($ping['ok'] ?? false)) {
            return [
                'available' => false,
                'online' => false,
                'error' => $ping['error'] ?? null,
                'models' => [],
            ];
        }
        $out = [];
        foreach ($status['models'] as $entry) {
            $name = (string)($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $declared = array_values(array_filter(array_map('strval', $entry['capabilities'] ?? [])));
            $roles = $this->rolesFromCapabilities($declared);
            $heuristic = false;
            if ($roles === []) {
                $heuristic = true;
                $roles = $this->rolesFromHeuristics($name, $entry['details'] ?? []);
            }
            $out[$name] = ['roles' => $roles, 'declared' => $declared, 'heuristic' => $heuristic];
        }
        return ['available' => true, 'online' => true, 'error' => null, 'models' => $out];
    }

    /**
     * Role list for a single raw /api/tags entry (public so the controller
     * can annotate the model picker without a second request).
     *
     * @return list<string>
     */
    public function rolesForModelEntry(array $entry): array {
        $declared = array_values(array_filter(array_map('strval', $entry['capabilities'] ?? [])));
        $roles = $this->rolesFromCapabilities($declared);
        if ($roles !== []) {
            return $roles;
        }
        return $this->rolesFromHeuristics((string)($entry['name'] ?? ''), $entry['details'] ?? []);
    }

    /** Map Ollama capability names to app-level model roles. */
    private function rolesFromCapabilities(array $declared): array {
        $roles = [];
        foreach ($declared as $capability) {
            $capability = strtolower((string)$capability);
            if ($capability === 'embedding') {
                $roles[] = 'embedding';
            } elseif (in_array($capability, ['completion', 'chat', 'tools', 'vision'], true)) {
                $roles[] = 'chat';
            }
        }
        return array_values(array_unique($roles));
    }

    /** Conservative name/family fallback for Ollama without capabilities. */
    private function rolesFromHeuristics(string $name, array $details): array {
        $haystack = strtolower($name . ' ' . (string)($details['family'] ?? ''));
        $looksEmbedding = false;
        foreach (self::EMBEDDING_NAME_MARKERS as $marker) {
            if (strpos($haystack, $marker) !== false) {
                $looksEmbedding = true;
                break;
            }
        }
        if (!$looksEmbedding) {
            $family = strtolower((string)($details['family'] ?? ''));
            if (in_array($family, self::EMBEDDING_FAMILIES, true)) {
                $looksEmbedding = true;
            }
        }
        return $looksEmbedding ? ['embedding'] : ['chat'];
    }

    /**
     * Resolve the effective model for a role from the configured model plus
     * its optional fallback chain (Issue #86). The first candidate that is
     * actually installed AND matches the requested role wins; verification
     * uses the cached /api/tags snapshot so it adds no request when status()
     * was already polled. If the endpoint cannot be reached the configured
     * model is used unchanged so the real Ollama error still surfaces.
     *
     * @param string $role 'chat'|'embedding'
     * @return array{model:?string,usedFallback:bool,error:?string,candidates:list<string>}
     */
    public function resolveModel(string $role, string $configured, string $fallbackList, string $taskModel = ''): array {
        $configured = trim($configured);
        $fallbackList = trim($fallbackList);
        $taskModel = trim($taskModel);
        if ($fallbackList === '' && $taskModel === '') {
            // No fallback chain configured: the hot path (every chat message
            // and embedding batch) must not add a /api/tags round trip. The
            // configured model is used directly; a missing model surfaces the
            // real Ollama error as before.
            return ['model' => $configured, 'usedFallback' => false, 'error' => null, 'candidates' => [$configured]];
        }
        $candidates = [];
        foreach ([$taskModel, $configured, ...array_map('trim', explode(',', $fallbackList))] as $candidate) {
            if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            return ['model' => null, 'usedFallback' => false, 'error' => 'No ' . $role . ' model configured.', 'candidates' => []];
        }

        $memoKey = hash('sha256', $role . '|' . implode(',', $candidates) . '|' . $this->base());
        $cached = self::$resolutionCache[$memoKey] ?? null;
        if ($cached !== null && time() - $cached['at'] < self::STATUS_CACHE_TTL_FALLBACK) {
            return $cached;
        }

        $capabilities = $this->capabilities();
        $installed = $capabilities['models'];
        if (!$capabilities['online']) {
            // Endpoint unreachable: keep the primary configured model so the
            // actual request reports the true connection error.
            return ['model' => $configured, 'usedFallback' => false, 'error' => null, 'candidates' => $candidates];
        }

        $normalized = [];
        foreach (array_keys($installed) as $name) {
            $lower = strtolower($name);
            $normalized[$lower] = $name;
            if (str_ends_with($lower, ':latest')) {
                $normalized[substr($lower, 0, -7)] = $name;
            }
        }
        foreach ($candidates as $candidate) {
            $listed = $normalized[strtolower($candidate)] ?? null;
            if ($listed === null) {
                continue;
            }
            $roles = $installed[$listed]['roles'] ?? [];
            if (in_array($role, $roles, true)) {
                $result = [
                    'model' => $listed,
                    'usedFallback' => $candidate !== $configured,
                    'error' => null,
                    'candidates' => $candidates,
                ];
                self::$resolutionCache[$memoKey] = $result + ['at' => time()];
                return $result;
            }
        }

        // None of the candidates is installed with the required role. Only
        // fail fast when the endpoint genuinely lists models - an empty list
        // is indistinguishable from a still-starting server, where the
        // configured model should be attempted directly.
        if ($installed === []) {
            return ['model' => $configured, 'usedFallback' => false, 'error' => null, 'candidates' => $candidates];
        }
        $error = 'None of the configured ' . $role . ' models (' . implode(', ', $candidates)
            . ') is installed and capable. Installed: '
            . implode(', ', array_keys($installed)) . '.';
        return ['model' => null, 'usedFallback' => false, 'error' => $error, 'candidates' => $candidates];
    }

    /**
     * Resolve the chat model from chat_model + chat_model_fallback. Heavy
     * text tasks may prefer summary_model via $preferredModel (Issue #86).
     *
     * @return array{model:?string,error:?string,usedFallback:bool}
     */
    private function resolveChatModel(?string $preferredModel = null): array {
        return $this->resolveModel(
            'chat',
            $this->config->get('chat_model'),
            $this->config->get('chat_model_fallback'),
            $preferredModel ?? ''
        );
    }

    /**
     * Resolve the embedding model from embedding_model + fallback chain.
     *
     * @return array{model:?string,error:?string,usedFallback:bool}
     */
    private function resolveEmbeddingModel(): array {
        return $this->resolveModel(
            'embedding',
            $this->config->get('embedding_model'),
            $this->config->get('embedding_model_fallback')
        );
    }

    /**
     * Return connectivity and model information from one /api/tags request.
     * The short process-local cache prevents repeated status polls from
     * multiplying requests while keeping the information at most 30 seconds
     * stale. Explicit connection checks continue to use testAll().
     *
     * The returned block keeps `ping` and `models` stable (older consumers
     * depend on that shape) and adds a small, versioned `meta` block with the
     * snapshot time, request latency and staleness so the UI can explain the
     * provider state without extra requests (Issue #151).
     *
     * @return array{ping:array,models:array<int,array<string,mixed>>,meta:array{version:int,checkedAt:int,latencyMs:?int,fromCache:bool}}
     */
    public function status(): array {
        $base = $this->base();
        $now = time();
        $key = 'tags_' . hash('sha256', $base);
        $cached = $this->readStatusCache($key, $base, $now);
        if ($cached !== null) {
            return [
                'ping' => $cached['ping'],
                'models' => $cached['models'],
                'meta' => [
                    'version' => 1,
                    'checkedAt' => (int)($cached['checkedAt'] ?? $now),
                    'latencyMs' => isset($cached['latencyMs']) ? (int)$cached['latencyMs'] : null,
                    'fromCache' => true,
                ],
            ];
        }

        $started = microtime(true);
        try {
            $response = $this->client()->get($base . '/api/tags', ['timeout' => 20]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $ping = [
                    'ok' => false,
                    'url' => $base,
                    'error' => 'Ollama returned HTTP ' . $status . '.',
                ];
                $models = [];
            } else {
                $data = json_decode((string)$response->getBody(), true);
                $models = is_array($data['models'] ?? null) ? $data['models'] : [];
                $ping = ['ok' => true, 'url' => $base];
            }
        } catch (\Throwable $e) {
            $ping = ['ok' => false, 'url' => $base, 'error' => $e->getMessage()];
            $models = [];
        }
        $latencyMs = (int)round((microtime(true) - $started) * 1000);

        $entry = [
            'expires' => $now + self::STATUS_CACHE_TTL,
            'ping' => $ping,
            'models' => $models,
            'checkedAt' => $now,
            'latencyMs' => $latencyMs,
        ];
        self::$statusCache[$base] = $entry;
        try {
            $this->statusStore()->set($key, $entry, self::STATUS_CACHE_TTL);
        } catch (\Throwable $e) {
            // The process-local fallback above still protects long-running
            // workers if the configured cache backend is temporarily absent.
        }
        return [
            'ping' => $ping,
            'models' => $models,
            'meta' => [
                'version' => 1,
                'checkedAt' => $now,
                'latencyMs' => $latencyMs,
                'fromCache' => false,
            ],
        ];
    }

    /**
     * @return array{expires:int,ping:array,models:array}|null
     */
    private function readStatusCache(string $key, string $base, int $now): ?array {
        $cached = null;
        try {
            $cached = $this->statusStore()->get($key);
        } catch (\Throwable $e) {
            $cached = self::$statusCache[$base] ?? null;
        }
        if (!is_array($cached) || !isset($cached['expires'], $cached['ping'], $cached['models'])
            || (int)$cached['expires'] <= $now) {
            $cached = self::$statusCache[$base] ?? null;
        }
        return is_array($cached) && (int)($cached['expires'] ?? 0) > $now ? $cached : null;
    }

    private function statusStore(): ICache {
        if (!$this->statusStoreInitialized) {
            $this->statusStoreInitialized = true;
            try {
                $this->statusStore = $this->cacheFactory->createDistributed('eva_ai_status_');
            } catch (\Throwable $e) {
                $this->statusStore = null;
            }
        }
        if ($this->statusStore === null) {
            throw new \RuntimeException('No distributed cache available');
        }
        return $this->statusStore;
    }

    /** @return array<int,array<string,mixed>> */
    public function listModels(?string $baseUrl = null): array {
        $baseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : $this->base();
        try {
            $r = $this->client()->get($baseUrl . '/api/tags', ['timeout' => 20]);
            $data = json_decode((string)$r->getBody(), true);
            return $data['models'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * End-to-end connection test: server, selected embedding model and chat model.
     * @return array{server:array,embedding:array,chat:array}
     */
    public function testAll(): array {
        $emb = $this->config->get('embedding_model');
        $chat = $this->config->get('chat_model');
        $server = $this->ping();
        if (!$server['ok']) {
            $reason = 'Skipped because the Ollama server is not reachable.';
            return [
                'server' => $server,
                'models' => [],
                'embedding' => [
                    'ok' => false,
                    'len' => 0,
                    'model' => $emb,
                    'error' => $reason,
                ],
                'chat' => [
                    'ok' => false,
                    'model' => $chat,
                    'answer' => null,
                    'error' => $reason,
                ],
            ];
        }

        // Do not wait for model loading timeouts when /api/tags already tells
        // us that a configured model is unavailable. This keeps "Check
        // connection" bounded and makes the actual configuration error clear.
        $models = $this->listModels();
        $modelNames = array_values(array_filter(array_map(
            static fn($model): string => (string)($model['name'] ?? ''),
            $models
        )));
        $embedding = $emb === ''
            ? $this->testEmbedding($emb, 30)
            : (in_array($emb, $modelNames, true)
                ? $this->testEmbedding($emb, 30)
                : [
                    'ok' => false,
                    'len' => 0,
                    'model' => $emb,
                    'error' => 'Model is not listed by Ollama. Pull it before testing the connection.',
                ]);
        $chatResult = $chat === ''
            ? $this->testChat($chat, 60)
            : (in_array($chat, $modelNames, true)
                ? $this->testChat($chat, 60)
                : [
                    'ok' => false,
                    'model' => $chat,
                    'answer' => null,
                    'error' => 'Model is not listed by Ollama. Pull it before testing the connection.',
                ]);

        return [
            'server' => $server,
            'models' => $models,
            'embedding' => $embedding,
            'chat' => $chatResult,
        ];
    }

    /** @return array{ok:bool,len:?int,error:?string} */
    public function testEmbedding(string $model, int $timeout = 120): array {
        if ($model === '') {
            return ['ok' => false, 'len' => 0, 'model' => '', 'error' => 'No embedding model configured.'];
        }
        try {
            $r = $this->client()->post($this->base() . '/api/embed', [
                'json' => ['model' => $model, 'input' => ['Test']],
                'timeout' => $timeout,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $emb = $data['embeddings'][0] ?? null;
            if (is_array($emb)) {
                return ['ok' => true, 'len' => count($emb), 'model' => $model, 'error' => null];
            }
            return ['ok' => false, 'len' => 0, 'model' => $model, 'error' => 'No result vector returned.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'len' => 0, 'model' => $model, 'error' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,model:string,answer:?string,error:?string} */
    public function testChat(string $model, int $timeout = 240): array {
        if ($model === '') {
            return ['ok' => false, 'model' => '', 'answer' => null, 'error' => 'No chat model configured.'];
        }
        try {
            $start = microtime(true);
            $r = $this->client()->post($this->base() . '/api/generate', [
                'json' => [
                    'model' => $model,
                    'prompt' => 'Antworte nur mit dem Wort: ok',
                    'stream' => false,
                    'options' => ['num_ctx' => 1024, 'num_predict' => 8],
                ],
                'timeout' => $timeout,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $elapsed = round(microtime(true) - $start, 1);
            $answer = $data['response'] ?? null;
            if ($answer === null && isset($data['error'])) {
                return ['ok' => false, 'model' => $model, 'answer' => null, 'error' => $data['error'] . ' (' . $elapsed . 's)'];
            }
            if ($answer === null || trim($answer) === '') {
                return ['ok' => false, 'model' => $model, 'answer' => null, 'error' => 'Leere Antwort (' . $elapsed . 's)'];
            }
            return ['ok' => true, 'model' => $model, 'answer' => trim(substr($answer, 0, 80)), 'error' => null, 'seconds' => $elapsed];
        } catch (\Throwable $e) {
            return ['ok' => false, 'model' => $model, 'answer' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Embed a batch of texts, reusing user-isolated cached vectors where
     * possible. Duplicate misses are coalesced into one model input.
     *
     * A position that could not be embedded (the model rejected that input even
     * on its own) is reported as a `null` slot rather than as a batch error, so
     * one bad document cannot block the rest of the index pass. A `null` return
     * plus a non-null error still means the whole request failed.
     *
     * @param string[] $texts
     * @return array{0: array|null, 1: ?string} [vectors[], error]
     */
    public function embedBatch(array $texts, ?string $userId = null): array {
        $this->lastEmbeddingStats = ['cache_hits' => 0, 'cache_misses' => 0, 'ollama_requests' => 0];
        if (empty($texts)) {
            return [[], null];
        }

        $vectors = array_fill(0, count($texts), null);
        $misses = [];
        $missIndices = [];
        $seenMisses = [];
        foreach ($texts as $index => $text) {
            $text = (string)$text;
            if ($userId !== null && $userId !== '') {
                $cached = $this->embeddingCache->get($userId, $text);
                if ($cached !== null) {
                    $vectors[$index] = $cached['vector'];
                    $this->lastEmbeddingStats['cache_hits']++;
                    continue;
                }
            }
            $digest = hash('sha256', preg_replace('/\\s+/u', ' ', trim($text)) ?? trim($text));
            $missIndices[$digest][] = $index;
            if (!isset($seenMisses[$digest])) {
                $seenMisses[$digest] = true;
                $misses[$digest] = $text;
            }
        }

        $this->lastEmbeddingStats['cache_misses'] = count($misses);
        if ($misses === []) {
            return [$vectors, null];
        }

        $missTexts = array_values($misses);
        $model = $this->resolveEmbeddingModel();
        if ($model['error'] !== null) {
            return [null, $model['error']];
        }
        $modelName = $model['model'] ?? '';
        try {
            [$embs, $error] = $this->embedWithBisect($modelName, $missTexts);
            if ($embs === null) {
                if ($error !== null) {
                    return [null, $error];
                }
                // The endpoint answered, but with an unusable shape. Fall back
                // to the per-text endpoint once, retaining the same coalesced
                // miss set.
                [$embs, $error] = $this->embedBatchLegacy($missTexts);
                if ($error !== null || !is_array($embs)) {
                    return [null, $error ?? 'Unexpected embedding response'];
                }
            }

            // An empty slot means the model rejected that one input even on its
            // own. Those documents are skipped by the caller; they never take
            // the rest of the batch down with them.
            $dimension = null;
            foreach ($embs as $vector) {
                if ($vector === null) {
                    continue;
                }
                if (!$this->isNumericVector($vector)) {
                    return [null, 'Invalid embedding vector returned'];
                }
                $dimension ??= count($vector);
                if (count($vector) !== $dimension) {
                    return [null, 'Embedding vectors have inconsistent dimensions'];
                }
            }

            $cacheEntries = [];
            $missKeys = array_keys($misses);
            foreach ($missTexts as $i => $text) {
                if (($embs[$i] ?? null) === null) {
                    continue;
                }
                $vector = array_map('floatval', $embs[$i]);
                foreach ($missIndices[$missKeys[$i]] as $index) {
                    $vectors[$index] = $vector;
                }
                if ($userId !== null && $userId !== '') {
                    $cacheEntries[] = ['userId' => $userId, 'text' => $text, 'vector' => $vector];
                }
            }
            // Publish only after the complete response has been validated.
            $this->embeddingCache->putMany($cacheEntries);
            return [$vectors, null];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai embed batch failed', ['exception' => $e]);
            return [null, $e->getMessage()];
        }
    }

    /**
     * Deadline for one embedding request, scaled with the number of inputs.
     *
     * A fixed 30 second cap is what made large libraries fail to index: a
     * healthy but modest model server needs roughly a second per embedding, so
     * a 24-text batch exceeded the cap, the request was aborted, and the whole
     * index pass stopped after its first batch. The deadline therefore grows
     * with the batch and is only bounded so a stalled request cannot hang a
     * worker forever.
     */
    private function embedTimeout(int $textCount): int {
        return max(self::EMBED_TIMEOUT_FLOOR, min(self::EMBED_TIMEOUT_CEILING, 10 + $textCount * self::EMBED_SECONDS_PER_TEXT));
    }

    /**
     * Send one embedding request and report whether a failure may be retried
     * with a smaller batch.
     *
     * @param list<string> $texts
     * @return array{0:array|null,1:?string,2:bool,3:bool} embeddings, error, retryable, inputRejected
     */
    private function requestEmbeddings(string $modelName, array $texts): array {
        $payload = ['model' => $modelName, 'input' => array_values($texts)];
        $keepAlive = $this->keepAlive();
        if ($keepAlive !== null) {
            $payload['keep_alive'] = $keepAlive;
        }
        try {
            $this->lastEmbeddingStats['ollama_requests']++;
            $r = $this->client()->post($this->base() . '/api/embed', [
                'json' => $payload,
                'timeout' => $this->embedTimeout(count($texts)),
                'read_timeout' => self::EMBED_READ_TIMEOUT,
                'connect_timeout' => 10,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $embs = $data['embeddings'] ?? null;
            if (!is_array($embs) || count($embs) !== count($texts)) {
                // Shape problem, not a transport problem: the caller falls back
                // to the legacy endpoint instead of bisecting.
                return [null, null, false, false];
            }
            return [$embs, null, false, false];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai embed batch failed', [
                'exception' => $e,
                'texts' => count($texts),
                'timeout' => $this->embedTimeout(count($texts)),
            ]);
            return [null, $e->getMessage(), true, $this->isInputRejection($e)];
        }
    }

    /**
     * Decide whether a failed embedding request was refused because of its
     * input rather than because the server could not serve it.
     *
     * A 4xx from the model server means "this text is not something I can
     * embed", so the document is skipped. A timeout, a refused connection or a
     * 5xx means the server is overloaded or down, and skipping every document in
     * silence would report a successful pass that indexed nothing - so that case
     * stays a real error the admin can see.
     */
    private function isInputRejection(\Throwable $e): bool {
        if (!$e instanceof \GuzzleHttp\Exception\BadResponseException) {
            return false;
        }
        $status = $e->getResponse()->getStatusCode();
        return $status >= 400 && $status < 500;
    }

    /**
     * Embed texts, halving the batch on a transport failure.
     *
     * A timeout or a dropped connection is usually a capacity problem, not a
     * broken input, so retrying the same batch smaller lets a slow model server
     * still make progress. Without this, one slow batch aborted the entire
     * index pass and the staged documents were discarded.
     *
     * @param list<string> $texts
     * @return array{0:array|null,1:?string}
     */
    private function embedWithBisect(string $modelName, array $texts): array {
        [$embs, $error, $retryable, $inputRejected] = $this->requestEmbeddings($modelName, $texts);
        if ($embs !== null) {
            return [$embs, null];
        }
        if (!$retryable) {
            // A malformed response is not a capacity problem; the caller falls
            // back to the per-text endpoint by signalling "no error, no data".
            return [null, null];
        }
        if (count($texts) <= 1) {
            if (!$inputRejected) {
                // Alone, the request still failed, but the cause is the server
                // (timeout, refused connection, 5xx): that must surface as an
                // error, not as a quietly skipped document.
                return [null, $error];
            }
            // The model itself refused this input. Report it as an empty slot
            // instead of failing the batch: the indexer skips that one document
            // and keeps the rest of the pass running - a single file must never
            // stall the whole background index.
            $this->logger->warning('eva_ai: skipping an unembeddable text', [
                'reason' => $error,
                'chars' => mb_strlen((string)($texts[0] ?? '')),
            ]);
            return [[null], null];
        }

        $half = intdiv(count($texts), 2);
        $out = [];
        foreach ([array_slice(array_values($texts), 0, $half), array_slice(array_values($texts), $half)] as $part) {
            [$partEmbs, $partError] = $this->embedWithBisect($modelName, $part);
            if ($partEmbs === null) {
                return [null, $partError];
            }
            foreach ($partEmbs as $vector) {
                $out[] = $vector;
            }
        }
        return [$out, null];
    }

    /** @return array{cache_hits:int,cache_misses:int,ollama_requests:int} */
    public function lastEmbeddingStats(): array {
        return $this->lastEmbeddingStats;
    }

    /** @param string[] $texts */
    private function embedBatchLegacy(array $texts): array {
        $model = $this->resolveEmbeddingModel();
        if ($model['error'] !== null) {
            return [null, $model['error']];
        }
        $modelName = $model['model'] ?? '';
        try {
            $out = [];
            foreach ($texts as $t) {
                $this->lastEmbeddingStats['ollama_requests']++;
                $payload = ['model' => $modelName, 'prompt' => $t];
                $keepAlive = $this->keepAlive();
                if ($keepAlive !== null) {
                    $payload['keep_alive'] = $keepAlive;
                }
                $r = $this->client()->post($this->base() . '/api/embeddings', [
                    'json' => $payload,
                    'timeout' => $this->embedTimeout(1),
                    'read_timeout' => self::EMBED_READ_TIMEOUT,
                    'connect_timeout' => 10,
                ]);
                $data = json_decode((string)$r->getBody(), true);
                if (isset($data['embedding'])) {
                    $out[] = $data['embedding'];
                } else {
                    return [null, 'Unexpected embedding response'];
                }
            }
            return [$out, null];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai embed legacy failed: ' . ($modelName ?? ''), ['exception' => $e]);
            return [null, 'Ollama embedding fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    /** @param string[] $texts */
    public function embedQuery(array $texts, ?string $userId = null): array {
        return $this->embedBatch($texts, $userId);
    }

    private function isNumericVector(mixed $vector): bool {
        if (!is_array($vector) || $vector === []) {
            return false;
        }
        foreach ($vector as $value) {
            if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recursively convert empty associative arrays to JSON objects so Ollama
     * doesn't reject tool schemas with `Value looks like object, but can't find closing '}'`.
     * PHP encodes `[]` as JSON array, but Ollama requires `{}` for object fields
     * like `parameters.properties`. Also normalize nested structures.
     */
    private function normalizePayload(mixed $value): mixed {
        if (is_array($value)) {
            $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc || $value === []) {
                $obj = new \stdClass();
                foreach ($value as $k => $v) {
                    $obj->{$k} = $this->normalizePayload($v);
                }
                return $obj;
            }
            $out = [];
            foreach ($value as $i => $v) {
                $out[$i] = $this->normalizePayload($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Non-streaming chat.
     *
     * Every call is bounded by a total timeout (default {@see self::CHAT_TIMEOUT},
     * 120 s) plus a read timeout so a slow model cannot silently occupy a web
     * or TaskProcessing worker for up to ten minutes (Issue #92). Callers that
     * run in a dedicated worker and provide an $onProgress callback switch to
     * an internal streaming request: they report progress as tokens arrive,
     * their reads are idle-bounded and a timeout surfaces as a clear error.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param callable(float):void|null $onProgress called with a monotonically
     *        increasing estimate between 0 and 1 while the model generates.
     * @return array{answer?:string,error?:string,model?:string}
     */
    public function chat(array $messages, array $tools = [], ?int $timeout = null, ?callable $onProgress = null, ?string $preferredModel = null): array {
        if ($this->config->get('chat_provider') === 'groq') {
            if ($onProgress !== null) return $this->chatStreamingAccumulate($messages, $tools, $timeout, $this->config->get('groq_model'), $onProgress, null);
            return $this->groqClient()->chat($messages, $tools, $timeout ?? self::CHAT_TIMEOUT);
        }
        $provider = strtolower(trim((string)$this->config->get('chat_provider')));
        if ($provider === '') $provider = 'ollama';
        if ($provider !== 'ollama') {
            if ($onProgress !== null) { $result = $this->openAiCompatible()->chat($messages, $tools, $timeout ?? self::CHAT_TIMEOUT); if (isset($result['answer'])) $onProgress(1.0); return $result; }
            return $this->openAiCompatible()->chat($messages, $tools, $timeout ?? self::CHAT_TIMEOUT);
        }
        $model = $this->resolveChatModel($preferredModel);
        if ($model['error'] !== null) {
            return ['error' => $model['error']];
        }
        $modelName = $model['model'] ?? '';
        if ($modelName === '') {
            return ['error' => 'No chat model configured.'];
        }
        if ($onProgress !== null) {
            return $this->chatStreamingAccumulate($messages, $tools, $timeout, $modelName, $onProgress, $preferredModel);
        }
        $payload = [
            'model' => $modelName,
            'messages' => $messages,
            'stream' => false,
            'options' => $this->ollamaOptions(),
        ];
        $keepAlive = $this->keepAlive();
        if ($keepAlive !== null) {
            $payload['keep_alive'] = $keepAlive;
        }
        if ($tools !== []) {
            $payload['tools'] = $this->normalizePayload($tools);
        }
        $totalTimeout = $timeout ?? self::CHAT_TIMEOUT;
        $startedAt = microtime(true);
        try {
            $r = $this->client()->post($this->base() . '/api/chat', [
                'json' => $payload,
                'timeout' => max(1, $totalTimeout),
                // Bound idle reads too: an unresponsive endpoint releases the
                // worker instead of holding it until the total timeout.
                'read_timeout' => 30,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $msg = $data['message'] ?? [];
            $rawToolCalls = $msg['tool_calls'] ?? [];
            $toolCalls = $this->normalizeToolCalls($rawToolCalls);
            $answer = (string)($msg['content'] ?? '');
            $this->recordUsage($modelName, $messages, $answer, $data, $startedAt);
            if (isset($msg['content']) && $msg['content'] !== '') {
                return [
                    'answer' => $msg['content'],
                    'model' => $data['model'] ?? $modelName,
                    'tool_calls' => $toolCalls,
                    'raw_tool_calls' => $rawToolCalls,
                ];
            }
            if ($toolCalls !== []) {
                return ['answer' => '', 'model' => $data['model'] ?? $modelName, 'tool_calls' => $toolCalls, 'raw_tool_calls' => $rawToolCalls];
            }
            if (isset($data['error'])) {
                return ['error' => $data['error']];
            }
            return ['error' => 'Ollama: empty answer'];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai ollama chat failed', ['exception' => $e]);
            return ['error' => $this->boundedError($e, $totalTimeout)];
        }
    }

    /**
     * Run the chat as an internal NDJSON stream while accumulating the final
     * answer, used when a caller wants progress reports during generation.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param callable(float):void $onProgress
     * @return array{answer?:string,error?:string,model?:string}
     */
    private function chatStreamingAccumulate(array $messages, array $tools, ?int $timeout, string $defaultModel, callable $onProgress, ?string $preferredModel = null): array {
        // Progress-reporting callers run inside a dedicated worker, so the
        // generous total budget from the streaming path is acceptable: reads
        // stay idle-bounded and every token is reported to the task.
        $totalTimeout = $timeout ?? self::TIMEOUT;
        $answer = '';
        $model = $defaultModel;
        $toolCalls = [];
        $rawToolCalls = [];
        $chunks = 0;
        foreach ($this->chatStream($messages, $tools, $totalTimeout, $preferredModel) as $ev) {
            $evType = $ev['type'] ?? '';
            if ($evType === 'content') {
                $answer .= (string)($ev['delta'] ?? '');
                $chunks++;
                // A cheap monotonic estimate; the caller maps it into its own
                // progress window.
                $onProgress(min(0.98, $chunks * 0.01));
            } elseif ($evType === 'tool_calls') {
                $toolCalls = $ev['tool_calls'] ?? [];
                $rawToolCalls = $ev['raw'] ?? [];
                if (isset($ev['model']) && $ev['model'] !== '') {
                    $model = (string)$ev['model'];
                }
            } elseif ($evType === 'finished') {
                if (isset($ev['model']) && $ev['model'] !== '') {
                    $model = (string)$ev['model'];
                }
            } elseif ($evType === 'error') {
                return ['error' => (string)($ev['delta'] ?? 'Ollama request failed')];
            }
        }
        if ($toolCalls !== []) {
            return ['answer' => trim($answer), 'model' => $model, 'tool_calls' => $toolCalls, 'raw_tool_calls' => $rawToolCalls];
        }
        if (trim($answer) !== '') {
            return ['answer' => trim($answer), 'model' => $model, 'tool_calls' => [], 'raw_tool_calls' => []];
        }
        return ['error' => 'Ollama: empty answer'];
    }

    /** Build a clear, user-facing timeout error message. */
    private function boundedError(\Throwable $e, int $timeoutSeconds): string {
        $message = $e->getMessage();
        if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
            return 'Ollama request timed out after ' . $timeoutSeconds . ' seconds. The model may still be loading - retry or choose a smaller context size.';
        }
        return 'Ollama error: ' . $message;
    }

    /** @param array<int,array{role?:string,content?:string}> $messages */
    private function recordUsage(string $model, array $messages, string $answer, array $data, float $startedAt): void {
        $this->usageMetrics?->recordChat(
            $this->config->userId(),
            'ollama',
            (string)($data['model'] ?? $model),
            $messages,
            $answer,
            isset($data['prompt_eval_count']) ? (int)$data['prompt_eval_count'] : null,
            isset($data['eval_count']) ? (int)$data['eval_count'] : null,
            (int)round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Streaming chat: yields ['type' => 'thinking'|'content', 'delta' => string]
     * events as tokens arrive from Ollama (NDJSON stream).
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<string,array{type:string,delta:string},void,void>
     */
    public function chatStream(array $messages, array $tools = [], ?int $timeout = null, ?string $preferredModel = null): \Generator {
        $provider = strtolower(trim((string)$this->config->get('chat_provider')));
        if ($provider === '') $provider = 'ollama';
        if ($provider === 'groq') {
            yield from $this->groqClient()->chatStream($messages, $tools, $timeout ?? self::CHAT_TIMEOUT);
            return;
        }
        if ($provider !== 'ollama') {
            $result = $this->openAiCompatible()->chat($messages, $tools, $timeout ?? self::CHAT_TIMEOUT);
            if (isset($result['error'])) yield ['type' => 'error', 'delta' => $result['error']];
            elseif (($result['tool_calls'] ?? []) !== []) yield ['type' => 'tool_calls', 'tool_calls' => $result['tool_calls'], 'raw' => $result['raw_tool_calls'] ?? [], 'model' => $result['model'] ?? ''];
            else yield ['type' => 'content', 'delta' => $result['answer'] ?? ''];
            yield ['type' => 'finished', 'model' => $result['model'] ?? ''];
            return;
        }
        $model = $this->resolveChatModel($preferredModel);
        if ($model['error'] !== null) {
            yield ['type' => 'error', 'delta' => $model['error']];
            return;
        }
        $modelName = $model['model'] ?? '';
        if ($modelName === '') {
            yield ['type' => 'error', 'delta' => 'No chat model configured.'];
            return;
        }
        $payload = [
            'model' => $modelName,
            'messages' => $messages,
            'stream' => true,
            'options' => $this->ollamaOptions(),
        ];
        $keepAlive = $this->keepAlive();
        if ($keepAlive !== null) {
            $payload['keep_alive'] = $keepAlive;
        }
        if ($tools !== []) {
            $payload['tools'] = $this->normalizePayload($tools);
        }
        $body = null;
        $startedAt = microtime(true);
        $streamAnswer = '';
        try {
            if ($this->clientDisconnected()) {
                return;
            }
            $r = $this->client()->post($this->base() . '/api/chat', [
                'json' => $payload,
                'timeout' => max(1, $timeout ?? self::TIMEOUT),
                // Bound idle reads so disconnect checks can release the worker
                // even when Ollama temporarily emits no token.
                'read_timeout' => 5,
                'stream' => true,
            ]);
            $body = $r->getBody();
            $buffer = '';
            $streamCalls = [];
            while (is_resource($body) ? !feof($body) : !$body->eof()) {
                if ($this->clientDisconnected()) {
                    return;
                }
                $chunk = is_resource($body) ? fread($body, 8192) : $body->read(8192);
                if ($chunk === '') {
                    usleep(10000);
                    continue;
                }
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    if (trim($line) === '') {
                        continue;
                    }
                    if ($this->clientDisconnected()) {
                        return;
                    }
                    $obj = json_decode($line, true);
                    if (!is_array($obj)) {
                        continue;
                    }
                    $msg = $obj['message'] ?? [];
                    if (is_array($msg) && !empty($msg['tool_calls'])) {
                        // Ollama streamt Tool-Call-Argumente in mehreren Chunks:
                        // nach Index akkumulieren statt überschreiben.
                        foreach ($msg['tool_calls'] as $tc) {
                            $idx = (int)($tc['index'] ?? 0);
                            $fn = $tc['function'] ?? [];
                            if (!isset($streamCalls[$idx])) {
                                $streamCalls[$idx] = [
                                    'id' => (string)($tc['id'] ?? ('call_' . random_int(100000, 999999))),
                                    'type' => 'function',
                                    'function' => ['name' => (string)($fn['name'] ?? ''), 'arguments' => ''],
                                ];
                            }
                            if (isset($fn['name']) && $fn['name'] !== '') {
                                $streamCalls[$idx]['function']['name'] = (string)$fn['name'];
                            }
                            if (isset($fn['arguments'])) {
                                $arg = $fn['arguments'];
                                if (is_array($arg)) {
                                    $arg = json_encode($arg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                }
                                if (is_string($arg) && $arg !== '') {
                                    $streamCalls[$idx]['function']['arguments'] .= $arg;
                                }
                            }
                        }
                    }
                    if (!empty($obj['done'])) {
                        if ($this->clientDisconnected()) {
                            return;
                        }
                        $doneModel = isset($obj['model']) ? (string)$obj['model'] : '';
                        $usageData = [
                            'model' => $doneModel !== '' ? $doneModel : $modelName,
                            'prompt_eval_count' => isset($obj['prompt_eval_count']) ? (int)$obj['prompt_eval_count'] : null,
                            'eval_count' => isset($obj['eval_count']) ? (int)$obj['eval_count'] : null,
                        ];
                        $this->recordUsage($modelName, $messages, $streamAnswer, $usageData, $startedAt);
                        if ($streamCalls !== []) {
                            ksort($streamCalls);
                            $rawToolCalls = array_values($streamCalls);
                            yield ['type' => 'tool_calls', 'tool_calls' => $this->normalizeToolCalls($streamCalls), 'raw' => $rawToolCalls, 'model' => $doneModel];
                        } else {
                            yield ['type' => 'finished', 'model' => $doneModel];
                        }
                        return;
                    }
                    if (!is_array($msg)) {
                        continue;
                    }
                    $thinking = (string)($msg['thinking'] ?? $msg['reasoning'] ?? '');
                    if ($thinking !== '') {
                        if ($this->clientDisconnected()) {
                            return;
                        }
                        yield ['type' => 'thinking', 'delta' => $thinking];
                    }
                    $content = (string)($msg['content'] ?? '');
                    if ($content !== '') {
                        $streamAnswer .= $content;
                        if ($this->clientDisconnected()) {
                            return;
                        }
                        yield ['type' => 'content', 'delta' => $content];
                    }
                }
            }
        } catch (\Throwable $e) {
            if (!$this->clientDisconnected()) {
                $this->logger->error('eva_ai ollama chat stream failed', ['exception' => $e]);
                yield ['type' => 'error', 'delta' => 'Ollama error: ' . $e->getMessage()];
            }
        } finally {
            if (is_resource($body)) {
                @fclose($body);
            } elseif (is_object($body) && method_exists($body, 'close')) {
                try {
                    $body->close();
                } catch (\Throwable $ignored) {
                    // Cleanup must never mask the original stream result.
                }
            }
        }
    }

    private function clientDisconnected(): bool {
        return function_exists('connection_aborted') && connection_aborted() > 0;
    }

    /**
     * Ollama may deliver arguments as JSON string or as an array.
     * @param array $raw
     * @return array<int,array{name:string,arguments:array}>
     */
    private function normalizeToolCalls(array $raw): array {
        $out = [];
        foreach ($raw as $tc) {
            $fn = $tc['function'] ?? [];
            $name = (string)($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = $fn['arguments'] ?? '';
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($args)) {
                $args = [];
            }
            $out[] = ['name' => $name, 'arguments' => $args];
        }
        return $out;
    }
}
