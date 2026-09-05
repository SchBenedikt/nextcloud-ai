<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Issue76OllamaStatusTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testRepeatedStatusCallsUseOneTagsRequest(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['models' => [['name' => 'gemma4:cloud']]]));
        $client->expects(self::once())
            ->method('get')
            ->with('http://127.0.0.1:11434/api/tags', ['timeout' => 20])
            ->willReturn($response);
        $clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientService->expects(self::once())->method('newClient')->willReturn($client);
        $cache = $this->createMock(\OCP\ICache::class);
        $cache->expects(self::exactly(2))->method('get')->with('tags_' . hash('sha256', 'http://127.0.0.1:11434'))->willReturn(null);
        $cache->expects(self::once())->method('set')->with(
            'tags_' . hash('sha256', 'http://127.0.0.1:11434'),
            self::callback(static fn(array $entry): bool => ($entry['ping']['ok'] ?? false) === true),
            30
        );
        $cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
        $cacheFactory->expects(self::once())->method('createDistributed')->with('eva_ai_status_')->willReturn($cache);

        $ollama = new Ollama($config, $clientService, $this->createMock(LoggerInterface::class), $cacheFactory);
        self::assertSame(['name' => 'gemma4:cloud'], $ollama->status()['models'][0]);
        self::assertSame(['name' => 'gemma4:cloud'], $ollama->status()['models'][0]);
    }
}
