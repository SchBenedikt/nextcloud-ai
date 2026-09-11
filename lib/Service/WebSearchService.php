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
    private const ABSOLUTE_MAX_RESULTS = 10;

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
        return in_array($provider, self::PROVIDERS, true) ? $provider : 'searxng';
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
        $configured = $this->config->getInt('web_search_max_results', 5);
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

        return ['ok' => true, 'provider' => $provider, 'results' => $results, 'error' => null];
    }

    /**
     * DuckDuckGo instant answers — no API key required.
     *
     * Uses the DuckDuckGo HTML search page which returns results without
     * any authentication. This is the recommended free provider for privacy-
     * conscious instances that do not want to host SearxNG.
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchDuckDuckGo(string $query, int $count): array {
        // Strategy 1 (primary): DuckDuckGo Instant Answers JSON API.
        // Works reliably from any server without authentication. Provides
        // factual summaries, related topics and source URLs for encyclopedic
        // queries. This is the most robust strategy for server-side usage.
        $results = $this->searchDuckDuckGoInstant($query, $count);
        if ($results !== []) {
            return array_slice($results, 0, $count);
        }

        // Strategy 2: DuckDuckGo HTML endpoint (may be blocked by CAPTCHA
        // on some server IPs). We attempt it anyway because it works from
        // many hosting environments and returns actual web search results.
        $url = 'https://html.duckduckgo.com/html/?' . http_build_query([
            'q' => $query,
            'kl' => 'wt-wt',
        ]);

        try {
            $body = $this->httpGet($url, [
                'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-US,en;q=0.9',
            ]);

            // Skip if DuckDuckGo returned a CAPTCHA/anomaly page.
            if (stripos($body, 'captcha') !== false || stripos($body, 'anomaly') !== false) {
                // CAPTCHA detected — fall through.
                $this->logger->debug('eva_ai: DuckDuckGo HTML returned CAPTCHA, falling back');
            } else {
                // Parse structured result blocks (result__a + result__snippet).
                $results = $this->parseDuckDuckGoResults($body, $count);

                // Broader link extraction if structured parsing found nothing.
                if ($results === []) {
                    $results = $this->parseDuckDuckGoLinks($body, $count);
                }

                if ($results !== []) {
                    return array_slice($results, 0, $count);
                }
            }
        } catch (\Throwable $e) {
            // HTML endpoint may be blocked (CAPTCHA/bot detection).
            $this->logger->debug('eva_ai: DuckDuckGo HTML failed: ' . $e->getMessage());
        }

        // Strategy 3: Startpage as a free fallback (no API key needed).
        // Startpage proxies Google results without tracking. It works
        // from most server IPs where DuckDuckGo is blocked.
        try {
            $results = $this->searchStartpage($query, $count);
            if ($results !== []) {
                return array_slice($results, 0, $count);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('eva_ai: Startpage fallback failed: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Strategy 1: Parse DuckDuckGo HTML result blocks.
     * Looks for the result__a links and nearby result__snippet text.
     */
    private function parseDuckDuckGoResults(string $html, int $count): array {
        $results = [];

        // Extract all links with class "result__a" (the main result title links).
        if (preg_match_all(
            '/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si',
            $html,
            $linkMatches,
            PREG_SET_ORDER
        )) {
            // Also extract all snippets.
            preg_match_all(
                '/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/si',
                $html,
                $snippetMatches,
                PREG_SET_ORDER
            );

            $snippets = [];
            foreach ($snippetMatches as $sm) {
                $snippets[] = $this->clamp(strip_tags(html_entity_decode($sm[1], ENT_QUOTES, 'UTF-8')), self::MAX_SNIPPET_CHARS);
            }

            $i = 0;
            foreach ($linkMatches as $linkMatch) {
                $rawUrl = trim(html_entity_decode($linkMatch[1], ENT_QUOTES, 'UTF-8'));
                $realUrl = $this->extractDuckDuckGoUrl($rawUrl);
                if (!$this->isSafeHttpUrl($realUrl)) {
                    continue;
                }
                $title = $this->clamp(strip_tags(html_entity_decode($linkMatch[2], ENT_QUOTES, 'UTF-8')), self::MAX_TITLE_CHARS);
                $snippet = $snippets[$i] ?? '';
                $results[] = [
                    'title' => $title !== '' ? $title : $realUrl,
                    'url' => $realUrl,
                    'snippet' => $snippet,
                ];
                $i++;
                if (count($results) >= $count) {
                    break;
                }
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
                // Skip DuckDuckGo internal links (navigation, settings, etc.).
                if (str_contains($realUrl, 'duckduckgo.com')) {
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

        // Deduplicate by URL to avoid showing the same source twice.
        $seen = [];
        $unique = [];
        foreach ($results as $r) {
            $key = $r['url'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $r;
            }
        }

        return array_slice($unique, 0, $count);
    }

    /**
     * Startpage.com: free, privacy-respecting search proxy that works
     * from server IPs without any API key. Used as a fallback when
     * DuckDuckGo is blocked by CAPTCHA.
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchStartpage(string $query, int $count): array {
        $url = 'https://www.startpage.com/sp/search';
        $body = $this->httpPost($url, http_build_query([
            'query' => $query,
            'cat' => 'web',
            'language' => 'english',
        ]), [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
            'Accept: text/html',
        ]);

        $results = [];

        // Extract result blocks: <a class="result-title result-link" href="URL">TITLE</a>
        if (preg_match_all(
            '/<a[^>]*class="[^"]*result-title[^"]*result-link[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si',
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $rawUrl = trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
                if (!$this->isSafeHttpUrl($rawUrl)) {
                    continue;
                }
                // Clean title: strip inline <style> tags and CSS content.
                $title = $match[2];
                $title = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $title);
                $title = strip_tags($title);
                $title = $this->clamp(trim($title), self::MAX_TITLE_CHARS);
                if ($title === '') {
                    continue;
                }
                $results[] = [
                    'title' => $title,
                    'url' => $rawUrl,
                    'snippet' => '', // Startpage snippets need separate extraction.
                ];
                if (count($results) >= $count) {
                    break;
                }
            }
        }

        // Try to extract descriptions for results that have empty snippets.
        if ($results !== []) {
            // Startpage uses <p class="w-gl__description"> for snippets.
            if (preg_match_all('/<p[^>]*class="[^"]*description[^"]*"[^>]*>(.*?)<\/p>/si', $body, $descMatches)) {
                foreach (array_values($descMatches[1]) as $i => $desc) {
                    if (!isset($results[$i])) break;
                    $text = $this->clamp(strip_tags(html_entity_decode($desc, ENT_QUOTES, 'UTF-8')), self::MAX_SNIPPET_CHARS);
                    if ($text !== '') {
                        $results[$i]['snippet'] = $text;
                    }
                }
            }
        }

        return $results;
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
            CURLOPT_USERAGENT => 'EVA-Nextcloud-WebSearch/1.0',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string)$payload;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
