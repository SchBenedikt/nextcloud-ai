<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\EmbeddingCache;
use OCA\EvaAi\Service\Ollama;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Controlled-backend coverage for the Ollama streaming client (Issue #134):
 * the stream decoder is exercised against a fake HTTP body instead of a live
 * server, so malformed lines, tool-call arguments split across chunks, awkward
 * read boundaries and connection errors are all deterministic in CI.
 */
final class OllamaStreamContractTest extends TestCase {
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

    /** A PSR-Stream-like body that delivers bytes in small fixed chunks. */
    private function fakeBody(string $data, int $chunkSize = 7): object {
        return new class($data, $chunkSize) {
            private int $pos = 0;

            public function __construct(
                private string $data,
                private int $chunkSize,
            ) {
            }

            public function read(int $length): string {
                if ($this->pos >= strlen($this->data)) {
                    return '';
                }
                $part = substr($this->data, $this->pos, min($length, $this->chunkSize));
                $this->pos += strlen($part);
                return $part;
            }

            public function eof(): bool {
                return $this->pos >= strlen($this->data);
            }

            public function close(): void {
                $this->pos = strlen($this->data);
            }
        };
    }

    private function ollamaWithStreamBody(object $body, ?\Throwable $postError = null): Ollama {
        $tags = $this->createMock(IResponse::class);
        $tags->method('getStatusCode')->willReturn(200);
        $tags->method('getBody')->willReturn(json_encode([
            'models' => [
                ['name' => 'llama3.1', 'capabilities' => ['completion', 'tools']],
            ],
        ], JSON_THROW_ON_ERROR));
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($tags);
        if ($postError !== null) {
            $client->method('post')->willThrowException($postError);
        } else {
            $chat = $this->createMock(IResponse::class);
            $chat->method('getBody')->willReturn($body);
            $client->method('post')->willReturn($chat);
        }
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static fn(string $key): string => match ($key) {
            'chat_provider' => 'ollama',
            'chat_model' => 'llama3.1',
            'temperature' => '0.7',
            'context_size' => '4096',
            default => '',
        });
        $config->method('ollamaUrl')->willReturn('http://127.0.0.1:11434');

        return new Ollama(
            $config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $cacheFactory,
            $this->createMock(EmbeddingCache::class)
        );
    }

    /** @return list<array{type:string,delta?:string,content?:string,model?:string}> */
    private function collect(\Generator $gen): array {
        $events = [];
        foreach ($gen as $event) {
            $events[] = $event;
        }
        return $events;
    }

    public function testMalformedLinesAreSkippedWithoutBreakingTheStream(): void {
        $body = $this->fakeBody(implode("\n", [
            '{"model":"llama3.1","message":{"role":"assistant","content":"Hello"},"done":false}',
            'this is not json at all {',
            '{"model":"llama3.1","message":{"role":"assistant","content":" world"},"done":false}',
            '',
            '{"model":"llama3.1","message":{"role":"assistant","content":null},"done":true}',
            '',
        ]));
        $ollama = $this->ollamaWithStreamBody($body);

        $events = $this->collect($ollama->chatStream([
            ['role' => 'user', 'content' => 'Hi'],
        ]));

        self::assertSame('Hello', $events[0]['delta']);
        self::assertSame(' world', $events[1]['delta']);
        self::assertSame('finished', $events[2]['type']);
        self::assertCount(3, $events, 'garbage lines never surface as events');
    }

    public function testToolCallArgumentsSplitAcrossChunksAreAccumulated(): void {
        $body = $this->fakeBody(implode("\n", [
            '{"model":"llama3.1","message":{"role":"assistant","content":"","tool_calls":[{"index":0,"function":{"name":"create_share","arguments":"{\"pa"}}]},"done":false}',
            '{"model":"llama3.1","message":{"role":"assistant","content":"","tool_calls":[{"index":0,"function":{"arguments":"th\":\"/Notes\"}"}}]},"done":true}',
            '',
        ]));
        $ollama = $this->ollamaWithStreamBody($body);

        $events = $this->collect($ollama->chatStream([
            ['role' => 'user', 'content' => 'Create a share'],
        ], ['create_share' => ['type' => 'function']]));

        self::assertSame('tool_calls', $events[0]['type']);
        $calls = $events[0]['tool_calls'];
        self::assertCount(1, $calls);
        self::assertSame('create_share', $calls[0]['name']);
        self::assertSame(['path' => '/Notes'], $calls[0]['arguments'], 'split JSON arguments are concatenated before decoding');
    }

    public function testMultiByteUtf8SplitAcrossReadBoundariesArrivesIntact(): void {
        // The stream is delivered in 5-byte chunks, splitting the UTF-8
        // characters and the JSON lines mid-way; only complete lines may be
        // decoded.
        $body = $this->fakeBody(implode("\n", [
            '{"model":"llama3.1","message":{"role":"assistant","content":"Grüße aus Köln"},"done":false}',
            '{"model":"llama3.1","message":{"role":"assistant","content":"✓"},"done":false}',
            '{"model":"llama3.1","message":{"role":"assistant","content":""},"done":true}',
            '',
        ]), 5);
        $ollama = $this->ollamaWithStreamBody($body);

        $events = $this->collect($ollama->chatStream([
            ['role' => 'user', 'content' => 'Hallo'],
        ]));

        self::assertSame('Grüße aus Köln', $events[0]['delta']);
        self::assertSame('✓', $events[1]['delta']);
        self::assertSame('finished', $events[2]['type']);
    }

    public function testConnectionErrorYieldsAnErrorEvent(): void {
        $ollama = $this->ollamaWithStreamBody(
            $this->fakeBody(''),
            new \RuntimeException('Connection refused (127.0.0.1:11434)')
        );

        $events = $this->collect($ollama->chatStream([
            ['role' => 'user', 'content' => 'Hi'],
        ]));

        self::assertCount(1, $events);
        self::assertSame('error', $events[0]['type']);
        self::assertStringContainsString('Connection refused', (string)$events[0]['delta']);
    }

    public function testUnparseableToolArgumentsDecodeToAnEmptyObject(): void {
        $body = $this->fakeBody(implode("\n", [
            '{"model":"llama3.1","message":{"role":"assistant","content":"","tool_calls":[{"index":0,"function":{"name":"read_file","arguments":"not json"}}]},"done":true}',
            '',
        ]));
        $ollama = $this->ollamaWithStreamBody($body);

        $events = $this->collect($ollama->chatStream([
            ['role' => 'user', 'content' => 'Read a file'],
        ], ['read_file' => ['type' => 'function']]));

        self::assertSame('tool_calls', $events[0]['type']);
        self::assertSame(['name' => 'read_file', 'arguments' => []], $events[0]['tool_calls'][0]);
    }
}