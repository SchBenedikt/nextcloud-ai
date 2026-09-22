<?php
declare(strict_types=1);
namespace OCA\EvaAi\Service;

use JsonException;
use OCP\Http\Client\IClientService;

/** Adapter for any provider exposing the OpenAI chat-completions contract. */
class OpenAICompatible {
    public function __construct(private AppConfig $config, private IClientService $clients, private ProviderCredentials $credentials, private ?UsageMetrics $usage = null) {}
    private function provider(): string { return (string)$this->config->get('chat_provider'); }
    private function profile(): array {
        $profile = $this->config->providerProfile($this->provider());
        return is_array($profile) ? $profile : [];
    }
    private function baseUrl(): string {
        $profile = $this->profile();
        return rtrim((string)($profile['url'] ?? $this->config->get('custom_provider_url')), '/');
    }
    private function model(): string {
        $profile = $this->profile();
        return trim((string)($profile['model'] ?? $this->config->get('custom_provider_model')));
    }
    private function endpoint(): string { return $this->baseUrl() . '/chat/completions'; }
    public function check(): array {
        try {
            $base = $this->baseUrl();
            $response = $this->clients->newClient()->get($base . '/models', ['headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $this->provider())], 'timeout' => 15, 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['ok' => false, 'error' => 'Provider returned HTTP ' . $status . '.'];
            $data = json_decode((string)$response->getBody(), true);
            $models = array_values(array_filter(array_map(static fn($row) => (string)($row['id'] ?? ''), is_array($data['data'] ?? null) ? $data['data'] : [])));
            return ['ok' => true, 'models' => $models, 'model' => $this->model()];
        } catch (\Throwable $e) { return ['ok' => false, 'models' => [], 'error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection failed. Check endpoint and key.']; }
    }
    public function chat(array $messages, array $tools = [], int $timeout = 120): array {
        $id = $this->provider();
        try {
            $payload = ['model' => $this->model(), 'messages' => $this->normalizeMessages($messages), 'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))), 'stream' => false];
            if ($tools !== []) $payload['tools'] = $tools;
            $response = $this->clients->newClient()->post($this->endpoint(), ['headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $id), 'Content-Type' => 'application/json'], 'json' => $payload, 'timeout' => max(1, min(300, $timeout)), 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['error' => 'Provider request failed (HTTP ' . $status . ').'];
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $message = $data['choices'][0]['message'] ?? [];
            $calls = [];
            foreach (($message['tool_calls'] ?? []) as $call) { $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true); if (is_array($args)) $calls[] = ['name' => (string)($call['function']['name'] ?? ''), 'arguments' => $args]; }
            $answer = (string)($message['content'] ?? '');
            $this->usage?->recordChat($this->config->userId(), $id, $this->model(), $messages, $answer, null, null, 0);
            return ['answer' => $answer, 'model' => $this->model(), 'tool_calls' => $calls, 'raw_tool_calls' => $message['tool_calls'] ?? []];
        } catch (JsonException $e) {
            return ['error' => 'Provider returned invalid JSON: ' . $e->getMessage()];
        } catch (\Throwable $e) { return ['error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.']; }
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
            $response = $this->clients->newClient()->post($this->baseUrl() . '/images/generations', [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider), 'Content-Type' => 'application/json'],
                'json' => ['model' => $model, 'prompt' => $prompt, 'n' => max(1, min(4, $count)), 'size' => (string)($profile['image_size'] ?? '1024x1024'), 'response_format' => 'b64_json'],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Image provider returned HTTP ' . $status . '. Check the configured model and API key.');
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
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
                    $download = $this->clients->newClient()->get($url, ['timeout' => 60, 'http_errors' => false, 'allow_redirects' => false]);
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
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Image provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /** Transcribe or translate one bounded audio/video payload. */
    public function transcribeAudio(string $bytes, string $filename, string $mime, bool $translate = false, bool $subtitles = false, int $timeout = 180): string {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an audio transcription endpoint. Configure an OpenAI-compatible audio provider first.');
        }
        $profile = $this->profile();
        $model = trim((string)($profile['audio_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No audio model configured for the selected provider.');
        try {
            $responseFormat = $subtitles ? 'vtt' : 'json';
            $response = $this->clients->newClient()->post($this->baseUrl() . '/audio/' . ($translate ? 'translations' : 'transcriptions'), [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider)],
                'multipart' => [
                    ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
                    ['name' => 'model', 'contents' => $model],
                    ['name' => 'response_format', 'contents' => $responseFormat],
                    ['name' => 'prompt', 'contents' => 'Transcribe faithfully in the same language as the audio. Preserve names and timestamps where available.'],
                ],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
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
            $response = $this->clients->newClient()->post($this->baseUrl() . '/audio/speech', [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider), 'Content-Type' => 'application/json'],
                'json' => ['model' => $model, 'input' => mb_substr($text, 0, 12000), 'voice' => $voice, 'response_format' => 'mp3'],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
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
        return array_map(static function (array $message): array {
            $images = is_array($message['images'] ?? null) ? $message['images'] : [];
            $mimes = is_array($message['image_mimes'] ?? null) ? $message['image_mimes'] : [];
            unset($message['images'], $message['image_mimes']);
            if ($images === []) {
                return $message;
            }
            $parts = [['type' => 'text', 'text' => (string)($message['content'] ?? '')]];
            foreach ($images as $index => $base64) {
                if (!is_string($base64) || $base64 === '') continue;
                $mime = is_string($mimes[$index] ?? null) ? $mimes[$index] : 'image/jpeg';
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64]];
            }
            $message['content'] = $parts;
            return $message;
        }, $messages);
    }
}
