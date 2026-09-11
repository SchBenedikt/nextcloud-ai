<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Optional web search grounding (Issue #187).
 *
 * EVA is private by default: every answer is grounded in the user's own
 * indexed files. Enabling this service sends the user's query to a third
 * party, so it is *off by default*, admin-only, and never triggered
 * automatically - the chat model has to explicitly call the `web_search`
 * tool (see ToolPolicy and ActionExecutor).
 *
 * Providers are abstracted behind one normalized result shape so the rest of
 * the app never talks to a vendor API directly:
 *
 *  - `searxng`: a self-hosted SearxNG instance (no third party at all, the
 *    recommended option for privacy-conscious instances).
 *  - `brave`:  Brave Search API (hosted, requires an API key).
 *  - `tavily`: Tavily Search API (hosted, tuned for LLM grounding).
 */
class WebSearchService {
    public const PROVIDERS = ['duckduckgo', 'searxng', 'brave', 'tavily'];

    /** Providers that work without an API key. */
    public const FREE_PROVIDERS = ['duckduckgo'];

    /** Encrypted at app scope; write-only through the admin API. */
    public const API_KEY_KEY = 'web_search_api_key';

    private const BRAVE_ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    private const TAVILY_ENDPOINT = 'https://api.tavily.com/search';

    /** Snippets are fed to the model, so they are bounded to protect context. */
    private const MAX_SNIPPET_CHARS = 600;
    private const MAX_TITLE_CHARS = 200;
    /** Hard ceiling regardless of configuration. */
    private const ABSOLUTE_MAX_RESULTS = 20;
    /**
     * Page-content enrichment bounds. Fetching the real page text is what makes
     * an answer correct rather than a snippet paraphrase of a snippet, but it
     * costs one request per result, so both the number of pages and the text
     * taken from each are hard-capped independently of configuration.
     */
    private const ABSOLUTE_MAX_CONTENT_PAGES = 8;
    private const ABSOLUTE_MAX_CONTENT_CHARS = 8000;
    /** Fetched pages must stay well inside the search timeout to keep chat responsive. */
    private const CONTENT_FETCH_TIMEOUT_CEILING = 8;
    /**
     * Class/id fragments that mark navigation, promotion or social chrome. They
     * are matched inside the attribute value, so "site-header-main" counts too.
     * Kept deliberately narrow: an over-eager marker would delete real content as
     * often as it deletes a menu.
     */
    private const CHROME_MARKERS = [
        'sidebar', 'breadcrumb', 'breadcrumbs', 'mw-panel', 'mw-portlet', 'p-lang',
        'vector-dropdown', 'vector-menu', 'site-header', 'site-footer', 'page-header',
        'cookie', 'consent', 'newsletter', 'advert', 'social-share', 'share-buttons',
        'comments', 'skip-link', 'language-list', 'nav-menu', 'navbar',
        'table-of-contents', 'toc-list',
    ];

    /**
     * Filler words that appear in nearly every page. Keeping them in the term
     * set would score every result equally and flatten the ranking, so they are
     * dropped before scoring (English and German, which EVA answers in).
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'these', 'those',
        'are', 'was', 'were', 'has', 'have', 'had', 'not', 'but', 'you', 'your',
        'how', 'what', 'when', 'where', 'which', 'who', 'why', 'can', 'will',
        'into', 'over', 'its', 'about', 'more', 'most', 'does', 'did',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen',
        'einem', 'eines', 'und', 'oder', 'aber', 'für', 'mit', 'von', 'ist',
        'sind', 'war', 'wie', 'was', 'wer', 'wann', 'wo', 'dass', 'sich',
        'nicht', 'auch', 'bei', 'eine', 'einer', 'über', 'kann', 'wird',
    ];

    /**
     * Hosts that almost always add noise to an answer: social walls, content
     * farms and link aggregators. They are pushed to the back of the ranking
     * instead of being removed, so an explicit question about them still works.
     */
    private const LOW_VALUE_HOSTS = [
        'pinterest.', 'facebook.', 'instagram.', 'tiktok.', 'quora.',
        'answers.yahoo.', 'slideshare.', 'scribd.', 'w3schools.',
        'geeksforgeeks.org', 'javatpoint.', 'tutorialspoint.',
    ];

    /**
     * A real browser user agent. DuckDuckGo serves an anti-bot "anomaly"
     * interstitial to obviously scripted clients, so its plain-text endpoints
     * are always called with a browser-like identity (Issue #187).
     */
    private const BROWSER_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /**
     * Why the last DuckDuckGo attempt failed. DuckDuckGo only reports this
     * generically ("anomaly"), so the precise reason is kept here and surfaced
     * to the caller instead of silently returning zero results.
     */
    private ?string $lastDuckDuckGoError = null;

    public function __construct(
        private AppConfig $config,
        private IConfig $rawConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Forward the user identity to the internal AppConfig so per-user
     * settings (e.g. web_search_enabled, web_search_provider) are
     * resolved correctly.
     */
    public function setUserId(?string $userId): void {
        $this->config->setUserId($userId);
    }

    public function isEnabled(): bool {
        return $this->config->get('web_search_enabled') === '1';
    }

    public function provider(): string {
        $provider = $this->config->get('web_search_provider');
        // Fall back to the same provider the settings UI and AppConfig default
        // to, so an unset or stale value behaves exactly like a fresh install.
        return in_array($provider, self::PROVIDERS, true) ? $provider : 'duckduckgo';
    }

    /** True when the selected provider has everything it needs to run. */
    public function isConfigured(): bool {
        if (!$this->isEnabled()) {
            return false;
        }
        return match ($this->provider()) {
            'duckduckgo' => true,
            'searxng' => $this->endpoint() !== '',
            'brave', 'tavily' => $this->hasApiKey(),
            default => false,
        };
    }

    /** Configured SearxNG base URL, normalized without a trailing slash. */
    public function endpoint(): string {
        return rtrim(trim($this->config->get('web_search_url')), '/');
    }

    public function hasApiKey(): bool {
        return (string)$this->rawConfig->getAppValue(AppConfig::APP, self::API_KEY_KEY, '') !== '';
    }

    /**
     * Store the hosted provider API key encrypted at app scope. An empty value
     * removes it. The key is never returned to any client.
     */
    public function saveApiKey(string $key): void {
        $key = trim($key);
        if ($key === '') {
            $this->rawConfig->deleteAppValue(AppConfig::APP, self::API_KEY_KEY);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{8,256}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid web search API key format');
        }
        $this->rawConfig->setAppValue(AppConfig::APP, self::API_KEY_KEY, $this->crypto->encrypt($key));
    }

    private function apiKey(): string {
        $stored = (string)$this->rawConfig->getAppValue(AppConfig::APP, self::API_KEY_KEY, '');
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable $e) {
            throw new ProviderException('The web search API key cannot be decrypted; save it again');
        }
    }

    public function maxResults(): int {
        $configured = $this->config->getInt('web_search_max_results', 8);
        return max(1, min(self::ABSOLUTE_MAX_RESULTS, $configured));
    }

    private function timeout(): int {
        return max(1, min(30, $this->config->getInt('web_search_timeout', 10)));
    }

    private function safeSearch(): bool {
        return $this->config->get('web_search_safe_search') !== '0';
    }

    /**
     * Run one web search.
     *
     * @return array{ok:bool,provider:string,results:list<array{title:string,url:string,snippet:string}>,error:?string}
     */
    public function search(string $query, ?int $limit = null): array {
        $query = trim($query);
        $provider = $this->provider();
        if ($query === '') {
            return ['ok' => false, 'provider' => $provider, 'results' => [], 'error' => 'A search query is required.'];
        }
        if (!$this->isEnabled()) {
            return ['ok' => false, 'provider' => $provider, 'results' => [], 'error' => 'Web search is disabled by the administrator.'];
        }
        $count = max(1, min(self::ABSOLUTE_MAX_RESULTS, $limit ?? $this->maxResults()));
        $this->lastDuckDuckGoError = null;

        try {
            $results = match ($provider) {
                'duckduckgo' => $this->searchDuckDuckGo($query, $count),
                'searxng' => $this->searchSearxng($query, $count),
                'brave' => $this->searchBrave($query, $count),
                'tavily' => $this->searchTavily($query, $count),
                default => throw new ProviderException('Unsupported web search provider: ' . $provider),
            };
        } catch (ProviderException $e) {
            return ['ok' => false, 'provider' => $provider, 'results' => [], 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: web search failed', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);
            return ['ok' => false, 'provider' => $provider, 'results' => [], 'error' => 'Web search failed.'];
        }

        // An empty result set must never be reported as success: the model
        // would then claim the web had no answer. Say why instead.
        if ($results === []) {
            return ['ok' => false, 'provider' => $provider, 'results' => [], 'error' => $this->emptyResultError($provider)];
        }

        // Order by real relevance against the query, keep the best `$count`,
        // then read those pages so the model works from their actual content
        // instead of a search-engine teaser.
        $results = $this->rankResults($results, $query);
        $results = array_slice($results, 0, $count);
        $results = $this->enrichWithPageContent($results);

        return ['ok' => true, 'provider' => $provider, 'results' => $results, 'error' => null];
    }

    /**
     * Explain why a provider produced no results. For DuckDuckGo the concrete
     * cause (anti-bot page, unreachable endpoint) is much more useful than a
     * generic "no results", because the fix differs entirely.
     */
    private function emptyResultError(string $provider): string {
        if ($provider === 'duckduckgo' && $this->lastDuckDuckGoError !== null) {
            return $this->lastDuckDuckGoError;
        }
        return 'The web search provider returned no results for this query.';
    }

    /**
     * Order results by how well they answer the query.
     *
     * Search engines already rank, but their order mixes in pages that merely
     * mention a word. Scoring term matches in the title, the snippet and the URL
     * path moves the pages that really cover the topic to the top and pushes
     * known low-value hosts to the back. The sort is stable, so equally relevant
     * results keep the engine's own order.
     *
     * @param list<array{title:string,url:string,snippet:string}> $results
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function rankResults(array $results, string $query): array {
        $terms = $this->queryTerms($query);
        if ($terms === [] || count($results) < 2) {
            return array_values($results);
        }

        $scored = [];
        foreach ($results as $index => $result) {
            $title = mb_strtolower((string)($result['title'] ?? ''));
            $snippet = mb_strtolower((string)($result['snippet'] ?? ''));
            $path = mb_strtolower((string)(parse_url((string)($result['url'] ?? ''), PHP_URL_PATH) ?? ''));

            $score = 0;
            foreach ($terms as $term) {
                if (mb_strpos($title, $term) !== false) {
                    $score += 3;
                }
                if (mb_strpos($snippet, $term) !== false) {
                    $score += 1;
                }
                if (mb_strpos($path, $term) !== false) {
                    $score += 1;
                }
            }
            // Covering every term in the title is the strongest signal that a
            // page is about the topic rather than a passing mention.
            if ($score >= 3 * count($terms)) {
                $score += 5;
            }
            if ($this->isLowValueHost((string)($result['url'] ?? ''))) {
                $score -= 6;
            }
            $scored[] = ['score' => $score, 'index' => $index, 'result' => $result];
        }

        usort($scored, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']);
        });

        return array_values(array_map(static fn(array $row): array => $row['result'], $scored));
    }

    /**
     * Split a query into comparable terms. Very short tokens are dropped because
     * they appear in almost every page and would flatten the ranking.
     *
     * @return list<string>
     */
    private function queryTerms(string $query): array {
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $terms = array_filter($terms, static function (string $term): bool {
            return mb_strlen($term) >= 3 && !in_array($term, self::STOP_WORDS, true);
        });
        return array_values(array_unique($terms));
    }

    private function isLowValueHost(string $url): bool {
        $host = mb_strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach (self::LOW_VALUE_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace the search-engine teaser with the readable text of each page.
     *
     * This is the difference between "a page about X exists" and knowing what it
     * says. Pages are fetched in parallel and bounded by count, per-page size and
     * a short timeout; a page that cannot be read keeps its snippet instead of
     * failing the whole search, so the model always gets the best text available.
     *
     * @param list<array{title:string,url:string,snippet:string}> $results
     * @return list<array{title:string,url:string,snippet:string,content:string}>
     */
    private function enrichWithPageContent(array $results): array {
        $enriched = [];
        foreach ($results as $result) {
            $enriched[] = $result + ['content' => ''];
        }
        if (!$this->fetchContent() || $enriched === []) {
            return $enriched;
        }

        $limit = min(count($enriched), self::ABSOLUTE_MAX_CONTENT_PAGES);
        $targets = [];
        for ($index = 0; $index < $limit; $index++) {
            $url = (string)($enriched[$index]['url'] ?? '');
            if ($url !== '' && $this->isFetchablePage($url)) {
                $targets[$index] = $url;
            }
        }
        if ($targets === []) {
            return $enriched;
        }

        $maxChars = $this->contentChars();
        foreach ($this->fetchMany($targets) as $index => $html) {
            $text = $this->extractReadableText($html);
            if ($text !== '') {
                $enriched[$index]['content'] = $this->clamp($text, $maxChars);
            }
        }

        return $enriched;
    }

    /**
     * Only fetch URLs that can plausibly yield readable text. Binary documents
     * and media would waste a request and produce nothing useful.
     */
    private function isFetchablePage(string $url): bool {
        if (!$this->isSafeHttpUrl($url)) {
            return false;
        }
        $path = mb_strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        foreach ([
            '.pdf', '.zip', '.gz', '.tar', '.rar', '.7z', '.dmg', '.exe',
            '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico',
            '.mp3', '.mp4', '.avi', '.mov', '.mkv', '.webm',
            '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
        ] as $extension) {
            if (str_ends_with($path, $extension)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fetch several URLs at once. Parallel requests keep a multi-page search
     * inside one short timeout instead of summing one timeout per page, which is
     * what makes enriching every result affordable in a chat request.
     *
     * @param array<int,string> $urls index => url
     * @return array<int,string> index => body, only for successful fetches
     */
    private function fetchMany(array $urls): array {
        $multi = curl_multi_init();
        $timeout = max(3, min(self::CONTENT_FETCH_TIMEOUT_CEILING, $this->timeout()));
        $handles = [];
        foreach ($urls as $index => $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => self::BROWSER_USER_AGENT,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,de;q=0.8',
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$index] = $ch;
        }

        $bodies = [];
        if ($handles === []) {
            curl_multi_close($multi);
            return $bodies;
        }

        $running = 0;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $index => $ch) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = curl_multi_getcontent($ch);
            if ($httpCode >= 200 && $httpCode < 300 && is_string($body) && $body !== '') {
                $bodies[$index] = $body;
            }
            curl_multi_remove_handle($multi, $ch);
            // No curl_close(): handles are freed automatically since PHP 8.0 and
            // the call is deprecated in 8.5.
        }
        curl_multi_close($multi);

        return $bodies;
    }

    /**
     * Turn a fetched HTML page into plain readable text.
     *
     * Scripts, styles, navigation and forms carry no answer text, and leaving
     * them in would push the real content out of the context window, so they are
     * removed before the markup is stripped. A marked main container wins over
     * the whole body, which drops menus and sidebars.
     */
    private function extractReadableText(string $html): string {
        if ($html === '') {
            return '';
        }
        if (class_exists(\DOMDocument::class)) {
            $text = $this->extractTextWithDom($html);
            if ($text !== '') {
                return $text;
            }
        }
        return $this->extractTextWithRegex($html);
    }

    /**
     * Read a page with a real DOM parse.
     *
     * Patterns cannot tell a layout wrapper <div> from a navigation <div>, and
     * getting that wrong either keeps menus or deletes the article. The DOM pass
     * removes the element types and the class/id keywords that never carry answer
     * text, prefers a marked main container, and only then reads the text.
     */
    private function extractTextWithDom(string $html): string {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return '';
        }

        $xpath = new \DOMXPath($document);
        $root = $this->findContentRoot($xpath);
        if ($root === null) {
            return '';
        }

        // The content and everything that contains it are protected: an outer
        // element's class name says nothing about the text inside it. Without
        // this, a class such as "skin-vector-2022" on <html> or a generic
        // "page-header" wrapper would delete the whole article.
        $protected = [];
        for ($node = $root; $node !== null; $node = $node->parentNode) {
            $protected[] = $node;
        }

        // Element types that never carry answer text.
        $this->removeNodes($xpath->query(
            '//script|//style|//noscript|//svg|//template|//iframe|//form'
            . '|//nav|//footer|//header|//aside|//button|//select|//option|//label|//input'
        ), $protected);

        // Chrome by class/id: sidebars, cookie banners and language pickers are
        // plain <div>s, which is exactly what a tag-based rule misses.
        $conditions = [];
        foreach (self::CHROME_MARKERS as $marker) {
            $literal = '"' . $marker . '"';
            $conditions[] = 'contains(@class, ' . $literal . ')';
            $conditions[] = 'contains(@id, ' . $literal . ')';
        }
        $this->removeNodes($xpath->query('//*[' . implode(' or ', $conditions) . ']'), $protected);

        return $this->normaliseText((string)$root->textContent);
    }

    /**
     * The element that holds the page's own content.
     *
     * Among the candidates the one with the most text wins, because a page can
     * carry several "content" containers (a teaser box, a promo sidebar, the
     * article) and only the article carries the substance. Depth breaks ties, so
     * the more specific container wins when two of them hold the same text. The
     * body is only a fallback when the page marks no container at all.
     */
    private function findContentRoot(\DOMXPath $xpath): ?\DOMNode {
        $containers = $xpath->query(
            '//article|//main|//*[@role="main"]|//*[@id="mw-content-text"]'
            . '|//*[contains(@class, "mw-parser-output")]'
            . '|//*[contains(@class, "post-content")]|//*[contains(@class, "entry-content")]'
            . '|//*[contains(@class, "article-body")]|//*[contains(@class, "markdown-body")]'
        );
        $body = $xpath->query('//body')->item(0);
        if ($containers === false || $containers->length === 0) {
            return $body;
        }

        $best = null;
        $bestLength = -1;
        $bestDepth = -1;
        foreach ($containers as $node) {
            $length = mb_strlen($node->textContent);
            $depth = 0;
            for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
                $depth++;
            }
            if ($length > $bestLength || ($length === $bestLength && $depth > $bestDepth)) {
                $bestLength = $length;
                $bestDepth = $depth;
                $best = $node;
            }
        }

        // A candidate that holds almost nothing is a teaser, not the article;
        // the body is a better starting point when the page has real text.
        if ($best !== null && $body !== null) {
            $bodyLength = mb_strlen($body->textContent);
            if ($bestLength < 200 && $bodyLength > $bestLength) {
                return $body;
            }
        }

        return $best;
    }

    /**
     * Remove a node list from its document. The list is copied first because
     * removing while iterating a live node list skips every other node, and
     * protected nodes (the content root and its ancestors) are never touched.
     *
     * @param \DOMNodeList|false $nodes
     * @param list<\DOMNode> $protected
     */
    private function removeNodes($nodes, array $protected = []): void {
        if ($nodes === false || $nodes->length === 0) {
            return;
        }
        $remove = [];
        foreach ($nodes as $node) {
            if (in_array($node, $protected, true)) {
                continue;
            }
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Last-resort extraction for installs without ext-dom. It keeps the page
     * text, but cannot tell a wrapper element from a navigation element.
     */
    private function extractTextWithRegex(string $html): string {
        $text = preg_replace(
            '/<(script|style|noscript|svg|nav|footer|form|iframe|template)\b[^>]*>.*?<\/\1>/is',
            ' ',
            $html
        ) ?? $html;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        if (preg_match('/<(article|main)\b[^>]*>(.*?)<\/\1>/is', $text, $match)) {
            $text = $match[2];
        }
        return $this->normaliseText(strip_tags($text));
    }

    /**
     * Collapse a page's whitespace into single spaces. U+00A0 arrives from
     * &nbsp; and is not matched by \s, so it is normalised explicitly or the
     * text keeps invisible gaps.
     */
    private function normaliseText(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
    }

    /** Read each result page and include its text, unless disabled. */
    private function fetchContent(): bool {
        return $this->config->get('web_search_fetch_content') !== '0';
    }

    private function contentChars(): int {
        $configured = $this->config->getInt('web_search_content_chars', 2000);
        return max(200, min(self::ABSOLUTE_MAX_CONTENT_CHARS, $configured));
    }

    /**
     * DuckDuckGo — no API key required.
     *
     * DuckDuckGo has no official free web-search API, so EVA uses the same
     * plain-text endpoints a browser does, in order of how much real content
     * they return. Every attempt records a diagnostic reason in
     * $lastDuckDuckGoError, so an anti-bot response is reported precisely
     * instead of silently returning zero results (Issue #187).
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchDuckDuckGo(string $query, int $count): array {
        // Every endpoint is queried and the results are merged, instead of
        // stopping at the first one that answers. That is what makes the result
        // set broad: the HTML page carries the web hits, the lightweight page
        // adds ones the HTML page dropped, and Instant Answers contributes the
        // encyclopedic entry the plain result list often lacks.
        $collected = [];

        // Strategy 1: the HTML endpoint. This is the only endpoint that returns
        // title, URL and snippet together for arbitrary queries.
        $html = $this->fetchDuckDuckGoHtml('https://html.duckduckgo.com/html/', [
            'q' => $query,
            'kl' => 'wt-wt',
        ]);
        if ($html !== null) {
            $found = $this->parseDuckDuckGoResults($html, self::ABSOLUTE_MAX_RESULTS);
            if ($found === []) {
                $found = $this->parseDuckDuckGoLinks($html, self::ABSOLUTE_MAX_RESULTS);
            }
            $collected = array_merge($collected, $found);
        }

        // Strategy 2: the lightweight endpoint. It is a plain HTML form, so the
        // query belongs in the POST body exactly like in a browser.
        $html = $this->fetchDuckDuckGoHtml('https://lite.duckduckgo.com/lite/', [
            'q' => $query,
            'kl' => 'wt-wt',
        ], true);
        if ($html !== null) {
            $collected = array_merge($collected, $this->parseDuckDuckGoLiteResults($html, self::ABSOLUTE_MAX_RESULTS));
        }

        // Strategy 3: the documented Instant Answers JSON API. It answers from
        // every server IP but only covers known entities.
        $collected = array_merge($collected, $this->searchDuckDuckGoInstant($query, self::ABSOLUTE_MAX_RESULTS));

        return $this->deduplicate($collected, $count);
    }

    /**
     * Collapse duplicate URLs across providers and endpoints, keeping the entry
     * with the richest text so a merged result is never worse than the best of
     * its sources.
     *
     * @param list<array{title:string,url:string,snippet:string}> $results
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function deduplicate(array $results, int $count): array {
        $byUrl = [];
        foreach ($results as $result) {
            $url = (string)($result['url'] ?? '');
            if ($url === '' || !$this->isSafeHttpUrl($url) || $this->isDuckDuckGoHost($url)) {
                continue;
            }
            if (!isset($byUrl[$url])) {
                $byUrl[$url] = $result;
                continue;
            }
            if (mb_strlen((string)($result['snippet'] ?? '')) > mb_strlen((string)($byUrl[$url]['snippet'] ?? ''))) {
                $byUrl[$url]['snippet'] = $result['snippet'];
            }
            if ((string)($byUrl[$url]['title'] ?? '') === '') {
                $byUrl[$url]['title'] = $result['title'];
            }
        }
        return array_slice(array_values($byUrl), 0, $count);
    }

    /**
     * Fetch one DuckDuckGo endpoint with a browser-like request.
     *
     * Returns null when DuckDuckGo served its anti-bot interstitial or the
     * request failed, so the caller can try the next strategy. The reason is
     * kept in $lastDuckDuckGoError.
     *
     * @param array<string,string> $fields
     */
    private function fetchDuckDuckGoHtml(string $endpoint, array $fields, bool $post = false): ?string {
        $headers = $this->browserHeaders($endpoint);
        try {
            $body = $post
                ? $this->httpPost($endpoint, http_build_query($fields), $headers)
                : $this->httpGet($endpoint . '?' . http_build_query($fields), $headers);
        } catch (\Throwable $e) {
            $this->lastDuckDuckGoError = 'The DuckDuckGo endpoint is unreachable: ' . $e->getMessage();
            $this->logger->debug('eva_ai: DuckDuckGo request failed: ' . $e->getMessage());
            return null;
        }

        if ($this->isDuckDuckGoAnomaly($body)) {
            $this->lastDuckDuckGoError = 'DuckDuckGo answered with its anti-bot page instead of results. '
                . 'DuckDuckGo blocks many server and data-center IP ranges; a self-hosted SearxNG URL '
                . 'or a Brave/Tavily API key is more reliable on a server.';
            $this->logger->info('eva_ai: DuckDuckGo returned the anti-bot interstitial');
            return null;
        }

        return $body;
    }

    /**
     * DuckDuckGo's own hosts. Its result pages interleave sponsored hits
     * (duckduckgo.com/y.js?ad_domain=…) and internal aggregation links
     * (duckduckgo.com/c/…) with the real web results. Those are tracking
     * redirects rather than sources, so they are dropped: otherwise they rank
     * first in document order and the model would cite ad redirects.
     */
    private function isDuckDuckGoHost(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        return $host === 'duckduckgo.com' || str_ends_with($host, '.duckduckgo.com');
    }

    /**
     * Detect DuckDuckGo's "anomaly" interstitial. It carries a JS challenge and
     * no result markup at all, so its presence is a reliable rejection signal.
     */
    private function isDuckDuckGoAnomaly(string $body): bool {
        if (stripos($body, 'anomaly.js') !== false || stripos($body, 'anomaly-modal') !== false) {
            return true;
        }
        // Any result markup means the query was answered normally.
        if (stripos($body, 'result__a') !== false
            || stripos($body, 'result-link') !== false
            || stripos($body, 'result__snippet') !== false) {
            return false;
        }
        return stripos($body, 'captcha') !== false
            || stripos($body, '/anomaly') !== false
            || stripos($body, 'challenge') !== false;
    }

    /**
     * Headers a real browser sends for a top-level navigation. Without them
     * DuckDuckGo classifies the request as a bot and serves the interstitial.
     *
     * @return list<string>
     */
    private function browserHeaders(string $endpoint = ''): array {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,de;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'DNT: 1',
            'Connection: keep-alive',
        ];
        $parts = parse_url($endpoint);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $headers[] = 'Origin: ' . $parts['scheme'] . '://' . $parts['host'];
            $headers[] = 'Referer: ' . $endpoint;
        }
        return $headers;
    }

    /**
     * Parse the lightweight endpoint. Its markup uses single-quoted classes,
     * so it is matched structurally instead of assuming an attribute order.
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function parseDuckDuckGoLiteResults(string $html, int $count): array {
        $links = [];
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (stripos($match[1], 'result-link') === false) {
                    continue;
                }
                if (!preg_match('/href\s*=\s*["\']([^"\']*)["\']/i', $match[1], $href)) {
                    continue;
                }
                $url = $this->extractDuckDuckGoUrl(html_entity_decode(trim($href[1]), ENT_QUOTES, 'UTF-8'));
                if (!$this->isSafeHttpUrl($url) || $this->isDuckDuckGoHost($url)) {
                    continue;
                }
                $title = $this->clamp(strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8')), self::MAX_TITLE_CHARS);
                if ($title === '') {
                    continue;
                }
                $links[] = ['title' => $title, 'url' => $url];
            }
        }

        // Snippets live in separate table cells; pair them by position.
        $snippets = [];
        if (preg_match_all('/class=["\']result-snippet["\'][^>]*>(.*?)<\/td>/si', $html, $snippetMatches, PREG_SET_ORDER)) {
            foreach ($snippetMatches as $snippetMatch) {
                $snippets[] = $this->clamp(strip_tags(html_entity_decode($snippetMatch[1], ENT_QUOTES, 'UTF-8')), self::MAX_SNIPPET_CHARS);
            }
        }

        $results = [];
        foreach ($links as $index => $link) {
            $results[] = [
                'title' => $link['title'],
                'url' => $link['url'],
                'snippet' => $snippets[$index] ?? '',
            ];
            if (count($results) >= $count) {
                break;
            }
        }
        return $results;
    }

    /**
     * Parse the HTML endpoint. Real DuckDuckGo markup places `rel` before
     * `class` and `href` after it, so the anchor is matched structurally
     * instead of assuming one attribute order (Issue #187).
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function parseDuckDuckGoResults(string $html, int $count): array {
        $links = [];
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (stripos($match[1], 'result__a') === false) {
                    continue;
                }
                if (!preg_match('/href\s*=\s*["\']([^"\']*)["\']/i', $match[1], $href)) {
                    continue;
                }
                $url = $this->extractDuckDuckGoUrl(html_entity_decode(trim($href[1]), ENT_QUOTES, 'UTF-8'));
                if (!$this->isSafeHttpUrl($url) || $this->isDuckDuckGoHost($url)) {
                    continue;
                }
                $title = $this->clamp(strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8')), self::MAX_TITLE_CHARS);
                $links[] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
            }
        }

        // Snippets are separate anchors; pair them by document order.
        $snippets = [];
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/si', $html, $snippetMatches, PREG_SET_ORDER)) {
            foreach ($snippetMatches as $match) {
                if (stripos($match[1], 'result__snippet') === false) {
                    continue;
                }
                $snippets[] = $this->clamp(strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8')), self::MAX_SNIPPET_CHARS);
            }
        }

        $results = [];
        foreach ($links as $index => $link) {
            $results[] = [
                'title' => $link['title'],
                'url' => $link['url'],
                'snippet' => $snippets[$index] ?? '',
            ];
            if (count($results) >= $count) {
                break;
            }
        }
        return $results;
    }

    /**
     * Strategy 2: Broader link extraction from DuckDuckGo HTML.
     * Parses any DuckDuckGo redirect links and uses surrounding text as snippets.
     */
    private function parseDuckDuckGoLinks(string $html, int $count): array {
        $results = [];

        // Find all DuckDuckGo redirect links (contain "uddg=" or are direct URLs).
        if (preg_match_all(
            '/<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>/si',
            $html,
            $allLinks,
            PREG_SET_ORDER
        )) {
            foreach ($allLinks as $link) {
                $rawUrl = trim($link[1]);
                $linkText = trim(strip_tags(html_entity_decode($link[2], ENT_QUOTES, 'UTF-8')));
                $realUrl = $this->extractDuckDuckGoUrl($rawUrl);
                if (!$this->isSafeHttpUrl($realUrl)) {
                    continue;
                }
                // Skip DuckDuckGo internal links (navigation, settings, ads).
                if ($this->isDuckDuckGoHost($realUrl)) {
                    continue;
                }
                // Skip very short link text (likely icons or navigation).
                if (mb_strlen($linkText) < 3) {
                    continue;
                }
                $results[] = [
                    'title' => $this->clamp($linkText, self::MAX_TITLE_CHARS),
                    'url' => $realUrl,
                    'snippet' => '',
                ];
                if (count($results) >= $count) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Extract the real destination URL from a DuckDuckGo redirect URL.
     * DuckDuckGo wraps links like: //duckduckgo.com/l/?uddg=ENCODED_URL&rut=...
     */
    private function extractDuckDuckGoUrl(string $rawUrl): string {
        if (preg_match('/uddg=([^&]+)/', $rawUrl, $m)) {
            $decoded = urldecode($m[1]);
            if ($this->isSafeHttpUrl($decoded)) {
                return $decoded;
            }
        }
        // Direct URL (not wrapped through redirect).
        if ($this->isSafeHttpUrl($rawUrl)) {
            return $rawUrl;
        }
        return '';
    }

    /**
     * Fallback: DuckDuckGo instant answers JSON API.
     * Returns related topics and abstract when HTML scraping found nothing.
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchDuckDuckGoInstant(string $query, int $count): array {
        $url = 'https://api.duckduckgo.com/?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'no_html' => '1',
            'skip_disambig' => '1',
        ]);

        $body = $this->httpGet($url, []);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }

        $results = [];

        // Direct answer (Answer field from DuckDuckGo Instant Answers).
        $answer = trim((string)($json['Answer'] ?? ''));
        $answerUrl = (string)($json['AnswerURL'] ?? '');
        if ($answer !== '' && $this->isSafeHttpUrl($answerUrl)) {
            $results[] = [
                'title' => $this->clamp((string)($json['Heading'] ?? $query), self::MAX_TITLE_CHARS),
                'url' => $answerUrl,
                'snippet' => $this->clamp($answer, self::MAX_SNIPPET_CHARS),
            ];
        }

        // Abstract (encyclopedic summary).
        $abstract = trim((string)($json['AbstractText'] ?? ''));
        $abstractUrl = (string)($json['AbstractURL'] ?? '');
        $abstractSource = (string)($json['AbstractSource'] ?? '');
        if ($abstract !== '' && $this->isSafeHttpUrl($abstractUrl)) {
            $title = $this->clamp((string)($json['Heading'] ?? $query), self::MAX_TITLE_CHARS);
            if ($abstractSource !== '') {
                $title .= ' (' . $this->clamp($abstractSource, 40) . ')';
            }
            $results[] = [
                'title' => $title,
                'url' => $abstractUrl,
                'snippet' => $this->clamp($abstract, self::MAX_SNIPPET_CHARS),
            ];
        }

        // Infobox data: extract key facts if available.
        $infobox = $json['Infobox'] ?? null;
        if (is_array($infobox) && isset($infobox['content']) && is_array($infobox['content'])) {
            foreach ($infobox['content'] as $field) {
                if (!is_array($field)) continue;
                $fieldLabel = trim((string)($field['label'] ?? ''));
                $fieldValue = trim((string)($field['value'] ?? ''));
                $fieldUrl = (string)($field['data_type'] === 'url' ? $field['value'] : '');
                if ($fieldValue !== '' && $fieldLabel !== '') {
                    $snippet = $fieldLabel . ': ' . $fieldValue;
                    $results[] = [
                        'title' => $this->clamp($fieldLabel . ' - ' . ($json['Heading'] ?? $query), self::MAX_TITLE_CHARS),
                        'url' => $this->isSafeHttpUrl($fieldUrl) ? $fieldUrl : ($abstractUrl !== ''
                            ? $abstractUrl : 'https://duckduckgo.com/?q=' . urlencode($query)),
                        'snippet' => $this->clamp($snippet, self::MAX_SNIPPET_CHARS),
                    ];
                }
            }
        }

        // Related topics — flat list and grouped sub-topics.
        $topics = $json['RelatedTopics'] ?? [];
        if (is_array($topics)) {
            foreach ($topics as $topic) {
                if (!is_array($topic)) {
                    continue;
                }
                $subTopics = $topic['Topics'] ?? ($topic['SubTopics'] ?? []);
                if (is_array($subTopics) && count($subTopics) > 0) {
                    foreach ($subTopics as $sub) {
                        if (!is_array($sub)) continue;
                        $topicUrl = (string)($sub['FirstURL'] ?? '');
                        $topicText = trim((string)($sub['Text'] ?? ''));
                        if ($topicText !== '' && $this->isSafeHttpUrl($topicUrl)) {
                            $results[] = [
                                'title' => $this->clamp($topicText, self::MAX_TITLE_CHARS),
                                'url' => $topicUrl,
                                'snippet' => $this->clamp($topicText, self::MAX_SNIPPET_CHARS),
                            ];
                        }
                    }
                } else {
                    $topicUrl = (string)($topic['FirstURL'] ?? '');
                    $topicText = trim((string)($topic['Text'] ?? ''));
                    if ($topicText !== '' && $this->isSafeHttpUrl($topicUrl)) {
                        $results[] = [
                            'title' => $this->clamp($topicText, self::MAX_TITLE_CHARS),
                            'url' => $topicUrl,
                            'snippet' => $this->clamp($topicText, self::MAX_SNIPPET_CHARS),
                        ];
                    }
                }
                if (count($results) >= $count) {
                    break;
                }
            }
        }

        // Deduplicate by URL and drop DuckDuckGo's own aggregation links, so
        // only real external sources reach the model.
        $seen = [];
        $unique = [];
        foreach ($results as $r) {
            $key = $r['url'];
            if (isset($seen[$key]) || $this->isDuckDuckGoHost($key)) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $r;
        }

        return array_slice($unique, 0, $count);
    }

    /** @return list<array{title:string,url:string,snippet:string}> */
    private function searchSearxng(string $query, int $count): array {
        $base = $this->endpoint();
        if ($base === '') {
            throw new ProviderException('No SearxNG URL configured.');
        }
        $url = $base . '/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'language' => 'all',
            'safesearch' => $this->safeSearch() ? 1 : 0,
        ]);
        $body = $this->httpGet($url, []);
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            throw new ProviderException('The SearxNG instance did not return JSON results. Enable the JSON output format there.');
        }
        return $this->normalize($json['results'], 'title', 'url', 'content', $count);
    }

    /** @return list<array{title:string,url:string,snippet:string}> */
    private function searchBrave(string $query, int $count): array {
        $key = $this->apiKey();
        if ($key === '') {
            throw new ProviderException('No Brave Search API key configured.');
        }
        $url = self::BRAVE_ENDPOINT . '?' . http_build_query([
            'q' => $query,
            'count' => $count,
            'safesearch' => $this->safeSearch() ? 'strict' : 'off',
        ]);
        $body = $this->httpGet($url, [
            'Accept: application/json',
            'X-Subscription-Token: ' . $key,
        ]);
        $json = json_decode($body, true);
        $rows = $json['web']['results'] ?? null;
        if (!is_array($rows)) {
            throw new ProviderException('The Brave Search API returned an unexpected response.');
        }
        return $this->normalize($rows, 'title', 'url', 'description', $count);
    }

    /** @return list<array{title:string,url:string,snippet:string}> */
    private function searchTavily(string $query, int $count): array {
        $key = $this->apiKey();
        if ($key === '') {
            throw new ProviderException('No Tavily API key configured.');
        }
        $payload = json_encode([
            'api_key' => $key,
            'query' => $query,
            'max_results' => $count,
            'search_depth' => 'basic',
            'include_answer' => false,
        ], JSON_THROW_ON_ERROR);
        $body = $this->httpPost(self::TAVILY_ENDPOINT, $payload, ['Content-Type: application/json']);
        $json = json_decode($body, true);
        $rows = $json['results'] ?? null;
        if (!is_array($rows)) {
            throw new ProviderException('The Tavily API returned an unexpected response.');
        }
        return $this->normalize($rows, 'title', 'url', 'content', $count);
    }

    /**
     * Map vendor rows onto one bounded, safe shape. Non-http(s) URLs are
     * dropped so a hostile provider response can never become a javascript:
     * or file: link in the chat answer.
     *
     * @param array<mixed> $rows
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function normalize(array $rows, string $titleKey, string $urlKey, string $snippetKey, int $count): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string)($row[$urlKey] ?? ''));
            if (!$this->isSafeHttpUrl($url)) {
                continue;
            }
            $title = $this->clamp((string)($row[$titleKey] ?? ''), self::MAX_TITLE_CHARS);
            $snippet = $this->clamp((string)($row[$snippetKey] ?? ''), self::MAX_SNIPPET_CHARS);
            $out[] = [
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
                'snippet' => $snippet,
            ];
            if (count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    private function isSafeHttpUrl(string $url): bool {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (trim((string)($parts['host'] ?? '')) === '') {
            return false;
        }
        // Credentials in the URL would leak into the rendered link.
        return !isset($parts['user']) && !isset($parts['pass']);
    }

    private function clamp(string $value, int $max): string {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }

    /** @param list<string> $headers */
    private function httpGet(string $url, array $headers): string {
        return $this->request('GET', $url, null, $headers);
    }

    /** @param list<string> $headers */
    private function httpPost(string $url, string $payload, array $headers): string {
        return $this->request('POST', $url, $payload, $headers);
    }

    /** @param list<string> $headers */
    private function request(string $method, string $url, ?string $payload, array $headers): string {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ProviderException('Could not initialize the HTTP client.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout()),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => self::BROWSER_USER_AGENT,
            // Transparently accept and decode gzip/deflate. DuckDuckGo and most
            // other search front-ends treat a client that cannot handle
            // compression as a bot and answer with a challenge page.
            CURLOPT_ENCODING => '',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string)$payload;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        // No curl_close(): the handle is freed automatically (PHP 8.0+) and the
        // function is deprecated in PHP 8.5.

        if ($body === false) {
            throw new ProviderException('The web search endpoint is unreachable: ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderException('The web search provider rejected the API key.');
        }
        if ($status === 429) {
            throw new ProviderException('The web search provider rate limit was reached. Try again later.');
        }
        if ($status < 200 || $status >= 300) {
            throw new ProviderException('The web search provider returned HTTP ' . $status . '.');
        }
        return (string)$body;
    }
}
