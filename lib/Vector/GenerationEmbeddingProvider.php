<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Service\{AppConfig, EmbeddingCache, ProviderException};
use OCP\Http\Client\IClientService;

/** Exact generation requests: no chat routing, model fallback or legacy vector inference. */
class GenerationEmbeddingProvider {
    private const MAX_BODY = 4194304;

    public function __construct(private AppConfig $config, private IClientService $clients,
        private GenerationEmbeddingConfiguration $configuration, private EmbeddingCache $cache) {}

    /** @param list<string> $texts @return list<list<float>> */
    public function embed(VectorGeneration $generation, array $texts, \Closure $guard, bool $useCache = true): array {
        if (!array_is_list($texts) || $texts === [] || count($texts) > 8 || count($texts) * $generation->dimension > 131072) throw new VectorStoreException('embedding_batch_too_large');
        foreach ($texts as $text) {
            if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || trim($text) === '' || strlen($text) > 40000 || mb_strlen($text) > $generation->chunkSize) throw new VectorStoreException('invalid_embedding_input');
        }
        $guard();
        $context = $this->context($generation);
        $vectors = []; $missing = []; $positions = [];
        foreach ($texts as $index => $text) {
            $cached = $useCache ? $this->cache->getForGeneration($generation, $text) : null;
            if ($cached !== null) { $vectors[$index] = $cached; continue; }
            $hash = hash('sha256', $text);
            $positions[$hash][] = $index;
            $missing[$hash] = $text;
        }
        if ($missing !== []) {
            $input = array_values($missing);
            $payload = ['model' => $generation->embeddingModel, 'input' => $input];
            if ($generation->embeddingProvider === 'ollama') $payload['truncate'] = false;
            else $payload['encoding_format'] = 'float';
            $transferGuard = function () use ($generation, $context, $guard): void {
                $guard();
                if ($this->context($generation) !== $context) throw new VectorStoreException('embedding_profile_changed');
            };
            $data = $this->request($generation, $context, $payload, $transferGuard);
            $received = $this->decode($generation, $data, count($input));
            // Validate the whole batch before publishing any result or cache entry.
            $transferGuard();
            foreach (array_keys($missing) as $slot => $hash) {
                foreach ($positions[$hash] as $index) $vectors[$index] = $received[$slot];
            }
            if ($useCache) $this->cache->putForGeneration($generation, array_values($missing), $received);
        }
        $guard();
        if ($this->context($generation) !== $context) throw new VectorStoreException('embedding_profile_changed');
        ksort($vectors);
        return array_values($vectors);
    }

    /** Capture current owned configuration, then pin the captured URL/auth for the transfer. */
    private function context(VectorGeneration $generation): array {
        if ($this->config->userId() !== $generation->userId) throw new VectorStoreException('embedding_owner_mismatch');
        try { $snapshot = $this->configuration->capture($generation); }
        catch (VectorStoreException $e) { throw $e; }
        catch (\Throwable) { throw new VectorStoreException('embedding_configuration_unavailable'); }
        $url = $snapshot['url']; $auth = $snapshot['auth'];
        $url = rtrim($url, '/'); $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new VectorStoreException('invalid_embedding_endpoint');
        if (!hash_equals($generation->endpointHash, hash('sha256', $url))) throw new VectorStoreException('embedding_profile_changed');
        if (!in_array($auth, ['none','api_key'], true)) throw new VectorStoreException('invalid_embedding_auth');
        return $snapshot;
    }

    private function request(VectorGeneration $generation, array $context, array $payload, \Closure $guard): array {
        $headers = ['Accept'=>'application/json','Content-Type'=>'application/json'];
        $headers += $this->configuration->authorization($context);
        $options = ['headers'=>$headers, 'json'=>$payload, 'timeout'=>30, 'connect_timeout'=>5, 'read_timeout'=>30,
            'allow_redirects'=>false,'http_errors'=>false,'stream'=>true,
            'progress'=>static function ($total,$downloaded): void { if ($downloaded > self::MAX_BODY) throw new VectorStoreException('embedding_response_too_large'); }];
        $stream = null;
        try {
            $client = $this->clients->newClient($this->config->providerEndpointPolicy()->handler($guard));
            $path = $generation->embeddingProvider === 'ollama' ? '/api/embed' : '/embeddings';
            $response = $client->post($context['url'] . $path, $options);
            $stream = $response->getBody();
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) throw new VectorStoreException('embedding_http_' . $response->getStatusCode());
            $body = is_resource($stream) ? stream_get_contents($stream,self::MAX_BODY+1) : $stream;
            if (!is_string($body) || strlen($body)>self::MAX_BODY) throw new VectorStoreException('embedding_response_too_large');
            $data = json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new VectorStoreException('invalid_embedding_response');
            return $data;
        } catch (VectorStoreException $e) { throw $e; }
        catch (ProviderException) { throw new VectorStoreException('embedding_egress_denied'); }
        catch (\JsonException) { throw new VectorStoreException('invalid_embedding_json'); }
        catch (\Throwable) { throw new VectorStoreException('embedding_connection_failed'); }
        finally { if (is_resource($stream)) fclose($stream); }
    }

    private function decode(VectorGeneration $generation, array $data, int $count): array {
        $model = $data['model'] ?? null;
        if (!is_string($model)) throw new VectorStoreException('embedding_model_mismatch');
        $expected = $generation->embeddingModel;
        if ($generation->embeddingProvider === 'ollama') {
            $canonical = static fn(string $name): string => str_contains(basename($name), ':') ? $name : $name . ':latest';
            $model = $canonical($model); $expected = $canonical($expected);
        }
        if (!hash_equals($expected,$model)) throw new VectorStoreException('embedding_model_mismatch');
        if ($generation->embeddingProvider === 'ollama') $vectors = $data['embeddings'] ?? null;
        else {
            $rows = $data['data'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows)!==$count) throw new VectorStoreException('invalid_embedding_response');
            $vectors = array_fill(0,$count,null);
            foreach ($rows as $row) {
                $index = is_array($row) ? ($row['index'] ?? null) : null;
                if (!is_int($index) || $index<0 || $index >= $count || $vectors[$index] !== null) throw new VectorStoreException('invalid_embedding_response');
                $vectors[$index] = $row['embedding'] ?? null;
            }
        }
        if (!is_array($vectors) || !array_is_list($vectors) || count($vectors)!==$count) throw new VectorStoreException('invalid_embedding_response');
        foreach ($vectors as &$vector) {
            try { if (!is_array($vector)) throw new \InvalidArgumentException(); $generation->validateVector($vector); }
            catch (\Throwable) { throw new VectorStoreException('invalid_embedding_vector'); }
            $vector = array_map('floatval',$vector);
        }
        return $vectors;
    }
}
