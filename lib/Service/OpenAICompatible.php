<?php
declare(strict_types=1);
namespace OCA\EvaAi\Service;

use JsonException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/** Adapter for any provider exposing the OpenAI chat-completions contract. */
class OpenAICompatible {
    public function __construct(private AppConfig $config, private IClientService $clients, private ProviderCredentials $credentials, private ?UsageMetrics $usage = null, private ?LoggerInterface $logger = null, private ?ProviderEndpointPolicy $endpointPolicy = null, private ?TraceContext $traceContext = null) {}
    private function client(): \OCP\Http\Client\IClient {
        return $this->clients->newClient(($this->endpointPolicy ?? $this->config->providerEndpointPolicy())->handler());
    }
    private function provider(): string { return (string)$this->config->get('chat_provider'); }
    private function profile(): array {
        $profile = $this->config->providerProfile($this->provider());
        return is_array($profile) ? $profile : [];
    }
    private function baseUrl(): string {
        $profile = $this->profile();
        $url = rtrim((string)($profile['url'] ?? $this->config->get('custom_provider_url')), '/');
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProviderException('Provider base URL must not contain credentials, query parameters or fragments.');
        }
        return $url;
    }
    private function model(): string {
        $profile = $this->profile();
        return trim((string)($profile['model'] ?? $this->config->get('custom_provider_model')));
    }
    private function endpoint(): string { return $this->baseUrl() . '/chat/completions'; }
    /** Local compatible servers can explicitly disable bearer authentication. */
    private function headers(): array {
        if (($this->profile()['auth'] ?? 'api_key') === 'none') return [];
        return ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $this->provider())];
    }
    private function traceHeaders(): array { return $this->traceContext?->outboundHeaders() ?? []; }
    public function check(): array {
        try {
            $base = $this->baseUrl();
            $response = $this->client()->get($base . '/models', ['headers' => $this->headers(), 'timeout' => 15, 'allow_redirects' => false, 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['ok' => false, 'error' => 'Provider returned HTTP ' . $status . '.'];
            $data = json_decode((string)$response->getBody(), true);
            $models = array_values(array_filter(array_map(static fn($row) => (string)($row['id'] ?? ''), is_array($data['data'] ?? null) ? $data['data'] : [])));
            return ['ok' => true, 'models' => $models, 'model' => $this->model()];
        } catch (\Throwable $e) { $this->logger?->warning('eva_ai: OpenAI-compatible provider check failed', ['provider' => $this->provider(), 'exception' => $e->getMessage()]); return ['ok' => false, 'models' => [], 'error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection failed. Check endpoint and key.']; }
    }
    public function chat(array $messages, array $tools = [], int $timeout = 120): array {
        $id = $this->provider();
        $startedAt = hrtime(true);
        try {
            $payload = ['model' => $this->model(), 'messages' => $this->normalizeMessages($messages), 'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))), 'stream' => false];
            if ($tools !== []) $payload['tools'] = $tools;
            $response = $this->client()->post($this->endpoint(), ['headers' => $this->headers() + ['Content-Type' => 'application/json'] + $this->traceHeaders(), 'json' => $payload, 'timeout' => max(1, min(300, $timeout)), 'allow_redirects' => false, 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['error' => 'Provider request failed (HTTP ' . $status . ').'];
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $message = $data['choices'][0]['message'] ?? [];
            $calls = [];
            foreach (($message['tool_calls'] ?? []) as $call) { $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true); if (is_array($args)) $calls[] = ['name' => (string)($call['function']['name'] ?? ''), 'arguments' => $args]; }
            $answer = (string)($message['content'] ?? '');
            $durationMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
            $this->usage?->recordChat($this->config->userId(), $id, $this->model(), $messages, $answer, null, null, $durationMs);
            return ['answer' => $answer, 'model' => $this->model(), 'tool_calls' => $calls, 'raw_tool_calls' => $message['tool_calls'] ?? []];
        } catch (JsonException $e) {
            return ['error' => 'Provider returned invalid JSON: ' . $e->getMessage()];
        } catch (\Throwable $e) { $this->logger?->warning('eva_ai: OpenAI-compatible chat failed', ['provider' => $id, 'model' => $this->model(), 'exception' => $e->getMessage()]); return ['error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.']; }
    }

    /**
     * Stream OpenAI-compatible chat-completions SSE events while keeping
     * function arguments buffered until the provider confirms completion.
     * @return \Generator<array{type:string,delta?:string,tool_calls?:array,raw?:array,model?:string}>
     */
    public function chatStream(array $messages, array $tools = [], int $timeout = 120): \Generator {
        $provider = $this->provider();
        $model = $this->model();
        $startedAt = microtime(true);
        $body = null;
        $answer = '';
        $usage = [];
        $calls = [];
        $complete = false;
        $lastProgressAt = $startedAt;
        try {
            $payload = [
                'model' => $model,
                'messages' => $this->normalizeMessages($messages),
                'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))),
                'stream' => true,
            ];
            if ($tools !== []) $payload['tools'] = $tools;
            $response = $this->client()->post($this->endpoint(), [
                'headers' => $this->headers() + ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + $this->traceHeaders(),
                'json' => $payload,
                'timeout' => max(1, min(300, $timeout)),
                'read_timeout' => 5,
                'stream' => true,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                yield ['type' => 'error', 'delta' => 'Provider request failed (HTTP ' . $status . ').'];
                return;
            }

            $body = $response->getBody();
            $buffer = '';
            $streamBytes = 0;
            while (is_resource($body) ? !feof($body) : !$body->eof()) {
                if (function_exists('connection_aborted') && connection_aborted() > 0) return;
                $chunk = is_resource($body) ? fread($body, 8192) : $body->read(8192);
                if ($chunk === '') {
                    usleep(10000);
                    continue;
                }
                $streamBytes += strlen($chunk);
                if ($streamBytes > 16_777_216) throw new ProviderException('Provider stream exceeded the response size limit.');
                $buffer .= $chunk;
                if (strlen($buffer) > 2_097_152 && !str_contains($buffer, "\n")) {
                    throw new ProviderException('Provider stream event exceeded the size limit.');
                }

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                    if (strlen($line) > 2_097_152) throw new ProviderException('Provider stream event exceeded the size limit.');
                    if (!str_starts_with($line, 'data:')) continue;
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        $complete = true;
                        break 2;
                    }
                    if ($data === '') continue;
                    $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($event['error'])) throw new ProviderException('Provider stream reported an error.');
                    if (is_array($event['usage'] ?? null)) $usage = $event['usage'];

                    $choice = $event['choices'][0] ?? [];
                    $finishReason = (string)($choice['finish_reason'] ?? '');
                    if (in_array($finishReason, ['length', 'content_filter'], true)) {
                        throw new ProviderException('Provider could not complete the response; no tool calls were released.');
                    }
                    if (in_array($finishReason, ['stop', 'tool_calls', 'function_call'], true)) $complete = true;

                    $delta = $choice['delta'] ?? [];
                    $content = $delta['content'] ?? '';
                    if (is_string($content) && $content !== '') {
                        $answer .= $content;
                        if (strlen($answer) > 16_777_216) throw new ProviderException('Provider response exceeded the size limit.');
                        yield ['type' => 'content', 'delta' => $content];
                    }
                    foreach ($delta['tool_calls'] ?? [] as $call) {
                        $index = (int)($call['index'] ?? 0);
                        if ($index < 0 || $index > 31) throw new ProviderException('Provider returned too many tool calls.');
                        $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                        if (isset($call['id']) && $call['id'] !== '') $calls[$index]['id'] = (string)$call['id'];
                        if (isset($call['type']) && $call['type'] !== '') $calls[$index]['type'] = (string)$call['type'];
                        $function = $call['function'] ?? [];
                        if (isset($function['name']) && $function['name'] !== '') $calls[$index]['function']['name'] .= (string)$function['name'];
                        if (isset($function['arguments']) && $function['arguments'] !== '') {
                            $calls[$index]['function']['arguments'] .= (string)$function['arguments'];
                            if (strlen($calls[$index]['function']['arguments']) > 2_097_152) throw new ProviderException('Provider tool arguments exceed the size limit.');
                        }
                    }
                }
                if (microtime(true) - $lastProgressAt >= 2.0) {
                    $lastProgressAt = microtime(true);
                    yield ['type' => 'progress'];
                }
            }

            if (!$complete) throw new ProviderException('Provider stream ended before completion; no tool calls were released.');
            ksort($calls);
            $rawToolCalls = array_values($calls);
            $normalized = [];
            foreach ($rawToolCalls as &$call) {
                if (trim($call['id']) === '') $call['id'] = 'call_' . bin2hex(random_bytes(8));
                if (trim($call['function']['name']) === '') throw new ProviderException('Provider returned an incomplete tool call.');
                $arguments = json_decode($call['function']['arguments'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($arguments)) throw new ProviderException('Provider returned invalid tool-call arguments.');
                $normalized[] = ['name' => $call['function']['name'], 'arguments' => $arguments];
            }
            unset($call);

            $this->usage?->recordChat(
                $this->config->userId(),
                $provider,
                $model,
                $messages,
                $answer,
                isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : null,
                isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null,
                (int)round((microtime(true) - $startedAt) * 1000),
            );
            if ($rawToolCalls !== []) {
                yield ['type' => 'tool_calls', 'tool_calls' => $normalized, 'raw' => $rawToolCalls, 'model' => $model];
            } else {
                yield ['type' => 'finished', 'model' => $model];
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('eva_ai: OpenAI-compatible streaming chat failed', [
                'provider' => $provider,
                'model' => $model,
                'exception' => $e->getMessage(),
            ]);
            yield ['type' => 'error', 'delta' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.'];
        } finally {
            if (is_resource($body)) fclose($body);
            elseif (is_object($body) && method_exists($body, 'close')) $body->close();
        }
    }

    /**
     * Request images from an OpenAI-compatible /images/generations endpoint.
     * Returns decoded bytes so callers can keep the result inside Nextcloud.
     *
     * @return list<array{bytes:string,mime:string}>
     */
    public function generateImages(string $prompt, int $count = 1, int $timeout = 120): array {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an image-generation endpoint. Configure an OpenAI-compatible image provider first.');
        }
        $profile = $this->profile();
        $model = trim((string)($profile['image_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No image model configured for the selected provider.');
        try {
            $response = $this->client()->post($this->baseUrl() . '/images/generations', [
                'headers' => $this->headers() + ['Content-Type' => 'application/json'] + $this->traceHeaders(),
                'json' => ['model' => $model, 'prompt' => $prompt, 'n' => max(1, min(4, $count)), 'size' => (string)($profile['image_size'] ?? '1024x1024'), 'response_format' => 'b64_json'],
                'timeout' => max(1, min(300, $timeout)), 'allow_redirects' => false, 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Image provider returned HTTP ' . $status . '. Check the configured model and API key.');
            return $this->decodeImageResponse((string)$response->getBody());
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Image provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /**
     * Edit one or more bounded images through an OpenAI-compatible image API.
     * The caller must verify Nextcloud ownership before passing file bytes.
     *
     * @param list<array{bytes:string,mime:string}> $images
     * @return list<array{bytes:string,mime:string}>
     */
    public function editImages(array $images, string $prompt, int $timeout = 180): array {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an image-editing endpoint. Configure an OpenAI-compatible image provider first.');
        }
        if ($images === [] || count($images) > 4) throw new ProviderException('Image editing accepts one to four input images.');
        $prompt = trim($prompt);
        if ($prompt === '' || mb_strlen($prompt) > 32000) throw new ProviderException('The image-editing prompt must contain 1-32000 characters.');
        $profile = $this->profile();
        $model = trim((string)($profile['image_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No image model configured for the selected provider.');

        $multipart = [
            ['name' => 'model', 'contents' => $model],
            ['name' => 'prompt', 'contents' => $prompt],
        ];
        $totalBytes = 0;
        foreach ($images as $index => $image) {
            $bytes = $image['bytes'] ?? '';
            $mime = strtolower((string)($image['mime'] ?? ''));
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 6_000_000
                || $totalBytes + strlen($bytes) > 12_000_000
                || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new ProviderException('An input image is invalid or exceeds the safe size limit.');
            }
            $totalBytes += strlen($bytes);
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $multipart[] = ['name' => 'image[]', 'contents' => $bytes, 'filename' => 'eva-input-' . ($index + 1) . '.' . $extension];
        }

        try {
            $response = $this->client()->post($this->baseUrl() . '/images/edits', [
                'headers' => $this->headers() + $this->traceHeaders(),
                'multipart' => $multipart,
                'timeout' => max(1, min(300, $timeout)), 'allow_redirects' => false, 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Image provider returned HTTP ' . $status . '. Check that the selected model supports image editing.');
            return $this->decodeImageResponse((string)$response->getBody());
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Image provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /** @return list<array{bytes:string,mime:string}> */
    private function decodeImageResponse(string $body): array {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach (($data['data'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $bytes = null;
            $mime = 'image/png';
            if (is_string($item['b64_json'] ?? null)) {
                $bytes = base64_decode($item['b64_json'], true);
            } elseif (is_string($item['url'] ?? null)) {
                $url = $item['url'];
                if (!preg_match('~^https://~i', $url)) throw new ProviderException('Image provider returned an unsafe image URL.');
                $download = $this->client()->get($url, ['timeout' => 60, 'http_errors' => false, 'allow_redirects' => false]);
                if ($download->getStatusCode() < 200 || $download->getStatusCode() >= 300) throw new ProviderException('Generated image could not be downloaded from the provider.');
                $bytes = (string)$download->getBody();
                $mime = (string)($download->getHeader('content-type') ?: 'image/png');
            }
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 15_000_000) continue;
            if (function_exists('getimagesizefromstring') && @getimagesizefromstring($bytes) === false) continue;
            $out[] = ['bytes' => $bytes, 'mime' => str_contains($mime, '/') ? $mime : 'image/png'];
        }
        if ($out === []) throw new ProviderException('Image provider returned no usable images.');
        return $out;
    }

    /** Transcribe or translate one bounded audio/video payload. */
    public function transcribeAudio(string $bytes, string $filename, string $mime, bool $translate = false, bool $subtitles = false, int $timeout = 180, ?string $language = null): string {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an audio transcription endpoint. Configure an OpenAI-compatible audio provider first.');
        }
        $profile = $this->profile();
        $model = trim((string)($profile['audio_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No audio model configured for the selected provider.');
        try {
            $responseFormat = $subtitles ? 'vtt' : 'json';
            $multipart = [
                ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
                ['name' => 'model', 'contents' => $model],
                ['name' => 'response_format', 'contents' => $responseFormat],
                ['name' => 'prompt', 'contents' => 'Transcribe faithfully in the same language as the audio. Preserve names and timestamps where available.'],
            ];
            if (is_string($language) && preg_match('/^[a-z]{2}$/', $language) === 1) {
                $multipart[] = ['name' => 'language', 'contents' => $language];
            }
            $response = $this->client()->post($this->baseUrl() . '/audio/' . ($translate ? 'translations' : 'transcriptions'), [
                'headers' => $this->headers() + $this->traceHeaders(),
                'multipart' => $multipart,
                'timeout' => max(1, min(300, $timeout)), 'allow_redirects' => false, 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Audio provider returned HTTP ' . $status . '. Check the configured model and API key.');
            $body = (string)$response->getBody();
            if ($subtitles) return trim($body);
            $data = json_decode($body, true);
            $text = is_array($data) ? (string)($data['text'] ?? '') : '';
            if ($text === '' && $body !== '' && !str_starts_with(ltrim($body), '{')) $text = $body;
            if (trim($text) === '') throw new ProviderException('Audio provider returned no transcription.');
            return trim($text);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Audio provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /** Generate bounded speech bytes through an OpenAI-compatible TTS endpoint. */
    public function synthesizeSpeech(string $text, int $timeout = 180): array {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') throw new ProviderException('The selected provider does not expose a speech endpoint. Configure an OpenAI-compatible audio provider first.');
        $profile = $this->profile();
        $model = trim((string)($profile['tts_model'] ?? $this->model()));
        $voice = trim((string)($profile['tts_voice'] ?? 'alloy'));
        if ($model === '') throw new ProviderException('No speech model configured for the selected provider.');
        try {
            $response = $this->client()->post($this->baseUrl() . '/audio/speech', [
                'headers' => $this->headers() + ['Content-Type' => 'application/json'] + $this->traceHeaders(),
                'json' => ['model' => $model, 'input' => mb_substr($text, 0, 12000), 'voice' => $voice, 'response_format' => 'mp3'],
                'timeout' => max(1, min(300, $timeout)), 'allow_redirects' => false, 'http_errors' => false,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) throw new ProviderException('Speech provider returned HTTP ' . $response->getStatusCode() . '. Check the configured model and API key.');
            $bytes = (string)$response->getBody();
            if ($bytes === '' || strlen($bytes) > 25_000_000) throw new ProviderException('Speech provider returned an empty or oversized audio file.');
            return ['bytes' => $bytes, 'mime' => 'audio/mpeg'];
        } catch (ProviderException $e) { throw $e; }
        catch (\Throwable $e) { throw new ProviderException('Speech provider connection or response failed. Check endpoint, key and model.'); }
    }

    /** Convert EVA's provider-neutral image message to OpenAI vision syntax. */
    private function normalizeMessages(array $messages): array {
        $normalized = [];
        $pendingToolIds = [];
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $images = is_array($message['images'] ?? null) ? $message['images'] : [];
            $mimes = is_array($message['image_mimes'] ?? null) ? $message['image_mimes'] : [];
            unset($message['images'], $message['image_mimes']);

            if ($images !== []) {
                $parts = [['type' => 'text', 'text' => (string)($message['content'] ?? '')]];
                foreach ($images as $imageIndex => $base64) {
                    if (!is_string($base64) || $base64 === '') continue;
                    $mime = is_string($mimes[$imageIndex] ?? null) ? $mimes[$imageIndex] : 'image/jpeg';
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64]];
                }
                $message['content'] = $parts;
            }

            if (($message['role'] ?? '') === 'assistant' && is_array($message['tool_calls'] ?? null)) {
                $pendingToolIds = [];
                $calls = [];
                foreach ($message['tool_calls'] as $callIndex => $call) {
                    if (!is_array($call)) continue;
                    $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
                    $name = trim((string)($function['name'] ?? ''));
                    if ($name === '') continue;
                    $id = trim((string)($call['id'] ?? ''));
                    if ($id === '') $id = 'call_' . $index . '_' . $callIndex;
                    $arguments = $function['arguments'] ?? [];
                    if (is_string($arguments)) {
                        $decoded = json_decode($arguments, true);
                        $arguments = is_array($decoded) ? $decoded : [];
                    } elseif (is_object($arguments)) {
                        $arguments = get_object_vars($arguments);
                    }
                    if (!is_array($arguments)) $arguments = [];
                    $encodedArguments = json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $calls[] = [
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $encodedArguments],
                    ];
                    $pendingToolIds[] = $id;
                }
                if ($calls !== []) $message['tool_calls'] = $calls;
                else unset($message['tool_calls']);
            } elseif (($message['role'] ?? '') === 'tool') {
                $toolCallId = array_shift($pendingToolIds);
                if ($toolCallId === null) continue;
                $message['tool_call_id'] = $toolCallId;
                unset($message['name']);
            }

            $normalized[] = $message;
        }
        return $normalized;
    }
}
