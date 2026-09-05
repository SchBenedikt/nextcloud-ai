<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class Ollama {
    private const TIMEOUT = 600;
    private const STATUS_CACHE_TTL = 30;

    /** @var array<string,array{expires:int,ping:array,models:array}> */
    private static array $statusCache = [];

    private ?ICache $statusStore = null;
    private bool $statusStoreInitialized = false;

    public function __construct(
        private AppConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
        private ICacheFactory $cacheFactory
    ) {
    }

    private function client() {
        return $this->clientService->newClient();
    }

    private function base(): string {
        return $this->config->ollamaUrl();
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
     * Return connectivity and model information from one /api/tags request.
     * The short process-local cache prevents repeated status polls from
     * multiplying requests while keeping the information at most 30 seconds
     * stale. Explicit connection checks continue to use testAll().
     *
     * @return array{ping:array,models:array<int,array<string,mixed>>}
     */
    public function status(): array {
        $base = $this->base();
        $now = time();
        $key = 'tags_' . hash('sha256', $base);
        $cached = $this->readStatusCache($key, $base, $now);
        if ($cached !== null) {
            return ['ping' => $cached['ping'], 'models' => $cached['models']];
        }

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

        $entry = [
            'expires' => $now + self::STATUS_CACHE_TTL,
            'ping' => $ping,
            'models' => $models,
        ];
        self::$statusCache[$base] = $entry;
        try {
            $this->statusStore()->set($key, $entry, self::STATUS_CACHE_TTL);
        } catch (\Throwable $e) {
            // The process-local fallback above still protects long-running
            // workers if the configured cache backend is temporarily absent.
        }
        return ['ping' => $ping, 'models' => $models];
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
    public function listModels(): array {
        try {
            $r = $this->client()->get($this->base() . '/api/tags', ['timeout' => 20]);
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
            return ['ok' => false, 'model' => '', 'answer' => null, 'error' => 'Kein Chat-Modell konfiguriert.'];
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
     * Embed a batch of texts.
     * @param string[] $texts
     * @return array{0: array, 1: ?string} [vectors[], error]
     */
    public function embedBatch(array $texts): array {
        if (empty($texts)) {
            return [[], null];
        }
        $model = $this->config->get('embedding_model');
        try {
            $body = ['model' => $model, 'input' => $texts];
            $r = $this->client()->post($this->base() . '/api/embed', [
                'json' => $body,
                // Keep cancellation responsive while allowing a cold model
                // enough time to produce a normal batch response.
                'timeout' => 30,
                'read_timeout' => 5,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $embs = $data['embeddings'] ?? null;
            if (is_array($embs) && count($embs) === count($texts)) {
                return [$embs, null];
            }
            // Fallback: per-text
            return $this->embedBatchLegacy($texts);
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai embed batch failed', ['exception' => $e]);
            return [null, $e->getMessage()];
        }
    }

    /** @param string[] $texts */
    private function embedBatchLegacy(array $texts): array {
        $model = $this->config->get('embedding_model');
        try {
            $out = [];
            foreach ($texts as $t) {
                $r = $this->client()->post($this->base() . '/api/embeddings', [
                    'json' => ['model' => $model, 'prompt' => $t],
                    'timeout' => 30,
                    'read_timeout' => 5,
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
            $this->logger->error('eva_ai embed legacy failed: ' . $model, ['exception' => $e]);
            return [null, 'Ollama embedding fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    /** @param float[] $vector */
    public function embedQuery(array $texts): array {
        return $this->embedBatch($texts);
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
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{answer?:string,error?:string,model?:string}
     */
    public function chat(array $messages, array $tools = []): array {
        $model = $this->config->get('chat_model');
        if ($model === '') {
            return ['error' => 'Kein Chat-Modell konfiguriert.'];
        }
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))),
                'num_ctx' => max(256, min(131072, (int)$this->config->get('context_size'))),
            ],
        ];
        if ($tools !== []) {
            $payload['tools'] = $this->normalizePayload($tools);
        }
        try {
            $r = $this->client()->post($this->base() . '/api/chat', [
                'json' => $payload,
                'timeout' => self::TIMEOUT,
            ]);
            $data = json_decode((string)$r->getBody(), true);
            $msg = $data['message'] ?? [];
            $rawToolCalls = $msg['tool_calls'] ?? [];
            $toolCalls = $this->normalizeToolCalls($rawToolCalls);
            if (isset($msg['content']) && $msg['content'] !== '') {
                return [
                    'answer' => $msg['content'],
                    'model' => $data['model'] ?? $model,
                    'tool_calls' => $toolCalls,
                    'raw_tool_calls' => $rawToolCalls,
                ];
            }
            if ($toolCalls !== []) {
                return ['answer' => '', 'model' => $data['model'] ?? $model, 'tool_calls' => $toolCalls, 'raw_tool_calls' => $rawToolCalls];
            }
            if (isset($data['error'])) {
                return ['error' => $data['error']];
            }
            return ['error' => 'Ollama: empty answer'];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai ollama chat failed', ['exception' => $e]);
            return ['error' => 'Ollama error: ' . $e->getMessage()];
        }
    }

    /**
     * Streaming chat: yields ['type' => 'thinking'|'content', 'delta' => string]
     * events as tokens arrive from Ollama (NDJSON stream).
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<string,array{type:string,delta:string},void,void>
     */
    public function chatStream(array $messages, array $tools = []): \Generator {
        $model = $this->config->get('chat_model');
        if ($model === '') {
            yield ['type' => 'error', 'delta' => 'No chat model configured.'];
            return;
        }
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'options' => [
                'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))),
                'num_ctx' => max(256, min(131072, (int)$this->config->get('context_size'))),
            ],
        ];
        if ($tools !== []) {
            $payload['tools'] = $this->normalizePayload($tools);
        }
        $body = null;
        try {
            if ($this->clientDisconnected()) {
                return;
            }
            $r = $this->client()->post($this->base() . '/api/chat', [
                'json' => $payload,
                'timeout' => self::TIMEOUT,
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
                        if ($streamCalls !== []) {
                            ksort($streamCalls);
                            $rawToolCalls = array_values($streamCalls);
                            yield ['type' => 'tool_calls', 'tool_calls' => $this->normalizeToolCalls($streamCalls), 'raw' => $rawToolCalls];
                        } else {
                            yield ['type' => 'finished'];
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