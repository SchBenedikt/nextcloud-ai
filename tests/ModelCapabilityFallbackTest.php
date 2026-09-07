<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\Ollama;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for the provider capability layer (Issues #86/#148/#151):
 * - model roles come from provider capabilities, not name heuristics alone;
 * - fallback chains resolve to the first installed, capable candidate;
 * - all-missing configurations fail with a clear user-facing error;
 * - :latest-tagged models match their untagged configured name.
 */
final class ModelCapabilityFallbackTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
        // Clear the process-local status/resolution memo between tests so a
        // cached /api/tags snapshot from a previous case cannot leak in.
        foreach (['statusCache', 'resolutionCache'] as $prop) {
            $ref = new \ReflectionProperty(Ollama::class, $prop);
            $ref->setValue(null, []);
        }
    }

    /** @param array<int,array<string,mixed>> $models */
    private function ollamaWith(array $models, AppConfig $config, ?\OCP\ICache $tagsCache = null): Ollama {
        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['models' => $models], JSON_THROW_ON_ERROR));
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->method('get')->willReturn($response);
        $clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
        if ($tagsCache !== null) {
            $cacheFactory->method('createDistributed')->willReturn($tagsCache);
        } else {
            $cacheFactory->method('createDistributed')->willReturn($this->createMock(\OCP\ICache::class));
        }
        return new Ollama(
            $config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $cacheFactory,
            $this->createMock(EmbeddingCache::class)
        );
    }

    private function configWith(array $values): AppConfig {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): string => (string)($values[$key] ?? '')
        );
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');
        return $config;
    }

    public function testChatRoleComesFromDeclaredCapabilitiesNotNames(): void {
        // Names alone would misclassify this multimodal model as embedding
        // because of the "embed" token in its name; the declared capabilities
        // must win (Issue #148).
        $models = [
            ['name' => 'super-embed-vision', 'capabilities' => ['completion', 'vision', 'tools']],
        ];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $caps = $ollama->capabilities();
        self::assertTrue($caps['available']);
        self::assertSame(['chat'], $caps['models']['super-embed-vision']['roles']);
        self::assertFalse($caps['models']['super-embed-vision']['heuristic']);
    }

    public function testEmbeddingRoleComesFromDeclaredCapabilities(): void {
        $models = [
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding']],
        ];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $caps = $ollama->capabilities();
        self::assertSame(['embedding'], $caps['models']['nomic-embed-text:latest']['roles']);
    }

    public function testNameHeuristicOnlyUsedWhenCapabilitiesAreAbsent(): void {
        // Older Ollama without the capabilities field: the heuristic still
        // separates embedding families from chat models (Issue #148).
        $models = [
            ['name' => 'llama3', 'details' => ['family' => 'llama']],
            ['name' => 'bge-m3', 'details' => ['family' => 'bge']],
        ];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $caps = $ollama->capabilities();
        self::assertTrue($caps['models']['llama3']['heuristic']);
        self::assertSame(['chat'], $caps['models']['llama3']['roles']);
        self::assertSame(['embedding'], $caps['models']['bge-m3']['roles']);
    }

    public function testFallbackResolvesToFirstInstalledCapableCandidate(): void {
        // chat_model is not installed at all; the fallback list contains a
        // chat-capable model that is installed and must win (Issue #86).
        $models = [
            ['name' => 'gemma4:cloud', 'capabilities' => ['completion', 'tools']],
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding']],
        ];
        $config = $this->configWith([
            'chat_model' => 'missing-model',
            'chat_model_fallback' => 'gemma4:cloud',
        ]);
        $ollama = $this->ollamaWith($models, $config);
        $resolved = $ollama->resolveModel('chat', 'missing-model', 'gemma4:cloud');
        self::assertNull($resolved['error']);
        self::assertSame('gemma4:cloud', $resolved['model']);
        self::assertTrue($resolved['usedFallback']);
    }

    public function testFallbackSkipsCapabilityMismatchedInstalledCandidates(): void {
        // The only installed fallback is an embedding model: it must NOT be
        // used for chat, and the resolution reports the mismatch clearly.
        $models = [
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding']],
        ];
        $config = $this->configWith([
            'chat_model' => 'not-installed',
            'chat_model_fallback' => 'nomic-embed-text:latest',
        ]);
        $ollama = $this->ollamaWith($models, $config);
        $resolved = $ollama->resolveModel('chat', 'not-installed', 'nomic-embed-text:latest');
        self::assertNull($resolved['model']);
        self::assertNotNull($resolved['error']);
        self::assertStringContainsString('None of the configured chat models', (string)$resolved['error']);
        self::assertStringContainsString('nomic-embed-text:latest', (string)$resolved['error']);
    }

    public function testLatestSuffixMatchesUntaggedConfiguredName(): void {
        // Ollama lists tags with an explicit ':latest' suffix while the user
        // configures the short name. A fallback candidate must still match.
        $models = [
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding']],
        ];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $resolved = $ollama->resolveModel('embedding', 'not-installed', 'nomic-embed-text');
        self::assertSame('nomic-embed-text:latest', $resolved['model']);
        self::assertTrue($resolved['usedFallback']);
    }

    public function testPrimaryConfiguredWithoutFallbackIsUsedAsIs(): void {
        // No fallback chain: the configured model must be used directly even
        // when it is only listed with an explicit ':latest' suffix - the hot
        // path stays request-free and Ollama itself resolves the tag.
        $models = [
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding']],
        ];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $resolved = $ollama->resolveModel('embedding', 'nomic-embed-text', '');
        self::assertSame('nomic-embed-text', $resolved['model']);
        self::assertFalse($resolved['usedFallback']);
    }

    public function testNoFallbackConfiguredKeepsPrimaryWithoutTagsRequest(): void {
        // The hot path must not add a /api/tags round trip when no fallback
        // chain exists (default installs). The mock client would fail the
        // test if a GET were issued, because it only stubs POST.
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->expects(self::never())->method('get');
        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['message' => ['content' => 'hi']], JSON_THROW_ON_ERROR));
        $client->expects(self::once())->method('post')->willReturn($response);
        $clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(\OCP\ICache::class));
        $config = $this->configWith(['chat_model' => 'llama3']);
        $ollama = new Ollama(
            $config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $cacheFactory,
            $this->createMock(EmbeddingCache::class)
        );
        $result = $ollama->chat([['role' => 'user', 'content' => 'hi']]);
        self::assertSame('hi', $result['answer']);
        self::assertSame('llama3', $result['model']);
    }

    public function testStatusExposesVersionedSnapshotMetadata(): void {
        $models = [['name' => 'gemma4:cloud', 'capabilities' => ['completion']]];
        $ollama = $this->ollamaWith($models, $this->configWith([]));
        $status = $ollama->status();
        self::assertSame(1, $status['meta']['version']);
        self::assertFalse($status['meta']['fromCache']);
        self::assertIsInt($status['meta']['checkedAt']);
        self::assertIsInt($status['meta']['latencyMs']);
        // Legacy shape stays stable for older consumers.
        self::assertSame('gemma4:cloud', $status['models'][0]['name']);
    }
}
