<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ProviderException;
use OCA\EvaAi\Service\WebSearchService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Web search is a privacy-sensitive opt-in (Issue #187): it must stay off
 * until an administrator enables it, it must never run without a configured
 * provider, and it must never turn a hostile provider response into an unsafe
 * link in the chat answer.
 */
final class WebSearchServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /** @param array<string,string> $values */
    private function service(
        array $values = [],
        ?IConfig $rawConfig = null,
        ?ICrypto $crypto = null,
    ): WebSearchService {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): string => $values[$key] ?? ''
        );
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => isset($values[$key]) ? (int)$values[$key] : (int)($default ?? 0)
        );
        return new WebSearchService(
            $config,
            $rawConfig ?? $this->createMock(IConfig::class),
            $crypto ?? $this->createMock(ICrypto::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @param list<mixed> $args */
    private function callPrivate(WebSearchService $service, string $method, array $args): mixed {
        $ref = new \ReflectionMethod($service, $method);
        return $ref->invokeArgs($service, $args);
    }

    // ---- Opt-in and configuration ----

    public function testWebSearchIsOffByDefault(): void {
        $service = $this->service();
        self::assertFalse($service->isEnabled());
        self::assertFalse($service->isConfigured());

        $result = $service->search('latest nextcloud release');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('disabled', (string)$result['error']);
    }

    public function testSearxngWithoutUrlIsNotConfigured(): void {
        $service = $this->service(['web_search_enabled' => '1', 'web_search_provider' => 'searxng', 'web_search_url' => '']);
        self::assertTrue($service->isEnabled());
        self::assertFalse($service->isConfigured());

        $result = $service->search('anything');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('SearxNG URL', (string)$result['error']);
    }

    public function testHostedProviderNeedsAnApiKey(): void {
        $service = $this->service(['web_search_enabled' => '1', 'web_search_provider' => 'brave']);
        self::assertFalse($service->isConfigured());

        $result = $service->search('anything');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Brave Search API key', (string)$result['error']);
    }

    public function testUnknownProviderFallsBackToSearxng(): void {
        $service = $this->service(['web_search_provider' => 'not-a-provider']);
        self::assertSame('searxng', $service->provider());
        self::assertSame(WebSearchService::PROVIDERS, ['duckduckgo', 'searxng', 'brave', 'tavily']);
    }

    public function testAnEmptyQueryIsRejected(): void {
        $service = $this->service(['web_search_enabled' => '1', 'web_search_provider' => 'searxng', 'web_search_url' => 'https://searx.example.org']);
        $result = $service->search('   ');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('query', (string)$result['error']);
    }

    public function testMaxResultsIsClampedToASafeRange(): void {
        self::assertSame(10, $this->service(['web_search_max_results' => '99'])->maxResults());
        self::assertSame(1, $this->service(['web_search_max_results' => '0'])->maxResults());
        self::assertSame(5, $this->service(['web_search_max_results' => '5'])->maxResults());
    }

    public function testSearxngUrlIsNormalizedWithoutTrailingSlash(): void {
        $service = $this->service(['web_search_url' => ' https://searx.example.org/ ']);
        self::assertSame('https://searx.example.org', $service->endpoint());
    }

    // ---- API key handling ----

    public function testApiKeyMustLookLikeAKey(): void {
        $service = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $service->saveApiKey('short');
    }

    public function testClearingTheApiKeyDeletesIt(): void {
        $raw = $this->createMock(IConfig::class);
        $raw->expects(self::once())
            ->method('deleteAppValue')
            ->with(AppConfig::APP, WebSearchService::API_KEY_KEY);
        $this->service([], $raw)->saveApiKey('');
    }

    public function testStoringAnApiKeyEncryptsItAndNeverReturnsIt(): void {
        $raw = $this->createMock(IConfig::class);
        $crypto = $this->createMock(ICrypto::class);
        $crypto->expects(self::once())->method('encrypt')->with('sk-live-abcdefghijklmnop')->willReturn('ENCRYPTED');
        $raw->expects(self::once())
            ->method('setAppValue')
            ->with(AppConfig::APP, WebSearchService::API_KEY_KEY, 'ENCRYPTED');

        $this->service([], $raw, $crypto)->saveApiKey('sk-live-abcdefghijklmnop');
    }

    public function testHasApiKeyReadsTheStoredValue(): void {
        $raw = $this->createMock(IConfig::class);
        $raw->method('getAppValue')->with(AppConfig::APP, WebSearchService::API_KEY_KEY, '')->willReturn('ENCRYPTED');
        self::assertTrue($this->service([], $raw)->hasApiKey());
    }

    public function testAnUndecryptableKeyIsReportedInsteadOfLeakingAnException(): void {
        $raw = $this->createMock(IConfig::class);
        $raw->method('getAppValue')->with(AppConfig::APP, WebSearchService::API_KEY_KEY, '')->willReturn('ENCRYPTED');
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('decrypt')->willThrowException(new \Exception('bad key material'));

        $service = $this->service(
            ['web_search_enabled' => '1', 'web_search_provider' => 'brave'],
            $raw,
            $crypto,
        );
        $result = $service->search('anything');
        self::assertFalse($result['ok']);
        self::assertNotEmpty($result['error']);
    }

    // ---- Response hardening ----

    public function testNormalizationDropsUnsafeUrlsAndBoundsSnippets(): void {
        $service = $this->service();
        $rows = [
            ['title' => 'Ok', 'url' => 'https://example.org/a', 'content' => 'fine'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)', 'content' => 'evil'],
            ['title' => 'Local', 'url' => 'file:///etc/passwd', 'content' => 'evil'],
            ['title' => 'Creds', 'url' => 'https://user:pass@example.org/', 'content' => 'evil'],
            ['title' => 'Empty host', 'url' => 'https:///nohost', 'content' => 'evil'],
            ['title' => 'Long', 'url' => 'https://example.org/b', 'content' => str_repeat('x', 2000)],
        ];

        $out = $this->callPrivate($service, 'normalize', [$rows, 'title', 'url', 'content', 10]);

        self::assertCount(2, $out);
        self::assertSame('https://example.org/a', $out[0]['url']);
        self::assertSame('https://example.org/b', $out[1]['url']);
        self::assertLessThanOrEqual(601, mb_strlen($out[1]['snippet']));
    }

    public function testNormalizationHonoursTheResultLimit(): void {
        $service = $this->service();
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['title' => 'T' . $i, 'url' => 'https://example.org/' . $i, 'content' => 'c'];
        }
        self::assertCount(3, $this->callPrivate($service, 'normalize', [$rows, 'title', 'url', 'content', 3]));
    }

    public function testNormalizationFallsBackToTheUrlWhenTheTitleIsMissing(): void {
        $service = $this->service();
        $out = $this->callPrivate($service, 'normalize', [
            [['url' => 'https://example.org/x', 'content' => 'c']],
            'title',
            'url',
            'content',
            5,
        ]);
        self::assertSame('https://example.org/x', $out[0]['title']);
    }

    public function testUnsafeUrlsAreRejectedByTheUrlGuard(): void {
        $service = $this->service();
        foreach ([
            '',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'data:text/html,<script>',
            'https://user:pass@example.org/',
            'https://',
            'ftp://example.org/file',
        ] as $unsafe) {
            self::assertFalse($this->callPrivate($service, 'isSafeHttpUrl', [$unsafe]), $unsafe . ' must be rejected');
        }
        foreach (['http://example.org', 'https://example.org/path?q=1#frag'] as $safe) {
            self::assertTrue($this->callPrivate($service, 'isSafeHttpUrl', [$safe]), $safe . ' must be accepted');
        }
    }

    public function testProviderExceptionIsAnException(): void {
        self::assertInstanceOf(\Exception::class, new ProviderException('x'));
    }
}
