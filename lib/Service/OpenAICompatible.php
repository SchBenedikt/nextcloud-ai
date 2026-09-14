<?php
declare(strict_types=1);
namespace OCA\EvaAi\Service;

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
        } catch (\Throwable $e) { return ['error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.']; }
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
