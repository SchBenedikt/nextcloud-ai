<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BrowserRenderer;
use OCA\EvaAi\Service\WebSearchService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How the web search decides to use a browser, and what it hands over.
 *
 * The renderer itself is covered elsewhere; what matters here is the seam. A
 * search must keep working when rendering is unavailable, must forward the
 * administrator's image choice, and must hand the renderer the *same* URL policy
 * the rest of the fetcher uses - that last one is the difference between reading
 * a page and letting a remote server decide what this machine connects to.
 */
final class WebSearchBrowserFallbackTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /** @param array<string,string> $values */
    private function service(array $values, BrowserRenderer $renderer): WebSearchService
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): string => $values[$key] ?? ''
        );
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => isset($values[$key]) ? (int)$values[$key] : (int)($default ?? 0)
        );
        return new WebSearchService(
            $config,
            $this->createMock(IConfig::class),
            $this->createMock(ICrypto::class),
            $renderer,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @param list<mixed> $args */
    private function callPrivate(WebSearchService $service, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($service, $method);
        return $ref->invokeArgs($service, $args);
    }

    /** Nothing is rendered when the renderer says it is unusable. */
    public function testAnUnavailableRendererIsNeverCalled(): void
    {
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('isAvailable')->willReturn(false);
        $renderer->expects(self::never())->method('renderMany');

        $service = $this->service(['web_search_enabled' => '1'], $renderer);

        self::assertSame([], $this->callPrivate($service, 'renderMany', [['https://example.org/a']]));
    }

    /**
     * The URL policy is handed to the renderer rather than reimplemented there,
     * and the policy is the search's own: internal addresses stay out even when
     * the caller asks for a page.
     */
    public function testTheSearchUrlPolicyIsPassedToTheRenderer(): void
    {
        $captured = null;
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('renderMany')->willReturnCallback(
            static function (array $urls, callable $isAllowed, bool $withImages) use (&$captured): array {
                $captured = $isAllowed;
                return [];
            }
        );

        $service = $this->service(['web_search_enabled' => '1'], $renderer);
        $this->callPrivate($service, 'renderMany', [['https://example.org/a']]);

        self::assertIsCallable($captured, 'the renderer was not given a URL policy');
        self::assertTrue($captured('https://example.org/article'));
        self::assertFalse($captured('http://127.0.0.1:11434/api/tags'), 'an internal address must be refused');
        self::assertFalse($captured('http://localhost/admin'), 'an internal address must be refused');
        self::assertFalse($captured('file:///etc/passwd'), 'a non-http scheme must be refused');
    }

    /**
     * Pages that never carry the article are refused by the same policy.
     *
     * A Google News item is a redirect link, and the page it actually lands on is
     * Google's consent interstitial: measured live, that is ~1,400 characters of
     * cookie notice instead of the article, and a batch of three of them consumed
     * the whole render budget and returned nothing. The consent host is refused
     * for the second half of the same reason - a redirect that ends there must
     * not become quotable text.
     */
    public function testTheUrlPolicyRefusesIntermediariesThatNeverCarryTheArticle(): void
    {
        $captured = null;
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('renderMany')->willReturnCallback(
            static function (array $urls, callable $isAllowed, bool $withImages) use (&$captured): array {
                $captured = $isAllowed;
                return [];
            }
        );

        $service = $this->service(['web_search_enabled' => '1'], $renderer);
        $this->callPrivate($service, 'renderMany', [['https://example.org/a']]);

        self::assertIsCallable($captured, 'the renderer was not given a URL policy');
        self::assertFalse(
            $captured('https://news.google.com/rss/articles/CBMiakFVX3lxTE1WTzZKWDBWUUVnZmpP?oc=5'),
            'a Google News redirect must never be fetched or rendered',
        );
        self::assertFalse(
            $captured('https://consent.google.com/m?continue=https://news.google.com/&gl=DE&m=0'),
            'a consent interstitial must never become article text',
        );
        self::assertTrue(
            $captured('https://www.heise.de/en/news/Nextcloud-Hub-10-12345.html'),
            'a publisher link must still be readable',
        );
    }

    /** Asking for such a link fails before anything is fetched or rendered. */
    public function testOpeningAnAggregatorRedirectIsRefusedBeforeAnyFetch(): void
    {
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->expects(self::never())->method('renderMany');

        $service = $this->service(['web_search_enabled' => '1'], $renderer);
        $result = $service->openPage('https://news.google.com/rss/articles/CBMiakFVX3lxTE1WTzZKWDBWUUVnZmpP?oc=5');

        self::assertFalse($result['ok']);
        self::assertSame('', $result['text']);
        self::assertStringContainsString('cannot be opened', (string)$result['error']);
    }

    /** The administrator's image choice reaches the renderer, which uses it to decide what to load. */
    public function testTheImageSettingIsForwarded(): void
    {
        // Explicit pairs, not `key => value`: PHP would turn the numeric string
        // keys of such an array into integers.
        foreach ([['1', true], ['0', false]] as [$setting, $expected]) {
            $seen = null;
            $renderer = $this->createMock(BrowserRenderer::class);
            $renderer->method('isAvailable')->willReturn(true);
            $renderer->method('renderMany')->willReturnCallback(
                static function (array $urls, callable $isAllowed, bool $withImages) use (&$seen): array {
                    $seen = $withImages;
                    return [];
                }
            );

            $service = $this->service(['web_search_enabled' => '1', 'web_search_images' => $setting], $renderer);
            $this->callPrivate($service, 'renderMany', [['https://example.org/a']]);

            self::assertSame($expected, $seen, 'images setting ' . $setting . ' was not forwarded');
        }
    }

    /** The admin screen shows the renderer's own explanation, not a generic message. */
    public function testTheBrowserStatusComesFromTheRenderer(): void
    {
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('unavailableReason')->willReturn('The configured Node.js path is not an executable file: /opt/node');

        $service = $this->service([], $renderer);

        self::assertSame(
            'The configured Node.js path is not an executable file: /opt/node',
            $service->browserStatus(),
        );
    }

    /**
     * Opening an internal address fails before anything is fetched or rendered,
     * so no browser can be aimed at the local network by asking for a page.
     */
    public function testOpeningAnInternalAddressIsRefusedBeforeAnyFetch(): void
    {
        $renderer = $this->createMock(BrowserRenderer::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->expects(self::never())->method('renderMany');

        $service = $this->service(['web_search_enabled' => '1'], $renderer);
        $result = $service->openPage('http://127.0.0.1:11434/api/tags');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be opened', (string)$result['error']);
    }
}
