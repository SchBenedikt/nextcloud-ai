<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Bounded, content-addressed cache for Ollama embedding vectors.
 *
 * Entries are isolated by user and invalidated by the cache schema, provider
 * endpoint, embedding model, or vector dimension. The distributed cache's TTL
 * provides bounded retention without storing document content in the cache
 * value itself.
 */
class EmbeddingCache {
    private const SCHEMA_VERSION = 1;
    private const TTL = 2592000; // 30 days
    private const PREFIX = 'eva_ai_embedding_';

    private ?ICache $store = null;
    private bool $initialized = false;
    /** Skip repeated slow cache failures briefly; embeddings remain correct without this cache. */
    private int $unavailableUntil = 0;
    /**
     * Process-local memo of the per-user+model dimension guard. With it, a
     * batch of N get() calls issues N cache reads instead of 2N; misses fall
     * through to the stored value, so correctness is unchanged (the vector
     * entry itself still carries and validates the dimension).
     * @var array<string,int|null>
     */
    private array $dimensionMemo = [];

    /** Proven generation entries never read legacy/fallback cache records. */
    public function getForGeneration(\OCA\EvaAi\Vector\VectorGeneration $generation, string $text): ?array {
        try {
            $entry = $this->store()->get($this->generationKey($generation, $text));
            if (!is_array($entry) || ($entry['schema'] ?? null) !== 2
                || ($entry['identity'] ?? null) !== $this->generationIdentity($generation)
                || !is_array($entry['vector'] ?? null)) return null;
            $generation->validateVector($entry['vector']);
            return array_map('floatval', $entry['vector']);
        } catch (\Throwable $e) { $this->markUnavailable($e); return null; }
    }

    /** Cache availability cannot turn a successful generation batch into a failure. */
    public function putForGeneration(\OCA\EvaAi\Vector\VectorGeneration $generation, array $texts, array $vectors): void {
        if (!array_is_list($texts) || !array_is_list($vectors) || count($texts) !== count($vectors)) return;
        try {
            foreach ($texts as $i => $text) {
                if (!is_string($text) || trim($text) === '' || strlen($text) > 40000) return;
                $generation->validateVector($vectors[$i]);
            }
            $identity = $this->generationIdentity($generation);
            foreach ($texts as $i => $text) {
                $this->store()->set($this->generationKey($generation, $text),
                    ['schema'=>2, 'identity'=>$identity, 'vector'=>array_map('floatval', $vectors[$i])], self::TTL);
            }
        } catch (\Throwable $e) { $this->markUnavailable($e); /* Valid vectors remain usable without caching. */ }
    }

    private function generationIdentity(\OCA\EvaAi\Vector\VectorGeneration $generation): string {
        return hash('sha256', json_encode([$generation->embeddingProvider, $generation->profileId,
            $generation->endpointHash, $generation->embeddingModel, $generation->dimension, $generation->distance()], JSON_THROW_ON_ERROR));
    }

    private function generationKey(\OCA\EvaAi\Vector\VectorGeneration $generation, string $text): string {
        return $this->userPrefix($generation->userId) . 'pinned_v2_' . $this->generationIdentity($generation) . '_' . hash('sha256', $text);
    }

    public function __construct(
        private ICacheFactory $cacheFactory,
        private AppConfig $config,
        private LoggerInterface $logger
    ) {
    }

    /** @return array{vector:array,dimension:int}|null */
    public function get(string $userId, string $text): ?array {
        $key = $this->key($userId, $text);
        try {
            $store = $this->store();
            $entry = $store->get($key);
            $memoKey = $this->dimensionKey($userId);
            if (!array_key_exists($memoKey, $this->dimensionMemo)) {
                $this->dimensionMemo[$memoKey] = $store->get($this->dimensionKey($userId));
                $this->dimensionMemo[$memoKey] = is_int($this->dimensionMemo[$memoKey]) || is_numeric($this->dimensionMemo[$memoKey])
                    ? (int)$this->dimensionMemo[$memoKey]
                    : null;
            }
            $expectedDimension = $this->dimensionMemo[$memoKey];
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            return null;
        }
        if (!is_array($entry)) {
            return null;
        }

        $vector = $entry['vector'] ?? null;
        $dimension = (int)($entry['dimension'] ?? 0);
        if ((int)($entry['schema'] ?? 0) !== self::SCHEMA_VERSION
            || (string)($entry['model'] ?? '') !== $this->model()
            || (string)($entry['endpoint'] ?? '') !== $this->endpoint()
            || !is_array($vector)
            || $dimension < 1
            || count($vector) !== $dimension
            || ($expectedDimension !== null && (int)$expectedDimension !== $dimension)
            || !$this->isNumericVector($vector)) {
            // Incompatible entries are ignored rather than guessed at. The
            // next successful embedding overwrites this content-addressed key.
            return null;
        }
        return ['vector' => array_map('floatval', $vector), 'dimension' => $dimension];
    }

    /** @param array<int,array{userId:string,text:string,vector:array}> $entries */
    public function putMany(array $entries): void {
        if ($entries === []) {
            return;
        }
        try {
            $store = $this->store();
            $dimension = null;
            foreach ($entries as $entry) {
                $vector = $entry['vector'] ?? null;
                if (!$this->isNumericVector($vector)) {
                    return;
                }
                $dimension ??= count($vector);
                if (count($vector) !== $dimension) {
                    return;
                }
            }
            foreach ($entries as $entry) {
                $key = $this->key($entry['userId'], $entry['text']);
                $store->set($key, [
                    'schema' => self::SCHEMA_VERSION,
                    'model' => $this->model(),
                    'endpoint' => $this->endpoint(),
                    'dimension' => $dimension,
                    'created' => time(),
                    'vector' => array_map('floatval', $entry['vector']),
                ], self::TTL);
            }
            $dimensionKey = $this->dimensionKey($entries[0]['userId']);
            $store->set($dimensionKey, $dimension, self::TTL);
            // A newly published dimension invalidates the read-side memo so
            // the next get() re-reads the guard instead of trusting a stale
            // value from before this write.
            unset($this->dimensionMemo[$dimensionKey]);
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            // Cache contents are disposable; avoid retrying cleanup calls against a failing backend.
            $this->logger->debug('eva_ai embedding cache write skipped', ['exception' => $e]);
        }
    }

    /**
     * Clear all embedding entries. Resetting an index intentionally clears the
     * shared cache as well: entries may be reused safely, but clearing avoids
     * retaining vectors after an explicit data reset and bounds stale storage.
     */
    public function clear(): void {
        try {
            $this->store()->clear();
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            $this->logger->debug('eva_ai embedding cache clear skipped', ['exception' => $e]);
        }
    }

    public function clearUser(string $userId): void {
        try {
            $this->store()->clear($this->userPrefix($userId));
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            $this->logger->debug('eva_ai user embedding cache clear skipped', ['exception' => $e]);
        }
    }

    public function available(): bool {
        try {
            $this->store();
            return true;
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            return false;
        }
    }

    private function store(): ICache {
        if (time() < $this->unavailableUntil) {
            throw new \RuntimeException('Distributed cache is in temporary cooldown');
        }
        if (!$this->initialized) {
            $this->initialized = true;
            try {
                $this->store = $this->cacheFactory->createDistributed(self::PREFIX);
            } catch (\Throwable $e) {
                $this->markUnavailable($e);
                throw $e;
            }
        }
        if ($this->store === null) {
            throw new \RuntimeException('No distributed cache available');
        }
        return $this->store;
    }

    private function markUnavailable(\Throwable $error): void {
        if (time() < $this->unavailableUntil) {
            return;
        }
        $this->unavailableUntil = time() + 15;
        $this->store = null;
        $this->initialized = false;
        try {
            $this->logger->debug('eva_ai distributed embedding cache temporarily unavailable', [
                'exception' => get_class($error),
                'retry_after_seconds' => 15,
            ]);
        } catch (\Throwable) {
            // Logging must not affect the uncached embedding path.
        }
    }

    private function key(string $userId, string $text): string {
        // Do not put document text into the cache key. The digest is also
        // stable across repeated chunks while the user namespace prevents
        // cross-user cache reuse and timing/content side channels.
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return $this->userPrefix($userId) . hash('sha256', implode("\n", [
            'v' . self::SCHEMA_VERSION,
            $this->endpoint(),
            $this->model(),
            $normalized,
        ]));
    }

    private function userPrefix(string $userId): string {
        return 'u_' . hash('sha256', $userId) . '_';
    }

    private function dimensionKey(string $userId): string {
        return $this->userPrefix($userId) . 'dimension_' . hash('sha256', $this->endpoint() . "\n" . $this->model());
    }

    private function model(): string {
        return $this->config->get('embedding_model');
    }

    private function endpoint(): string {
        return $this->config->ollamaUrl();
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
}
