<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\Ollama;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A single unembeddable document must never take an indexing pass down with it.
 *
 * The model server aborts a batch it cannot serve, so the service halves the
 * batch until the offending input is alone, then reports that one position as
 * empty. Everything else in the batch is still embedded and indexed; the caller
 * skips just the failed document.
 */
final class OllamaEmbeddingResilienceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Build an Ollama service whose HTTP layer is answered by $server.
     *
     * @param callable(array):array{body?:string} $server
     */
    private function ollamaWithServer(callable $server): Ollama {
        $config = $this->createMock(AppConfig::class);
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');
        $config->method('get')->willReturnCallback(static function (string $key, string $default = ''): string {
            return match ($key) {
                // A configured model with no fallback chain keeps resolveModel()
                // off the network, so the test only exercises the embed path.
                'embedding_model' => 'test-embed',
                'embedding_model_fallback' => '',
                default => $default,
            };
        });

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(function (string $url, array $options) use ($server): IResponse {
            $result = $server($options['json'] ?? []);
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn((string)($result['body'] ?? ''));
            return $response;
        });
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new Ollama(
            $config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ICacheFactory::class),
            $this->createMock(EmbeddingCache::class)
        );
    }

    /** @param list<string> $input */
    private function responseFor(array $input): string {
        $embeddings = [];
        foreach ($input as $text) {
            $embeddings[] = [0.25, 0.75];
        }
        return (string)json_encode(['embeddings' => $embeddings]);
    }

    /** The model answered "I cannot embed this" with a 400. */
    private function rejectedByModel(): \GuzzleHttp\Exception\ClientException {
        return new \GuzzleHttp\Exception\ClientException(
            'input too long',
            new \GuzzleHttp\Psr7\Request('POST', 'http://127.0.0.1:11434/api/embed'),
            new \GuzzleHttp\Psr7\Response(400)
        );
    }

    public function testOneRefusedTextDoesNotFailTheBatch(): void {
        $requests = 0;
        $ollama = $this->ollamaWithServer(function (array $payload) use (&$requests): array {
            $requests++;
            $input = $payload['input'] ?? [];
            if (count($input) > 1) {
                // A batch the server cannot serve, exactly like a timeout.
                throw new \RuntimeException('batch too large');
            }
            if (str_contains((string)($input[0] ?? ''), 'UNEMBEDDABLE')) {
                throw $this->rejectedByModel();
            }
            return ['body' => $this->responseFor($input)];
        });

        [$vectors, $error] = $ollama->embedBatch(['a normal chunk', 'an UNEMBEDDABLE chunk'], 'alice');

        self::assertNull($error, 'a refused input is not a batch failure');
        self::assertIsArray($vectors);
        self::assertCount(2, $vectors);
        self::assertSame([0.25, 0.75], $vectors[0], 'the healthy document is still embedded');
        self::assertNull($vectors[1], 'only the refused document is reported as an empty slot');
        self::assertGreaterThanOrEqual(3, $requests, 'the batch was halved until the bad input stood alone');
    }

    public function testAHealthyBatchIsEmbeddedInOneRequest(): void {
        $requests = 0;
        $ollama = $this->ollamaWithServer(function (array $payload) use (&$requests): array {
            $requests++;
            return ['body' => $this->responseFor($payload['input'] ?? [])];
        });

        [$vectors, $error] = $ollama->embedBatch(['one', 'two', 'three'], 'alice');

        self::assertNull($error);
        self::assertSame([[0.25, 0.75], [0.25, 0.75], [0.25, 0.75]], $vectors);
        self::assertSame(1, $requests, 'no retry overhead on the happy path');
    }

    /**
     * When the server is unreachable there is nothing to bisect towards, so the
     * call must still report a real error rather than silently returning
     * documents nobody indexed.
     */
    public function testATotalFailureIsStillReportedAsAnError(): void {
        $ollama = $this->ollamaWithServer(function (array $payload): array {
            // An unreachable server arrives as a transport error, not a 4xx.
            throw new \RuntimeException('connection refused');
        });

        [$vectors, $error] = $ollama->embedBatch(['one'], 'alice');

        self::assertNull($vectors);
        self::assertNotNull($error, 'a dead model server must not look like a successful pass');
    }
}
