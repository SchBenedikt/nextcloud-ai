<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/** Groq chat adapter. Embeddings deliberately remain on the local Ollama path. */
class Groq {
    private const BASE = 'https://api.groq.com/openai/v1';
    // General-purpose production chat models on the published Free Plan, 2026-09-09.
    // No Compound built-in tools, enterprise models or automatic paid fallback.
    public const MODELS = ['openai/gpt-oss-20b', 'openai/gpt-oss-120b'];
    public function __construct(private AppConfig $config, private IClientService $clients, private ProviderCredentials $credentials, private ?UsageMetrics $usageMetrics = null) {}
    public function info(): array {
        return ['name' => 'groq', 'models' => self::MODELS, 'keyConfigured' => $this->credentials->configured($this->config->userId() ?? ''), 'model' => $this->config->get('groq_model')];
    }
    public function saveKey(string $key): void { $this->credentials->save($this->config->userId() ?? '', $key); }
    private function options(bool $stream, int $timeout): array {
        return ['headers' => ['Authorization' => 'Bearer ' . $this->credentials->get($this->config->userId() ?? '')],
            'timeout' => max(1, min(120, $timeout)), 'connect_timeout' => 10, 'read_timeout' => 30,
            'stream' => $stream, 'http_errors' => false, 'allow_redirects' => false];
    }
    private function checkResponse(IResponse $response): void {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) return;
        $model = $this->config->get('groq_model');
        if ($status === 429 || $status === 413) {
            // Extract only numeric diagnostics. Never expose raw provider errors,
            // organization IDs, request data or Authorization headers.
            $body = $response->getBody();
            try {
                $raw = is_resource($body) ? stream_get_contents($body, 65536)
                    : (is_object($body) && method_exists($body, 'read') ? $body->read(65536) : substr((string)$body, 0, 65536));
            } finally {
                if (is_resource($body)) fclose($body);
                elseif (is_object($body) && method_exists($body, 'close')) $body->close();
            }
            $data = json_decode((string)$raw, true);
            $detail = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : '';
            $numbers = [];
            foreach (['Limit', 'Used', 'Requested'] as $label) {
                if (preg_match('/\b' . $label . '\s*[:=]?\s*(\d{1,10})(?:\b|\.)/i', $detail, $match)) $numbers[$label] = (int)$match[1];
            }
            $oversized = $status === 413 || stripos($detail, 'request too large') !== false
                || (isset($numbers['Limit'], $numbers['Requested']) && $numbers['Requested'] > $numbers['Limit']);
            $message = $oversized
                ? 'Groq (' . $model . '): this request exceeds the token limit. Reduce file context or shorten the message; waiting alone will not help.'
                : 'Groq (' . $model . '): rate limit reached for this model/account. A short curl request can still fit while the chat context does not.';
            foreach ($numbers as $label => $number) $message .= ' ' . $label . ': ' . $number . '.';
            $retry = $response->getHeader('retry-after');
            if (!$oversized && preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', $retry)) $message .= ' Retry after ' . $retry . ' seconds.';
            throw new ProviderException($message);
        }
        throw new ProviderException(match ($status) {
            401, 403 => 'Groq rejected the API key or model access. Check the saved key and account permissions.',
            400 => 'Groq rejected the request. Try a shorter conversation or fewer tools.',
            default => 'Groq request failed (HTTP ' . $status . '). Please retry later.',
        });
    }
    public function check(): array {
        try {
            $response = $this->clients->newClient()->get(self::BASE . '/models', $this->options(false, 15));
            $this->checkResponse($response);
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $models = array_values(array_intersect(self::MODELS, array_column($data['data'] ?? [], 'id')));
            $ok = in_array($this->config->get('groq_model'), $models, true);
            return ['ok' => $ok, 'models' => $models, 'error' => $ok ? null : 'The selected Groq model is unavailable for this account'];
        } catch (\Throwable $e) { return ['ok' => false, 'models' => [], 'error' => $this->safeError($e)]; }
    }
    private function safeError(\Throwable $e): string {
        // Never return/log HTTP exceptions: they can contain Authorization headers.
        return $e instanceof ProviderException ? $e->getMessage() : 'Groq connection or response failed. Check the key, network and account limits.';
    }
    private function payload(array $messages, array $tools, bool $stream): array {
        $model = $this->config->get('groq_model');
        if (!in_array($model, self::MODELS, true)) throw new ProviderException('Groq model is not in the supported free-plan selection');
        $out = [];
        $pendingIds = [];
        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? '');
            if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) continue;
            $row = ['role' => $role, 'content' => (string)($message['content'] ?? '')];
            if ($role === 'assistant' && !empty($message['tool_calls'])) {
                $row['tool_calls'] = [];
                $pendingIds = [];
                foreach ($message['tool_calls'] as $call) {
                    $id = (string)($call['id'] ?? ('call_' . count($out) . '_' . count($pendingIds)));
                    $args = $call['function']['arguments'] ?? [];
                    $row['tool_calls'][] = ['id' => $id, 'type' => 'function', 'function' => ['name' => (string)($call['function']['name'] ?? ''), 'arguments' => is_string($args) ? $args : json_encode($args === [] ? new \stdClass() : $args, JSON_THROW_ON_ERROR)]];
                    $pendingIds[] = $id;
                }
            }
            if ($role === 'tool') {
                $id = array_shift($pendingIds);
                if ($id === null) continue;
                $row['tool_call_id'] = $id;
            }
            $out[] = $row;
        }
        $payload = ['model' => $model, 'messages' => $out, 'stream' => $stream, 'max_completion_tokens' => 1024,
            'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature')))];
        if ($tools !== []) {
            $payload['tools'] = $this->compactDescriptions($tools);
            $payload['parallel_tool_calls'] = false;
        }
        if ($stream) {
            // Groq includes exact usage in the final stream event when this is
            // requested; older endpoints simply fall back to the UI estimate.
            $payload['stream_options'] = ['include_usage' => true];
        }
        // Byte-based, conservative input budget, not a tokenizer claim. Drop
        // oldest complete turns, never system instructions or the current turn
        // (including assistant tool calls and their paired results).
        while (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > 28000) {
            $users = array_keys(array_filter($payload['messages'], static fn($m) => $m['role'] === 'user'));
            if (count($users) < 2) throw new ProviderException('Groq request context is too large. Reduce retrieved file context or shorten the current message. No request was sent.');
            $start = $users[0]; $end = $users[1];
            $payload['messages'] = array_values(array_filter($payload['messages'], static fn($m, $i) => $i < $start || $i >= $end || $m['role'] === 'system', ARRAY_FILTER_USE_BOTH));
        }
        return $payload;
    }
    /** Preserve every tool and schema constraint; shorten explanatory prose only. */
    private function compactDescriptions(array $value): array {
        foreach ($value as $key => $item) {
            if ($key === 'description' && is_string($item) && mb_strlen($item) > 160) {
                $value[$key] = mb_substr($item, 0, 157) . '...';
            } elseif (is_array($item)) {
                $value[$key] = $this->compactDescriptions($item);
            }
        }
        return $value;
    }
    private function calls(array $raw): array {
        return array_map(static function ($call) {
            $args = $call['function']['arguments'] ?? '{}';
            $args = is_string($args) ? json_decode($args, true, 512, JSON_THROW_ON_ERROR) : $args;
            if (!is_array($args)) throw new ProviderException('Groq returned invalid tool arguments');
            return ['name' => (string)($call['function']['name'] ?? ''), 'arguments' => $args];
        }, $raw);
    }
    public function chat(array $messages, array $tools, int $timeout = 120): array {
        $startedAt = microtime(true);
        try {
            $options = $this->options(false, $timeout);
            $options['json'] = $this->payload($messages, $tools, false);
            $response = $this->clients->newClient()->post(self::BASE . '/chat/completions', $options);
            $this->checkResponse($response);
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (in_array($data['choices'][0]['finish_reason'] ?? '', ['length', 'content_filter'], true)) throw new ProviderException('Groq could not complete the response; shorten the request');
            $message = $data['choices'][0]['message'] ?? null;
            if (!is_array($message)) throw new ProviderException('Groq returned no answer');
            $raw = $message['tool_calls'] ?? [];
            $answer = (string)($message['content'] ?? '');
            $usage = $data['usage'] ?? [];
            $this->usageMetrics?->recordChat($this->config->userId(), 'groq', $this->config->get('groq_model'), $messages, $answer, isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : null, isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null, (int)round((microtime(true) - $startedAt) * 1000));
            return ['answer' => $answer, 'model' => $this->config->get('groq_model'), 'tool_calls' => $this->calls($raw), 'raw_tool_calls' => $raw, 'usage' => $usage];
        } catch (\Throwable $e) { return ['error' => $this->safeError($e)]; }
    }
    public function chatStream(array $messages, array $tools, int $timeout = 120): \Generator {
        $body = null;
        $startedAt = microtime(true);
        $streamAnswer = '';
        $usage = [];
        try {
            $options = $this->options(true, $timeout);
            $options['json'] = $this->payload($messages, $tools, true);
            $response = $this->clients->newClient()->post(self::BASE . '/chat/completions', $options);
            $this->checkResponse($response);
            $body = $response->getBody();
            $buffer = '';
            $calls = [];
            $finished = false;
            while (is_resource($body) ? !feof($body) : !$body->eof()) {
                if (connection_aborted()) return;
                $buffer .= is_resource($body) ? fread($body, 8192) : $body->read(8192);
                if (strlen($buffer) > 2097152) throw new ProviderException('Groq stream event exceeds the size limit');
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if (!str_starts_with($line, 'data:')) continue;
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') { $finished = true; break 2; }
                    $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($event['error'])) throw new ProviderException('Groq stream reported an error');
                    if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
                    if (in_array($event['choices'][0]['finish_reason'] ?? '', ['length', 'content_filter'], true)) throw new ProviderException('Groq could not complete the response; no tool calls were released');
                    $delta = $event['choices'][0]['delta'] ?? [];
                    if (!empty($delta['content'])) {
                        $streamAnswer .= (string)$delta['content'];
                        yield ['type' => 'content', 'delta' => $delta['content']];
                    }
                    foreach ($delta['tool_calls'] ?? [] as $call) {
                        $index = (int)($call['index'] ?? 0);
                        if ($index < 0 || $index > 31) throw new ProviderException('Groq returned too many tool calls');
                        $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                        if (isset($call['id'])) $calls[$index]['id'] = $call['id'];
                        foreach (['name', 'arguments'] as $field) $calls[$index]['function'][$field] .= (string)($call['function'][$field] ?? '');
                        if (strlen($calls[$index]['function']['arguments']) > 2097152) throw new ProviderException('Groq tool arguments exceed the size limit');
                    }
                }
            }
            if (!$finished) throw new ProviderException('Groq stream ended before completion; no tool calls were executed');
            ksort($calls); $raw = array_values($calls);
            $this->usageMetrics?->recordChat($this->config->userId(), 'groq', $this->config->get('groq_model'), $messages, $streamAnswer, isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : null, isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null, (int)round((microtime(true) - $startedAt) * 1000));
            if ($raw !== []) yield ['type' => 'tool_calls', 'tool_calls' => $this->calls($raw), 'raw' => $raw, 'model' => $this->config->get('groq_model')];
            else yield ['type' => 'finished', 'model' => $this->config->get('groq_model')];
        } catch (\Throwable $e) { yield ['type' => 'error', 'delta' => $this->safeError($e)]; }
        finally {
            if (is_resource($body)) fclose($body);
            elseif (is_object($body) && method_exists($body, 'close')) $body->close();
        }
    }
}
