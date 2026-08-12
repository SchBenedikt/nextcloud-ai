<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class Ollama {
    private const TIMEOUT = 600;

    public function __construct(
        private AppConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger
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
            return ['ok' => $r->getStatusCode() === 200, 'url' => $this->base()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $this->base(), 'error' => $e->getMessage()];
        }
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
        return [
            'server' => $this->ping(),
            'models' => $this->listModels(),
            'embedding' => $this->testEmbedding($emb),
            'chat' => $this->testChat($chat),
        ];
    }

    /** @return array{ok:bool,len:?int,error:?string} */
    public function testEmbedding(string $model): array {
        if ($model === '') {
            return ['ok' => false, 'len' => 0, 'model' => '', 'error' => 'No embedding model configured.'];
        }
        try {
            $r = $this->client()->post($this->base() . '/api/embed', [
                'json' => ['model' => $model, 'input' => ['Test']],
                'timeout' => 120,
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
    public function testChat(string $model): array {
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
                'timeout' => 240,
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
                'timeout' => self::TIMEOUT,
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
                    'timeout' => self::TIMEOUT,
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
        try {
            $r = $this->client()->post($this->base() . '/api/chat', [
                'json' => $payload,
                'timeout' => self::TIMEOUT,
                'stream' => true,
            ]);
            $body = $r->getBody();
            $buffer = '';
            $streamCalls = [];
            while (is_resource($body) ? !feof($body) : !$body->eof()) {
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
                        yield ['type' => 'thinking', 'delta' => $thinking];
                    }
                    $content = (string)($msg['content'] ?? '');
                    if ($content !== '') {
                        yield ['type' => 'content', 'delta' => $content];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai ollama chat stream failed', ['exception' => $e]);
            yield ['type' => 'error', 'delta' => 'Ollama error: ' . $e->getMessage()];
        }
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