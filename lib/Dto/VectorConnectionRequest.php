<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

/** Full replacement: never infer authentication or silently retain a key. */
final readonly class VectorConnectionRequest {
    private function __construct(public string $endpoint, public ?string $apiKey, public bool $noKey, public bool $enabled, public ?array $privateOrigins) {}

    public static function fromArray(array $input): self {
        $endpoint = $input['endpoint'] ?? null;
        $auth = $input['auth'] ?? null;
        $key = $input['api_key'] ?? null;
        $enabled = $input['enabled'] ?? null;
        $privateOrigins = $input['private_origins'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '' || strlen($endpoint) > 2048
            || !in_array($auth, ['api_key', 'none'], true) || !is_bool($enabled)
            || ($privateOrigins !== null && (!is_array($privateOrigins) || !array_is_list($privateOrigins) || count($privateOrigins)>100
                || array_filter($privateOrigins,static fn($origin):bool=>!is_string($origin)||strlen($origin)>2048)!==[]))
            || ($auth === 'none' && $key !== null)
            || ($auth === 'api_key' && (!is_string($key) || $key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)))) {
            throw new \InvalidArgumentException('Enter a server URL, select authentication and supply a new API key when required.');
        }
        return new self(trim($endpoint), $key, $auth === 'none', $enabled, $privateOrigins);
    }
}
