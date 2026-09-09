<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests;
use OCA\EvaAi\Service\{AppConfig, Groq, ProviderCredentials};
use OCP\Http\Client\{IClientService, IClient, IResponse};
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class GroqTest extends TestCase {
    private function adapter(int $status, mixed $body, ?callable $inspect = null): Groq {
        $config = $this->createMock(AppConfig::class);
        $config->method('userId')->willReturn('alice');
        $config->method('get')->willReturnCallback(fn($key) => $key === 'groq_model' ? Groq::MODELS[0] : '0.1');
        $credentials = $this->createMock(ProviderCredentials::class);
        $credentials->method('get')->with('alice')->willReturn('gsk_synthetic_test_key_1234');
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $client = $this->createMock(IClient::class);
        $client->expects(self::once())->method('post')->willReturnCallback(function ($url, $options) use ($response, $inspect) {
            self::assertSame('https://api.groq.com/openai/v1/chat/completions', $url);
            self::assertFalse($options['allow_redirects']);
            self::assertSame('Bearer gsk_synthetic_test_key_1234', $options['headers']['Authorization']);
            if ($inspect) $inspect($options['json']);
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        return new Groq($config, $clients, $credentials);
    }
    public function testToolHistoryIsTranslatedWithoutFlatteningArgumentArrays(): void {
        $adapter = $this->adapter(200, '{"choices":[{"message":{"content":"Done"}}]}', function ($payload) {
            $call = $payload['messages'][0]['tool_calls'][0];
            self::assertSame('{"items":["a","b"]}', $call['function']['arguments']);
            self::assertSame($call['id'], $payload['messages'][1]['tool_call_id']);
            self::assertArrayNotHasKey('name', $payload['messages'][1]);
        });
        $result = $adapter->chat([
            ['role' => 'assistant', 'tool_calls' => [['function' => ['name' => 'lookup', 'arguments' => ['items' => ['a', 'b']]]]]],
            ['role' => 'tool', 'name' => 'lookup', 'content' => 'result'],
        ], []);
        self::assertSame('Done', $result['answer']);
    }
    public function testRateLimitDoesNotRetryOrExposeResponseSecrets(): void {
        $result = $this->adapter(429, 'secret diagnostic')->chat([], []);
        self::assertStringContainsString('rate limit', $result['error']);
        self::assertStringNotContainsString('secret', $result['error']);
    }
    public function testStreamingAssemblesToolsOnlyAfterCompletedStream(): void {
        $body = fopen('php://temp', 'w+');
        foreach ([['content' => 'Hello'], ['tool_calls' => [['index' => 0, 'id' => 'call1', 'function' => ['name' => 'lookup', 'arguments' => '{"q":']]]], ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"test"}']]]]] as $delta) {
            fwrite($body, 'data: ' . json_encode(['choices' => [['delta' => $delta]]]) . "\r\n\r\n");
        }
        fwrite($body, "data: [DONE]\n\n"); rewind($body);
        $events = iterator_to_array($this->adapter(200, $body)->chatStream([], []));
        self::assertSame('Hello', $events[0]['delta']);
        self::assertSame(['name' => 'lookup', 'arguments' => ['q' => 'test']], $events[1]['tool_calls'][0]);
        self::assertFalse(is_resource($body));
    }
    public function testTruncatedStreamCannotReleaseToolCalls(): void {
        $body = fopen('php://temp', 'w+');
        fwrite($body, 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"name":"delete","arguments":"{}"}}]}}]}' . "\n\n"); rewind($body);
        $events = iterator_to_array($this->adapter(200, $body)->chatStream([], []));
        self::assertCount(1, $events);
        self::assertSame('error', $events[0]['type']);
    }
    public function testCredentialsAreEncryptedAndIsolatedByUser(): void {
        $values = [];
        $config = $this->createMock(IConfig::class);
        $config->method('setUserValue')->willReturnCallback(function ($user, $app, $key, $value) use (&$values) { $values[$user][$key] = $value; });
        $config->method('getUserValue')->willReturnCallback(function ($user, $app, $key, $default = '') use (&$values) { return $values[$user][$key] ?? $default; });
        $config->method('deleteUserValue')->willReturnCallback(function ($user, $app, $key) use (&$values) { unset($values[$user][$key]); });
        $crypto = $this->createMock(ICrypto::class);
        $crypto->expects(self::once())->method('encrypt')->with('gsk_synthetic_test_key_1234')->willReturn('encrypted');
        $crypto->method('decrypt')->with('encrypted')->willReturn('gsk_synthetic_test_key_1234');
        $store = new ProviderCredentials($config, $crypto);
        $store->save('alice', 'gsk_synthetic_test_key_1234');
        self::assertSame('encrypted', $values['alice'][ProviderCredentials::KEY]);
        self::assertFalse($store->configured('bob'));
        self::assertSame('gsk_synthetic_test_key_1234', $store->get('alice'));
        $store->save('alice', '');
        self::assertFalse($store->configured('alice'));
    }
}
