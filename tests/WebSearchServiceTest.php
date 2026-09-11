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

    public function testUnknownProviderFallsBackToTheDefaultProvider(): void {
        // The settings UI and AppConfig both default to DuckDuckGo, so an
        // unset or stale stored value has to resolve to it as well.
        $service = $this->service(['web_search_provider' => 'not-a-provider']);
        self::assertSame('duckduckgo', $service->provider());
        self::assertSame(WebSearchService::PROVIDERS, ['duckduckgo', 'searxng', 'brave', 'tavily']);
    }

    public function testAnEmptyQueryIsRejected(): void {
        $service = $this->service(['web_search_enabled' => '1', 'web_search_provider' => 'searxng', 'web_search_url' => 'https://searx.example.org']);
        $result = $service->search('   ');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('query', (string)$result['error']);
    }

    public function testMaxResultsIsClampedToASafeRange(): void {
        self::assertSame(20, $this->service(['web_search_max_results' => '99'])->maxResults());
        self::assertSame(1, $this->service(['web_search_max_results' => '0'])->maxResults());
        self::assertSame(5, $this->service(['web_search_max_results' => '5'])->maxResults());
        self::assertSame(8, $this->service()->maxResults(), 'the default widens the result set');
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

    // ---- DuckDuckGo endpoint handling (Issue #187 regression) ----

    /**
     * The exact markup the DuckDuckGo HTML endpoint serves: `rel` comes before
     * `class`, the destination is wrapped in a redirect URL, and snippets live
     * in their own anchor. An attribute-order assumption here silently produced
     * zero results before this test existed.
     */
    public function testDuckDuckGoHtmlIsParsedIntoTitleUrlAndSnippet(): void {
        $service = $this->service();
        $html = <<<'HTML'
<h2 class="result__title">
  <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fnextcloud.com%2F&amp;rut=abc123">Official site</a>
</h2>
<div class="result__extras"><div class="result__extras__url">
  <a rel="nofollow" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fnextcloud.com%2F&amp;rut=abc123"><img class="result__icon__img" src="x"></a>
</div></div>
<a class="result__snippet" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fnextcloud.com%2F&amp;rut=abc123">Open source file sync and share</a>
<h2 class="result__title">
  <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fde.wikipedia.org%2Fwiki%2FNextcloud&amp;rut=def456">Nextcloud - Wikipedia</a>
</h2>
<a class="result__snippet">Content collaboration platform</a>
HTML;

        $out = $this->callPrivate($service, 'parseDuckDuckGoResults', [$html, 5]);

        self::assertCount(2, $out);
        self::assertSame('Official site', $out[0]['title']);
        self::assertSame('https://nextcloud.com/', $out[0]['url']);
        self::assertSame('Open source file sync and share', $out[0]['snippet']);
        self::assertSame('https://de.wikipedia.org/wiki/Nextcloud', $out[1]['url']);
        self::assertSame('Content collaboration platform', $out[1]['snippet']);
    }

    /**
     * The lightweight endpoint uses single-quoted classes and puts `href`
     * before `class`, so it is parsed structurally rather than by pattern.
     */
    public function testDuckDuckGoLiteIsParsedWithSingleQuotedClasses(): void {
        $service = $this->service();
        $html = <<<'HTML'
<table>
<tr><td><a rel="nofollow" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fa" class='result-link'>Example A</a></td></tr>
<tr><td class='result-snippet'>First snippet</td></tr>
<tr><td><a rel="nofollow" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fb" class='result-link'>Example B</a></td></tr>
<tr><td class='result-snippet'>Second snippet</td></tr>
</table>
HTML;

        $out = $this->callPrivate($service, 'parseDuckDuckGoLiteResults', [$html, 5]);

        self::assertCount(2, $out);
        self::assertSame('Example A', $out[0]['title']);
        self::assertSame('https://example.org/a', $out[0]['url']);
        self::assertSame('First snippet', $out[0]['snippet']);
        self::assertSame('https://example.org/b', $out[1]['url']);
        self::assertSame('Second snippet', $out[1]['snippet']);
    }

    /**
     * DuckDuckGo answers automated traffic with an "anomaly" interstitial that
     * contains no result markup, and serves it with HTTP 202. Recognising it is
     * what turns a silent empty answer into an actionable error.
     */
    public function testDuckDuckGoAnomalyPageIsDetected(): void {
        $service = $this->service();
        $anomaly = '<html><body><div class="anomaly-modal__modal">'
            . '<form id="challenge-form" action="//duckduckgo.com/anomaly.js?sv=html&amp;cc=sre"></form></body></html>';
        self::assertTrue($this->callPrivate($service, 'isDuckDuckGoAnomaly', [$anomaly]));

        $real = '<a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fnextcloud.com%2F">Official site</a>';
        self::assertFalse($this->callPrivate($service, 'isDuckDuckGoAnomaly', [$real]));

        // Neither a result page nor an explicit challenge: keep it usable, so a
        // future DDG layout change is not misread as a block.
        self::assertFalse($this->callPrivate($service, 'isDuckDuckGoAnomaly', ['<html><body>nothing here</body></html>']));
    }

    /**
     * Without the browser-like headers DuckDuckGo classifies the request as a
     * bot and serves the interstitial instead of results.
     */
    public function testBrowserHeadersLookLikeATopLevelNavigation(): void {
        $service = $this->service();
        $joined = implode("\n", $this->callPrivate($service, 'browserHeaders', ['https://html.duckduckgo.com/html/']));

        self::assertStringContainsString('Sec-Fetch-Dest: document', $joined);
        self::assertStringContainsString('Sec-Fetch-Mode: navigate', $joined);
        self::assertStringContainsString('Accept-Language:', $joined);
        self::assertStringContainsString('Upgrade-Insecure-Requests: 1', $joined);
        self::assertStringContainsString('Accept: text/html', $joined);
        // Origin must be scheme+host only, never the full path.
        self::assertStringContainsString('Origin: https://html.duckduckgo.com', $joined);
        self::assertStringNotContainsString('Origin: https://html.duckduckgo.com/html/', $joined);
    }

    /**
     * DuckDuckGo interleaves sponsored hits and internal aggregation links with
     * the real results, and they appear first in document order. Without this
     * filter the model would cite ad-tracking redirects instead of sources.
     */
    public function testDuckDuckGoAdsAndInternalLinksAreFiltered(): void {
        $service = $this->service();
        $html = <<<'HTML'
<h2 class="result__title">
  <a rel="nofollow" class="result__a" href="https://duckduckgo.com/y.js?ad_domain=proton.me&amp;ad_provider=bingv7aa">Sponsored cloud storage</a>
</h2>
<a class="result__snippet">Ad copy that must never reach the model.</a>
<h2 class="result__title">
  <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fnextcloud.com%2Ffeatures%2F&amp;rut=zz">Nextcloud features</a>
</h2>
<a class="result__snippet">Discover all Nextcloud features.</a>
HTML;

        $out = $this->callPrivate($service, 'parseDuckDuckGoResults', [$html, 5]);

        self::assertCount(1, $out);
        self::assertSame('https://nextcloud.com/features/', $out[0]['url']);
    }

    /**
     * The DuckDuckGo host test must not be fooled by a lookalike domain, which
     * would let a spoofed site pose as the search engine.
     */
    public function testDuckDuckGoOwnHostsAreRecognised(): void {
        $service = $this->service();
        foreach ([
            'https://duckduckgo.com/y.js?ad_domain=x',
            'https://duckduckgo.com/c/Nextcloud',
            'https://html.duckduckgo.com/html/',
        ] as $own) {
            self::assertTrue($this->callPrivate($service, 'isDuckDuckGoHost', [$own]), $own . ' must count as DuckDuckGo');
        }
        foreach (['https://nextcloud.com/', 'https://duckduckgo.com.evil.test/', 'https://notduckduckgo.com/'] as $external) {
            self::assertFalse($this->callPrivate($service, 'isDuckDuckGoHost', [$external]), $external . ' must not count as DuckDuckGo');
        }
    }

    // ---- Ranking and content enrichment ----

    /**
     * Search engines return pages that merely mention a word. The result that
     * matches every query term in its title must come first, or the model reads
     * the wrong page.
     */
    public function testRankingPrefersPagesThatMatchTheQuery(): void {
        $service = $this->service();
        $out = $this->callPrivate($service, 'rankResults', [[
            ['title' => 'Unrelated news', 'url' => 'https://a.example/', 'snippet' => 'nothing here'],
            ['title' => 'Python asyncio tutorial', 'url' => 'https://b.example/', 'snippet' => 'async guide'],
        ], 'python asyncio tutorial']);

        self::assertSame('https://b.example/', $out[0]['url']);
    }

    public function testRankingPushesLowValueHostsBack(): void {
        $service = $this->service();
        $out = $this->callPrivate($service, 'rankResults', [[
            ['title' => 'Asyncio guide', 'url' => 'https://www.w3schools.com/python/asyncio', 'snippet' => ''],
            ['title' => 'Asyncio guide', 'url' => 'https://realpython.com/asyncio', 'snippet' => ''],
        ], 'asyncio']);

        self::assertSame('https://realpython.com/asyncio', $out[0]['url']);
    }

    /** Equally relevant results must keep the search engine's own order. */
    public function testRankingIsStableForEqualScores(): void {
        $service = $this->service();
        $out = $this->callPrivate($service, 'rankResults', [[
            ['title' => 'First', 'url' => 'https://one.example/', 'snippet' => ''],
            ['title' => 'Second', 'url' => 'https://two.example/', 'snippet' => ''],
        ], 'zzz qqq']);

        self::assertSame(['https://one.example/', 'https://two.example/'], array_column($out, 'url'));
    }

    public function testQueryTermsIgnoreShortNoiseWords(): void {
        $service = $this->service();
        self::assertSame(['nextcloud', 'release'], $this->callPrivate($service, 'queryTerms', ['nextcloud 8 of the release']));
    }

    /**
     * Page text is what grounds an answer, so the extraction has to keep the
     * content and drop navigation, scripts and footers - and it must never leak
     * script bodies into the model context.
     */
    public function testPageTextExtractionKeepsContentAndDropsChrome(): void {
        $service = $this->service();
        $html = '<html><head><title>T</title><style>.x{}</style></head><body>'
            . '<nav>Menu</nav><article><h1>Heading</h1><p>Real&nbsp;content here.</p></article>'
            . '<script>alert(1)</script><footer>Footer</footer></body></html>';

        $text = $this->callPrivate($service, 'extractReadableText', [$html]);

        self::assertStringContainsString('Heading', $text);
        self::assertStringContainsString('Real content here.', $text);
        self::assertStringNotContainsString('Menu', $text);
        self::assertStringNotContainsString('alert(1)', $text);
        self::assertStringNotContainsString('Footer', $text);
    }

    /**
     * Wikipedia-style pages put a language picker above the article. Leaving it
     * in wastes the whole per-page character budget on navigation.
     */
    public function testWikiStyleLanguageChromeIsStrippedFromContent(): void {
        $service = $this->service();
        $html = '<html><body>'
            . '<div id="p-lang" class="vector-menu"><a href="#">24 languages</a> العربية Català</div>'
            . '<div class="mw-parser-output"><p>Nextcloud is a suite of client-server software.</p></div>'
            . '</body></html>';

        $text = $this->callPrivate($service, 'extractReadableText', [$html]);

        self::assertStringContainsString('suite of client-server software', $text);
        self::assertStringNotContainsString('24 languages', $text);
    }

    /**
     * A wrapper's class name must never delete the content inside it.
     *
     * "skin-vector-2022" sits on <html> and generic "page-header" wrappers are
     * everywhere, so without protecting the content root and its ancestors the
     * whole article disappeared and only the page title survived.
     */
    public function testChromeMarkersNeverDeleteTheContentRoot(): void {
        $service = $this->service();
        $html = '<html class="skin-vector-2022"><body>'
            . '<div class="page-header"><h1>Title</h1></div>'
            . '<main><div class="mw-parser-output"><p>The article body text.</p></div></main>'
            . '</body></html>';

        $text = $this->callPrivate($service, 'extractReadableText', [$html]);

        self::assertStringContainsString('The article body text.', $text);
    }

    /** Without ext-dom the extraction must still be safe and useful. */
    public function testRegexFallbackStripsScriptsAndKeepsText(): void {
        $service = $this->service();
        $text = $this->callPrivate($service, 'extractTextWithRegex', [
            '<html><body><script>bad()</script><nav>Menu</nav><p>Keep me.</p></body></html>',
        ]);

        self::assertStringContainsString('Keep me.', $text);
        self::assertStringNotContainsString('bad()', $text);
        self::assertStringNotContainsString('Menu', $text);
    }

    public function testBinaryDocumentsAreNotFetched(): void {
        $service = $this->service();
        self::assertTrue($this->callPrivate($service, 'isFetchablePage', ['https://example.org/guide.html']));
        foreach ([
            'https://example.org/paper.pdf',
            'https://example.org/picture.PNG',
            'https://example.org/archive.zip',
            'javascript:alert(1)',
        ] as $binary) {
            self::assertFalse($this->callPrivate($service, 'isFetchablePage', [$binary]), $binary . ' must not be fetched');
        }
    }

    /**
     * The same page often arrives from several endpoints; merging them must keep
     * the richest text and must still drop DuckDuckGo's own links.
     */
    public function testDuplicatesAreMergedAcrossEndpoints(): void {
        $service = $this->service();
        $out = $this->callPrivate($service, 'deduplicate', [[
            ['title' => 'A', 'url' => 'https://example.org/a', 'snippet' => 'short'],
            ['title' => 'A longer', 'url' => 'https://example.org/a', 'snippet' => 'a much longer and richer snippet'],
            ['title' => 'B', 'url' => 'https://example.org/b', 'snippet' => 'b'],
            ['title' => 'Ad', 'url' => 'https://duckduckgo.com/y.js?ad_domain=x', 'snippet' => 'ad'],
        ], 10]);

        self::assertCount(2, $out);
        self::assertSame('a much longer and richer snippet', $out[0]['snippet']);
    }

    public function testContentEnrichmentHonoursConfiguration(): void {
        $results = [['title' => 'T', 'url' => 'https://example.org/a', 'snippet' => 's']];

        $off = $this->service(['web_search_fetch_content' => '0']);
        $out = $this->callPrivate($off, 'enrichWithPageContent', [$results]);
        self::assertSame('', $out[0]['content'], 'page fetching can be switched off');

        self::assertSame(2000, $this->callPrivate($off, 'contentChars', []), 'default per-page text length');
        self::assertSame(200, $this->callPrivate($this->service(['web_search_content_chars' => '10']), 'contentChars', []));
        self::assertSame(8000, $this->callPrivate($this->service(['web_search_content_chars' => '99999']), 'contentChars', []));
    }

    /**
     * An empty result set must be an error, never a silent "success": the
     * model would otherwise tell the user the web had no answer.
     */
    public function testAnEmptyResultSetExplainsItself(): void {
        $service = $this->service();
        self::assertStringContainsString(
            'no results',
            $this->callPrivate($service, 'emptyResultError', ['searxng'])
        );

        // With a DuckDuckGo diagnostic recorded, that precise reason wins.
        $ref = new \ReflectionProperty($service, 'lastDuckDuckGoError');
        $ref->setValue($service, 'DuckDuckGo answered with its anti-bot page instead of results.');
        self::assertStringContainsString(
            'anti-bot',
            $this->callPrivate($service, 'emptyResultError', ['duckduckgo'])
        );
        // The diagnostic must not leak into other providers.
        self::assertStringContainsString(
            'no results',
            $this->callPrivate($service, 'emptyResultError', ['brave'])
        );
    }
}
