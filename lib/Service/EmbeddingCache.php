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
            $expectedDimension = $store->get($this->dimensionKey($userId));
        } catch (\Throwable $e) {
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
        $writtenKeys = [];
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
                $writtenKeys[] = $key;
            }
            $dimensionKey = $this->dimensionKey($entries[0]['userId']);
            $store->set($dimensionKey, $dimension, self::TTL);
            $writtenKeys[] = $dimensionKey;
        } catch (\Throwable $e) {
            // A cache outage must never make indexing fail. Remove entries
            // written by this attempt so a failed update cannot advertise a
            // partially published cache generation.
            foreach ($writtenKeys as $key) {
                try {
                    $store?->remove($key);
                } catch (\Throwable $ignored) {
                    // Cache cleanup is best effort and must not mask the error.
                }
            }
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
            $this->logger->debug('eva_ai embedding cache clear skipped', ['exception' => $e]);
        }
    }

    public function clearUser(string $userId): void {
        try {
            $this->store()->clear($this->userPrefix($userId));
        } catch (\Throwable $e) {
            $this->logger->debug('eva_ai user embedding cache clear skipped', ['exception' => $e]);
        }
    }

    public function available(): bool {
        try {
            $this->store();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function store(): ICache {
        if (!$this->initialized) {
            $this->initialized = true;
            $this->store = $this->cacheFactory->createDistributed(self::PREFIX);
        }
        if ($this->store === null) {
            throw new \RuntimeException('No distributed cache available');
        }
        return $this->store;
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
