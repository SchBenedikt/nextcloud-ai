<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\Ollama;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EmbeddingCacheTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testCacheIsUserIsolatedAndStoresValidatedMetadata(): void {
        $values = [];
        $cache = new class($values) implements \OCP\ICache {
            public function __construct(private array &$values) {
            }
            public function get($key): mixed {
                return $this->values[$key]['value'] ?? null;
            }
            public function set($key, $value, $ttl = 0): bool {
                $this->values[$key] = ['ttl' => $ttl, 'value' => $value];
                return true;
            }
            public function hasKey($key): bool {
                return isset($this->values[$key]);
            }
            public function remove($key): bool {
                unset($this->values[$key]);
                return true;
            }
            public function clear($prefix = ''): bool {
                foreach (array_keys($this->values) as $key) {
                    if ($prefix === '' || str_starts_with($key, $prefix)) {
                        unset($this->values[$key]);
                    }
                }
                return true;
            }
            public static function isAvailable(): bool {
                return true;
            }
        };
        $cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
        $cacheFactory->expects(self::once())->method('createDistributed')->with('eva_ai_embedding_')->willReturn($cache);
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->with('embedding_model')->willReturn('nomic-embed-text');
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');

        $store = new EmbeddingCache($cacheFactory, $config, $this->createMock(LoggerInterface::class));
        $store->putMany([['userId' => 'alice', 'text' => "hello\nworld", 'vector' => [1, 2.5]]]);

        self::assertCount(2, $values);
        $ttlValues = array_map(static fn(array $entry): int => $entry['ttl'], $values);
        self::assertSame([2592000, 2592000], array_values($ttlValues));
        self::assertSame(['vector' => [1.0, 2.5], 'dimension' => 2], $store->get('alice', 'hello world'));
        self::assertNull($store->get('bob', 'hello world'));

        $values[array_key_first($values)]['value']['model'] = 'changed-model';
        self::assertNull($store->get('alice', 'hello world'));
    }

    public function testOllamaBatchReusesCacheAndCoalescesDuplicateMisses(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->with('embedding_model')->willReturn('nomic-embed-text');
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');

        $cache = $this->createMock(EmbeddingCache::class);
        $cache->expects(self::exactly(3))->method('get')->willReturnCallback(
            static fn(string $userId, string $text): ?array => $text === 'cached'
                ? ['vector' => [9.0, 9.0], 'dimension' => 2]
                : null
        );
        $cache->expects(self::once())->method('putMany')->with(self::callback(static function (array $entries): bool {
            return count($entries) === 1
                && $entries[0]['userId'] === 'alice'
                && $entries[0]['text'] === 'new';
        }));

        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['embeddings' => [[1, 2]]], JSON_THROW_ON_ERROR));
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->expects(self::once())->method('post')->with(
            'http://127.0.0.1:11434/api/embed',
            self::callback(static fn(array $options): bool => ($options['json']['input'] ?? []) === ['new'])
        )->willReturn($response);
        $clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientService->expects(self::once())->method('newClient')->willReturn($client);
        $cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
        $ollama = new Ollama(
            $config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $cacheFactory,
            $cache
        );

        [$vectors, $error] = $ollama->embedBatch(['cached', 'new', 'new'], 'alice');
        self::assertNull($error);
        self::assertSame([[9.0, 9.0], [1.0, 2.0], [1.0, 2.0]], $vectors);
        self::assertSame(['cache_hits' => 1, 'cache_misses' => 1, 'ollama_requests' => 1], $ollama->lastEmbeddingStats());
    }
}
