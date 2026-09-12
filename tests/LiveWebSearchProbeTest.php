<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\WebSearchService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TEMPORARY live probe. Skipped unless EVA_AI_LIVE_WEB=1. Exercises real DNS,
 * real HTTP and the real guard, so its output is evidence rather than a mock.
 */
final class LiveWebSearchProbeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (getenv('EVA_AI_LIVE_WEB') !== '1') {
            $this->markTestSkipped('live probe disabled');
        }
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /** @param array<string,string> $values */
    private function service(array $values, ?\OCA\EvaAi\Service\BrowserRenderer $renderer = null): WebSearchService {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): string => $values[$key] ?? ''
        );
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => isset($values[$key]) ? (int)$values[$key] : (int)($default ?? 0)
        );
        if ($renderer === null) {
            $renderer = $this->createMock(\OCA\EvaAi\Service\BrowserRenderer::class);
            $renderer->method('isAvailable')->willReturn(false);
        }
        return new WebSearchService(
            $config,
            $this->createMock(IConfig::class),
            $this->createMock(ICrypto::class),
            $renderer,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function defaults(): array {
        return [
            'web_search_enabled' => '1',
            'web_search_provider' => 'duckduckgo',
            'web_search_fetch_content' => '1',
            'web_search_content_chars' => '3000',
            'web_search_images' => '1',
            'web_search_candidates' => '12',
            'web_search_max_results' => '6',
            'web_search_timeout' => '12',
        ];
    }

    public function testLiveGuardRefusesTheRunningLocalService(): void
    {
        $service = $this->service($this->defaults());
        $ref = new \ReflectionMethod($service, 'isFetchableUrl');
        $urls = [
            'http://127.0.0.1:11434/api/tags' => false,
            'http://localhost:11434/api/tags' => false,
            'http://169.254.169.254/latest/meta-data/' => false,
            'http://[::1]:8080/' => false,
            'https://example.org/' => true,
        ];
        foreach ($urls as $url => $expected) {
            $actual = $ref->invoke($service, $url);
            echo "\n[fetchable] $url => " . ($actual ? 'yes' : 'no') . " (expected " . ($expected ? 'yes' : 'no') . ")";
            self::assertSame($expected, $actual, $url);
        }
        echo "\n";
    }

    public function testLiveOpenPageRefusesLoopbackWhileCurlSucceeds(): void
    {
        $probe = @file_get_contents('http://127.0.0.1:11434/api/tags');
        echo "\n[live] direct fetch of loopback: " . ($probe === false ? 'unreachable' : 'REACHABLE (' . strlen($probe) . ' bytes)');
        $service = $this->service($this->defaults());
        $page = $service->openPage('http://127.0.0.1:11434/api/tags');
        echo "\n[live] openPage(loopback) ok=" . var_export($page['ok'], true) . " error=" . (string)$page['error'];
        self::assertFalse($page['ok']);
        self::assertSame('', $page['text']);
        echo "\n";
    }

    public function testLiveRedirectToLoopbackIsNotFollowed(): void
    {
        $service = $this->service($this->defaults());
        $ref = new \ReflectionMethod($service, 'resolveRedirect');
        self::assertNull($ref->invoke($service, 'https://example.org/', 'http://127.0.0.1:11434/api/tags'));
        echo "\n[live] redirect to loopback refused\n";
    }

    public function testLiveWebSearchReturnsRealPagesWithImages(): void
    {
        $service = $this->service($this->defaults());
        $result = $service->search('Nextcloud Hub release', 6, 'web');
        echo "\n[live] ok=" . var_export($result['ok'], true) . " error=" . (string)$result['error'] . "\n";
        if (!$result['ok']) {
            self::markTestSkipped('no network for provider: ' . (string)$result['error']);
        }
        $withContent = 0;
        $images = 0;
        foreach ($result['results'] as $i => $r) {
            $len = strlen((string)($r['content'] ?? ''));
            $imgCount = count($r['images'] ?? []);
            $withContent += $len > 200 ? 1 : 0;
            $images += $imgCount;
            echo sprintf(
                "[%d] %s\n     url=%s\n     content=%dB highlights=%s images=%d\n",
                $i,
                substr((string)$r['title'], 0, 80),
                (string)$r['url'],
                $len,
                ($r['highlights'] ?? '') !== '' ? 'yes' : 'no',
                $imgCount
            );
            foreach (array_slice($r['images'] ?? [], 0, 3) as $img) {
                echo '       img: ' . $img['url'] . "\n";
            }
        }
        echo "[live] results=" . count($result['results']) . " withContent=$withContent images=$images\n";
        self::assertGreaterThan(0, count($result['results']));
    }

    public function testLiveNewsSearchCarriesDatesAndSources(): void
    {
        $service = $this->service($this->defaults());
        $result = $service->search('Nextcloud', 6, 'news');
        echo "\n[live][news] ok=" . var_export($result['ok'], true) . " error=" . (string)$result['error'] . "\n";
        if (!$result['ok']) {
            self::markTestSkipped('no news feed reachable: ' . (string)$result['error']);
        }
        $dated = 0;
        foreach ($result['results'] as $r) {
            $published = $r['published'] ?? null;
            $dated += $published ? 1 : 0;
            echo sprintf(
                "[news] %s | %s | %s\n       %s\n",
                substr((string)$r['title'], 0, 70),
                (string)($r['source'] ?? '-'),
                $published ? date('Y-m-d', (int)$published) : 'no-date',
                (string)$r['url']
            );
        }
        echo "[live][news] results=" . count($result['results']) . " dated=$dated\n";
        self::assertGreaterThan(0, count($result['results']));
    }

    public function testLiveImageSearchReturnsEmbeddablePictures(): void
    {
        $service = $this->service($this->defaults());
        $queries = ['golden retriever', 'Nextcloud Hub logo', 'Brandenburger Tor'];
        foreach ($queries as $query) {
            $result = $service->searchImages($query, 4);
            echo "\n[live][images] query=\"$query\" ok=" . var_export($result['ok'], true)
                . ' error=' . (string)$result['error'] . "\n";
            if (!$result['ok']) {
                continue;
            }
            foreach ($result['images'] as $img) {
                echo '  ' . substr((string)$img['title'], 0, 50) . "\n      " . $img['url']
                    . "\n      preview: " . $img['preview'] . ' page: ' . $img['page'] . "\n";
            }
            self::assertGreaterThan(0, count($result['images']), 'no pictures for ' . $query);
        }
    }

    public function testLiveOpenPageReadsAnArticleInDepth(): void
    {
        $service = $this->service($this->defaults());
        $page = $service->openPage('https://en.wikipedia.org/wiki/Nextcloud', 'Nextcloud Hub');
        echo "\n[live][page] ok=" . var_export($page['ok'], true) . " error=" . (string)$page['error'] . "\n";
        if (!$page['ok']) {
            self::markTestSkipped('page not reachable');
        }
        echo '[live][page] title=' . $page['title'] . "\n";
        echo '[live][page] textChars=' . strlen($page['text']) . ' truncated=' . var_export($page['truncated'], true)
            . ' images=' . count($page['images']) . ' published=' . var_export($page['published'], true) . "\n";
        echo '[live][page] highlights=' . substr($page['highlights'], 0, 200) . "\n";
        self::assertGreaterThan(200, strlen($page['text']));
    }

    /**
     * The same page read twice: once as a plain HTTP GET, once in a real browser.
     *
     * The point is the comparison. For a client-rendered page the static body is
     * a shell with almost nothing in it, and the difference between the two
     * numbers is the whole reason browser rendering exists. Run with
     * `EVA_AI_LIVE_WEB=1`; it is a probe, not a CI test.
     */
    public function testLiveAJavaScriptPageIsOnlyReadableInABrowser(): void
    {
        $renderer = new \OCA\EvaAi\Service\BrowserRenderer(
            (static function (): AppConfig {
                return new class extends AppConfig {
                    public function __construct() {}
                    public function get(string $key): string {
                        if ($key === \OCA\EvaAi\Service\BrowserRenderer::ENABLED_KEY) {
                            return '1';
                        }
                        // Empty, not '0': an empty Node path means "find node on
                        // PATH", while any other value is treated as a path.
                        return '';
                    }
                    public function getInt(string $key, ?int $default = null): int {
                        return $key === 'web_search_browser_timeout' ? 30 : (int)($default ?? 0);
                    }
                };
            })(),
            $this->createMock(LoggerInterface::class),
        );
        if (!$renderer->isAvailable()) {
            self::markTestSkipped('browser rendering unavailable: ' . $renderer->unavailableReason());
        }

        $values = $this->defaults();
        $values['web_search_browser'] = '1';
        $staticService = $this->service($values);
        $browserService = $this->service($values, $renderer);

        $urls = [
            'https://vuejs.org/guide/introduction.html',
            'https://angular.dev/overview',
            'https://www.reddit.com/r/nextcloud/',
        ];

        $best = 0;
        foreach ($urls as $url) {
            $static = $staticService->openPage($url);
            $browser = $browserService->openPage($url);
            $staticChars = $static['ok'] ? strlen($static['text']) : 0;
            $browserChars = $browser['ok'] ? strlen($browser['text']) : 0;
            echo "\n[live][render] $url\n"
                . '  static:  ok=' . var_export($static['ok'], true) . ' chars=' . $staticChars
                . ' error=' . (string)$static['error'] . "\n"
                . '  browser: ok=' . var_export($browser['ok'], true) . ' chars=' . $browserChars
                . ' error=' . (string)$browser['error'] . "\n";
            if ($browser['ok']) {
                echo '  browser text: ' . substr(preg_replace('/\s+/', ' ', $browser['text']) ?? '', 0, 220) . "\n";
            }
            $best = max($best, $browserChars);
        }

        // At least one real page has to come back with a substantial body through
        // the browser, otherwise the feature is not actually working on the web.
        self::assertGreaterThan(500, $best, 'no page produced readable text through the browser');
    }
}
