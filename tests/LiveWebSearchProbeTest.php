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
    private function service(array $values): WebSearchService {
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
}
