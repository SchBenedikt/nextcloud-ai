<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\{AppConfig, OpenAICompatible, ProviderCredentials};
use OCP\Http\Client\{IClient, IClientService, IResponse};
use PHPUnit\Framework\TestCase;

final class OpenAICompatibleTest extends TestCase {
    public function testUnauthenticatedLocalProfileDoesNotReadOrSendAnApiKey(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud OCP interfaces are not available');
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static fn(string $key): string => $key === 'chat_provider' ? 'local' : '0.1');
        $config->method('providerProfile')->with('local')->willReturn([
            'id' => 'local', 'name' => 'Local server', 'url' => 'http://127.0.0.1:1234/v1', 'model' => 'local-model', 'auth' => 'none',
        ]);
        $credentials = $this->createMock(ProviderCredentials::class);
        $credentials->expects(self::never())->method('getCustom');
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('{"choices":[{"message":{"content":"Local reply"}}]}');
        $client = $this->createMock(IClient::class);
        $client->expects(self::once())->method('post')->willReturnCallback(static function (string $url, array $options) use ($response) {
            self::assertSame('http://127.0.0.1:1234/v1/chat/completions', $url);
            self::assertArrayNotHasKey('Authorization', $options['headers']);
            self::assertSame('local-model', $options['json']['model']);
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        self::assertSame('Local reply', (new OpenAICompatible($config, $clients, $credentials))->chat([['role' => 'user', 'content' => 'Hello']])['answer']);
    }

    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testToolRoundTripUsesOpenAiChatCompletionShape(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('userId')->willReturn('alice');
        $config->method('get')->willReturnCallback(static fn(string $key): string => match ($key) {
            'chat_provider' => 'custom',
            'custom_provider_url' => 'https://llm.example/v1',
            'custom_provider_model' => 'test-model',
            default => '0.1',
        });
        $config->method('providerProfile')->willReturn(null);
        $credentials = $this->createMock(ProviderCredentials::class);
        $credentials->method('getCustom')->willReturn('synthetic-key');

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('{"choices":[{"message":{"content":"Done"}}]}');
        $client = $this->createMock(IClient::class);
        $client->expects(self::once())->method('post')->willReturnCallback(static function (string $url, array $options) use ($response) {
            self::assertSame('https://llm.example/v1/chat/completions', $url);
            $messages = $options['json']['messages'];
            self::assertSame('call-42', $messages[0]['tool_calls'][0]['id']);
            self::assertSame('function', $messages[0]['tool_calls'][0]['type']);
            self::assertSame('{"query":"calendar"}', $messages[0]['tool_calls'][0]['function']['arguments']);
            self::assertSame('call-42', $messages[1]['tool_call_id']);
            self::assertArrayNotHasKey('name', $messages[1]);
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        $adapter = new OpenAICompatible($config, $clients, $credentials);

        $result = $adapter->chat([
            ['role' => 'assistant', 'tool_calls' => [[
                'id' => 'call-42',
                'type' => 'function',
                'function' => ['name' => 'lookup', 'arguments' => (object)['query' => 'calendar']],
            ]]],
            ['role' => 'tool', 'name' => 'lookup', 'content' => '{"events":[]}'],
        ]);

        self::assertSame('Done', $result['answer']);
    }
}
