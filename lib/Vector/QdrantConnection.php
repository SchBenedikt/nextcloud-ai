<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ProviderEndpointPolicy;
use OCA\EvaAi\Service\ProviderException;
use OCA\EvaAi\Db\VectorTargetRepository;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;

/** Instance-only, explicitly enabled transport. Construction performs no I/O. */
class QdrantConnection {
    private const MAX_BODY = 2097152;
    public function __construct(private IConfig $config, private ICrypto $crypto, private IClientService $clients, private VectorTargetRepository $targets) {}

    public function configure(string $url, ?string $key, bool $noKey, bool $enable): void {
        $url = $this->validateBase($url);
        if ($noKey ? $key !== null : $key === null) throw new VectorStoreException('select_key_or_no_key');
        if ($key !== null && ($key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key))) throw new VectorStoreException('invalid_key');
        $encrypted = $key !== null ? $this->crypto->encrypt($key) : '';
        // Disable first: incomplete updates must not enable an unintended target.
        $this->config->setAppValue(AppConfig::APP, 'qdrant_enabled', '0');
        $this->targets->save($url, $noKey ? 'none' : 'api_key', $encrypted);
        $this->config->setAppValue(AppConfig::APP, 'qdrant_url', $url);
        $this->config->setAppValue(AppConfig::APP, 'qdrant_api_key_encrypted', $encrypted);
        $this->config->setAppValue(AppConfig::APP, 'qdrant_key_target', hash('sha256', $url));
        $this->config->setAppValue(AppConfig::APP, 'qdrant_auth', $noKey ? 'none' : 'api_key');
        if ($enable) $this->config->setAppValue(AppConfig::APP, 'qdrant_enabled', '1');
    }

    public function disable(): void { $this->config->setAppValue(AppConfig::APP, 'qdrant_enabled', '0'); }

    public function status(): array {
        return ['enabled' => $this->config->getAppValue(AppConfig::APP, 'qdrant_enabled', '0') === '1',
            'configured' => $this->config->getAppValue(AppConfig::APP, 'qdrant_url', '') !== '',
            'key_configured' => $this->config->getAppValue(AppConfig::APP, 'qdrant_api_key_encrypted', '') !== ''];
    }

    public function targetFingerprint(): string {
        if ($this->config->getAppValue(AppConfig::APP, 'qdrant_enabled', '0') !== '1') throw new VectorStoreException('not_enabled');
        $base = $this->validateBase($this->config->getAppValue(AppConfig::APP, 'qdrant_url', ''));
        $id = hash('sha256', $base);
        if (!hash_equals($id, $this->config->getAppValue(AppConfig::APP, 'qdrant_key_target', ''))) throw new VectorStoreException('target_changed');
        if ($this->targets->find($id) === null) {
            $this->targets->save($base, $this->config->getAppValue(AppConfig::APP, 'qdrant_auth', ''), $this->config->getAppValue(AppConfig::APP, 'qdrant_api_key_encrypted', ''));
        }
        return $id;
    }

    public function request(string $method, string $path, ?array $payload = null, bool $allowMissing = false, int $timeout = 30, ?string $expectedTarget = null): ?array {
        if ($this->config->getAppValue(AppConfig::APP, 'qdrant_enabled', '0') !== '1') throw new VectorStoreException('not_enabled');
        $targetId = $expectedTarget ?? $this->targetFingerprint();
        $target = $this->targets->find($targetId);
        if ($target === null) throw new VectorStoreException('target_missing');
        $base = $this->validateBase($target['endpoint']);
        if (!str_starts_with($path, '/') || str_contains($path, '..') || !in_array($method, ['get', 'post', 'put', 'delete'], true)) throw new VectorStoreException('invalid_request');
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if (!hash_equals(hash('sha256', $base), $targetId)) throw new VectorStoreException('target_changed');
        $auth = $target['auth'];
        if ($auth === 'api_key') {
            try { $key = $this->crypto->decrypt($target['encrypted_key']); }
            catch (\Throwable) { throw new VectorStoreException('key_unreadable'); }
            if ($key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) throw new VectorStoreException('invalid_key');
            $headers['api-key'] = $key;
        } elseif ($auth !== 'none') throw new VectorStoreException('invalid_auth');
        $timeout = max(1, min(30, $timeout));
        $options = ['headers' => $headers, 'timeout' => $timeout, 'connect_timeout' => min(5, $timeout), 'read_timeout' => $timeout,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            'progress' => static function ($total, $downloaded): void { if ($downloaded > self::MAX_BODY) throw new VectorStoreException('response_too_large'); }];
        if ($payload !== null) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($body) > 8388608) throw new VectorStoreException('request_too_large');
            $options['body'] = $body;
        }
        $stream = null;
        try {
            $client = $this->clients->newClient((new ProviderEndpointPolicy($this->config, 'vector_private_origins'))->handler());
            $response = $client->$method($base . $path, $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody();
            if ($status === 404 && $allowMissing) return null;
            if ($status < 200 || $status >= 300) throw new VectorStoreException('http_' . $status);
            $body = is_resource($stream) ? stream_get_contents($stream, self::MAX_BODY + 1) : $stream;
            if (!is_string($body) || strlen($body) > self::MAX_BODY) throw new VectorStoreException('invalid_response_size');
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new VectorStoreException('invalid_response');
            return $data;
        } catch (VectorStoreException $e) { throw $e; }
        catch (ProviderException) { throw new VectorStoreException('egress_denied'); }
        catch (\JsonException) { throw new VectorStoreException('invalid_json'); }
        catch (\Throwable) { throw new VectorStoreException('connection_failed'); }
        finally { if (is_resource($stream)) fclose($stream); }
    }

    private function validateBase(string $url): string {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new VectorStoreException('invalid_endpoint');
        // Cleartext is restricted to loopback; remote stores always require TLS.
        $host = strtolower(trim($parts['host'], '[]'));
        if (strtolower($parts['scheme']) !== 'https' && !in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) throw new VectorStoreException('remote_tls_required');
        return $url;
    }
}
