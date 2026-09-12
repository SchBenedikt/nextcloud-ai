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
    public const PROVIDERS = ['duckduckgo', 'bing', 'searxng', 'brave', 'tavily'];

    /**
     * Providers that work without an API key. `bing` is a hint, not a
     * suggestion that EVA scrapes Google: Bing's RSS endpoint serves real web
     * results (titles, direct URLs, descriptions) and is the no-key option that
     * actually answers from a server, whereas Google's result page is a
     * JavaScript application and cannot be read without an API key.
     */
    public const FREE_PROVIDERS = ['duckduckgo', 'bing'];

    /**
     * Search modes. `news` uses the free news feeds (Bing News and Google News
     * RSS) instead of the web index, and `all` merges both so a question about
     * something current gets both background and the latest coverage.
     */
    public const MODES = ['web', 'news', 'all'];

    /** Encrypted at app scope; write-only through the admin API. */
    public const API_KEY_KEY = 'web_search_api_key';

    private const BRAVE_ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    private const TAVILY_ENDPOINT = 'https://api.tavily.com/search';
    /**
     * Key-free feeds. Bing serves web results through its RSS endpoint, and both
     * Bing and Google publish a news feed that carries the publication date and
     * the source name - which is what lets an answer say how old it is.
     */
    private const BING_WEB_ENDPOINT = 'https://www.bing.com/search';
    private const BING_IMAGE_ENDPOINT = 'https://www.bing.com/images/search';
    private const BING_NEWS_ENDPOINT = 'https://www.bing.com/news/search';
    private const GOOGLE_NEWS_ENDPOINT = 'https://news.google.com/rss/search';
    /** Ask the feed endpoints for XML explicitly, whatever the user agent implies. */
    private const FEED_ACCEPT = 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5';

    /**
     * A result is "fresh" within this many days. Recency is a tie-breaker, not a
     * trump card: an older page that answers the question still wins, but two
     * equally relevant pages are ordered newest first, which is what stops a
     * chat model from answering a current question with what it remembers.
     */
    private const FRESH_DAYS = 45;
    private const RECENT_DAYS = 365;

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
    private const ABSOLUTE_MAX_CONTENT_PAGES = 20;
    private const ABSOLUTE_MAX_CONTENT_CHARS = 8000;
    /**
     * How many hits may be read and compared before the best are picked. The
     * first hits a search engine returns are often the wrong page (a shop, a
     * forum thread, an ad), so the ranking is given a field of candidates to
     * choose from instead of the first few.
     */
    private const ABSOLUTE_MAX_CANDIDATES = 20;
    /**
     * Text budget when one page is opened directly. Far larger than the search
     * budget: opening a page is a deliberate "read this source" step, so the
     * useful part of a long article should actually arrive.
     */
    // A direct open is a full page read. Keep a generous safety ceiling for
    // pathological documents while avoiding the old 20k snippet-sized cap.
    private const ABSOLUTE_MAX_OPEN_CHARS = 2000000;
    private const DEFAULT_CANDIDATES = 12;
    /** Images offered per result, and the size below which one is an icon. */
    private const MAX_IMAGES_PER_RESULT = 3;
    /** Pictures returned by a dedicated image search, and its hard ceiling. */
    private const DEFAULT_IMAGES = 6;
    private const ABSOLUTE_MAX_IMAGES = 12;
    private const MIN_IMAGE_DIMENSION = 200;
    /**
     * src fragments that mark toolbars, logos, avatars and tracking pixels.
     * A page's own article images almost never carry these in their name.
     */
    private const IMAGE_CHROME_MARKERS = [
        'logo', 'icon', 'avatar', 'sprite', 'badge', 'pixel', 'tracking',
        'spinner', 'placeholder', 'blank.gif', '1x1', 'button', 'banner-ad',
    ];
    /** File names that make an <img> a picture rather than a layout artefact. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];
    /** Fetched pages must stay well inside the search timeout to keep chat responsive. */
    private const CONTENT_FETCH_TIMEOUT_CEILING = 8;
    /**
     * Below this many characters a statically fetched page is treated as a shell
     * rather than an article, and a browser is given a chance at it.
     *
     * Chosen from the shapes that actually occur: a client-rendered app ships a
     * few hundred bytes of markup around an empty container, while a cookie wall
     * leaves a short banner. A page that already yields real prose is never
     * rendered, which is what keeps the added cost attached to the pages that
     * actually need it.
     */
    private const THIN_PAGE_CHARS = 400;
    /**
     * How many thin pages one search may render. Rendering costs seconds per
     * page, so this is what stops an unlucky query from turning into a minute.
     */
    private const ABSOLUTE_MAX_RENDER_PAGES = 4;
    /**
     * How many redirect hops a fetched page may take. Each hop is validated as if
     * it were a fresh user-supplied URL, because a public address that redirects
     * to 127.0.0.1 is exactly how an SSRF guard is bypassed.
     */
    private const MAX_PAGE_REDIRECTS = 3;
    /**
     * Host suffixes that never leave the machine or the local network. They are
     * rejected by name so a name that only resolves inside the LAN is caught even
     * when its address record is cached somewhere unexpected.
     */
    private const INTERNAL_HOST_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa', '.lan', '.intranet'];
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

    /**
     * How many pages the last search read through a real browser.
     *
     * The interesting number for anyone checking whether rendering works: a
     * switch that is on and a browser that is never used look identical from
     * outside, and this is what tells them apart.
     */
    private int $lastRenderedPages = 0;

    /**
     * Host name => is public, for the lifetime of one request. A search resolves
     * the same host several times (every result link, every image on it), and a
     * DNS lookup per occurrence would be pure latency.
     *
     * @var array<string,bool>
     */
    private array $hostCache = [];

    public function __construct(
        private AppConfig $config,
        private IConfig $rawConfig,
        private ICrypto $crypto,
        private BrowserRenderer $renderer,
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
            'duckduckgo', 'bing' => true,
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

    /**
     * How many hits to read and compare before the best ones are returned.
     * Always at least the requested number of results, so the ranking has
     * something to choose between.
     */
    private function candidateLimit(int $resultCount): int {
        $configured = $this->config->getInt('web_search_candidates', self::DEFAULT_CANDIDATES);
        $configured = max(3, min(self::ABSOLUTE_MAX_CANDIDATES, $configured));
        return max($resultCount, $configured);
    }

    /** Whether page images are collected and offered to the model. */
    private function imagesEnabled(): bool {
        return $this->config->get('web_search_images') !== '0';
    }

    /**
     * Whether a page may be read through a real browser when a plain fetch is not
     * enough. Off unless an administrator switched it on *and* the server can
     * actually run it - see BrowserRenderer for why the second half matters.
     */
    private function browserRendering(): bool
    {
        return $this->renderer->isAvailable();
    }

    /**
     * Whether a browser is available for reading JavaScript-only pages, as a
     * sentence for the admin settings screen: '' when it works, otherwise the
     * concrete missing piece.
     *
     * Exposed rather than inferred in the template, because "switched on" and
     * "actually usable" are different things: an administrator who enables this
     * without Node installed would otherwise see a setting that quietly does
     * nothing.
     */
    public function browserStatus(): string
    {
        return $this->renderer->unavailableReason();
    }

    /**
     * Where the browser builds are searched for, for the admin settings screen.
     *
     * Shown because it is the one piece of the setup an administrator has to get
     * right and cannot guess: it is derived from the web server account's home
     * directory, which is rarely the account they are logged in as.
     */
    public function browserBrowsersPath(): string
    {
        return $this->renderer->browsersPath();
    }

    /** The command that installs the browser build, for the admin settings screen. */
    public function browserInstallCommand(): string
    {
        return $this->renderer->installCommand();
    }

    /**
     * How many pages of the last search were read in a real browser.
     *
     * Reported by the admin test search so that "switched on" can be told apart
     * from "actually doing something".
     */
    public function lastRenderedPages(): int
    {
        return $this->lastRenderedPages;
    }

    /**
     * Read pages in a real browser, under this service's own URL policy.
     *
     * The policy is passed in rather than reimplemented: the renderer runs the
     * same checks on the way in *and* on the URL the browser finally settled on,
     * because a redirect is a fresh request that a browser makes on its own.
     *
     * @param array<int,string> $urls index => url
     * @return array<int,array{html:string,text:string,title:string,finalUrl:string}>
     */
    private function renderMany(array $urls): array
    {
        if ($urls === [] || !$this->browserRendering()) {
            return [];
        }
        $pages = $this->renderer->renderMany(
            $urls,
            fn(string $candidate): bool => $this->isFetchablePage($candidate),
            $this->imagesEnabled(),
        );
        // Counted here rather than at each call site: a page that came back from
        // the browser is the same fact wherever it was asked for, and the count
        // is what the admin test reports.
        $this->lastRenderedPages += \count($pages);
        return $pages;
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
    public function search(string $query, ?int $limit = null, string $mode = 'web'): array {
        $query = trim($query);
        $provider = $this->provider();
        $mode = in_array($mode, self::MODES, true) ? $mode : 'web';
        if ($query === '') {
            return ['ok' => false, 'provider' => $provider, 'mode' => $mode, 'results' => [], 'error' => 'A search query is required.'];
        }
        if (!$this->isEnabled()) {
            return ['ok' => false, 'provider' => $provider, 'mode' => $mode, 'results' => [], 'error' => 'Web search is disabled by the administrator.'];
        }
        $count = max(1, min(self::ABSOLUTE_MAX_RESULTS, $limit ?? $this->maxResults()));
        // Ask the engine for more hits than are returned. The extra candidates
        // are read and scored, and only then are the best ones kept: without a
        // field to choose from, "best" inevitably collapses to "first".
        $candidates = $this->candidateLimit($count);
        $this->lastDuckDuckGoError = null;
        $failures = [];
        $results = [];

        // The two halves are attempted independently, so a blocked news feed
        // (or a blocked web index) still leaves the other one usable instead of
        // failing the whole question.
        if ($mode !== 'news') {
            try {
                $results = match ($provider) {
                    'duckduckgo' => $this->searchDuckDuckGo($query, $candidates),
                    'bing' => $this->searchBing($query, $candidates),
                    'searxng' => $this->searchSearxng($query, $candidates),
                    'brave' => $this->searchBrave($query, $candidates),
                    'tavily' => $this->searchTavily($query, $candidates),
                    default => throw new ProviderException('Unsupported web search provider: ' . $provider),
                };
            } catch (ProviderException $e) {
                $failures[] = $e->getMessage();
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: web search failed', [
                    'provider' => $provider,
                    'exception' => $e->getMessage(),
                ]);
                $failures[] = 'Web search failed.';
            }
        }

        if ($mode !== 'web') {
            try {
                $news = $this->searchNews($query, $candidates);
                if ($news !== []) {
                    // News items carry a publication date and a source name; both
                    // are what let the answer say how current it is.
                    $results = $this->mergeByUrl($results, $news, $candidates + $count);
                }
            } catch (ProviderException $e) {
                $failures[] = $e->getMessage();
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: news search failed', ['exception' => $e->getMessage()]);
                $failures[] = 'The news feeds could not be read.';
            }
        }

        // An empty result set must never be reported as success: the model
        // would then claim the web had no answer. Say why instead.
        if ($results === []) {
            return ['ok' => false, 'provider' => $provider, 'mode' => $mode, 'results' => [], 'error' => $this->emptyResultError($provider, $mode, $failures)];
        }

        // Two-phase selection. First a cheap pass ranks on title, snippet and
        // URL alone and picks the most promising candidates. Those pages are
        // then read, and a second pass re-ranks them on what they actually say.
        // Only after that are the best `$count` kept, so the returned list is
        // chosen by evidence rather than by the engine's order.
        $results = $this->rankResults($results, $query, false, $mode);
        $results = array_slice($results, 0, min(count($results), self::ABSOLUTE_MAX_CONTENT_PAGES));
        $results = $this->enrichWithPageContent($results, $query);
        $results = $this->rankResults($results, $query, true, $mode);
        $results = array_slice($results, 0, $count);

        return ['ok' => true, 'provider' => $provider, 'mode' => $mode, 'results' => $results, 'error' => null];
    }

    /**
     * Search for pictures of a subject.
     *
     * A text search is the wrong instrument for "show me pictures of X": its
     * results are pages, and the model ends up answering that it cannot display
     * images. This asks an image index instead and returns pictures that are
     * meant to be embedded, each with a title and the page it comes from.
     *
     * No API key is used: the image index is the public HTML one, exactly like
     * the web search fallbacks, so the feature works out of the box.
     *
     * @return array{ok:bool,provider:string,query:string,images:list<array{url:string,preview:string,title:string,page:string}>,error:?string}
     */
    public function searchImages(string $query, ?int $limit = null): array {
        $query = trim($query);
        $provider = 'bing-images';
        $empty = ['provider' => $provider, 'query' => $query, 'images' => []];
        if ($query === '') {
            return $empty + ['ok' => false, 'error' => 'A search query is required.'];
        }
        if (!$this->isEnabled()) {
            return $empty + ['ok' => false, 'error' => 'Web search is disabled by the administrator.'];
        }
        if (!$this->imagesEnabled()) {
            return $empty + ['ok' => false, 'error' => 'Image search is disabled by the administrator.'];
        }
        $count = max(1, min(self::ABSOLUTE_MAX_IMAGES, $limit ?? self::DEFAULT_IMAGES));

        $endpoint = self::BING_IMAGE_ENDPOINT . '?' . http_build_query([
            'q' => $query,
            'form' => 'HDRSC2',
            'first' => 1,
            'count' => max(20, $count * 4),
        ]);
        try {
            $html = $this->httpGet($endpoint, $this->browserHeaders(self::BING_IMAGE_ENDPOINT));
        } catch (\Throwable $e) {
            return $empty + ['ok' => false, 'error' => 'The image search failed: ' . $e->getMessage()];
        }
        $images = $this->parseBingImages($html, $count, $query);
        if ($images === []) {
            return $empty + ['ok' => false, 'error' => 'The image search returned no usable pictures for "' . $query . '".'];
        }
        return ['ok' => true, 'provider' => $provider, 'query' => $query, 'images' => $images, 'error' => null];
    }

    /**
     * Pull the picture entries out of an image-search page.
     *
     * Each hit sits in an `m="…"` attribute holding HTML-escaped JSON, so the
     * entry is decoded rather than pattern-matched: `murl` is the picture, `turl`
     * a thumbnail the engine serves, `t` the title and `purl` the page the
     * picture was found on. Chrome (logos, icons, tracking pixels) is dropped by
     * the same markers the page extractor uses, and only http(s) survives.
     *
     * @return list<array{url:string,preview:string,title:string,page:string}>
     */
    private function parseBingImages(string $html, int $count, string $query): array {
        // Bing has used double quoted, single quoted and data-m attributes
        // across its image result layouts. Accept all three so a markup change
        // does not make image search silently return an empty list.
        preg_match_all('/\bm\s*=\s*"([^"]+)"/i', $html, $doubleQuoted);
        preg_match_all("/\\bm\\s*=\\s*'([^']+)'/i", $html, $singleQuoted);
        preg_match_all('/\bdata-m\s*=\s*"([^"]+)"/i', $html, $dataQuoted);
        $matches = [1 => array_merge($doubleQuoted[1] ?? [], $singleQuoted[1] ?? [], $dataQuoted[1] ?? [])];
        if ($matches[1] === []) {
            return [];
        }
        $queryTerms = $this->queryTerms($query);
		// A named-person image search must never silently return an unrelated
		// stock image merely because the engine supplied malformed/weak metadata.
		// Two identity terms (for example "Angela Merkel") are a strong enough
		// signal; generic visual words such as photo and portrait do not count.
		$identityTerms = array_values(array_filter($queryTerms, static fn(string $term): bool => !in_array($term, [
			'image', 'images', 'picture', 'pictures', 'photo', 'photos', 'portrait', 'porträt', 'bilder', 'bild', 'foto', 'fotos',
		], true)));
		preg_match_all('/\b\p{Lu}[\p{L}\-]{1,}\b/u', $query, $properNames);
		$namedPersonSearch = count($identityTerms) >= 2 && count($identityTerms) <= 4
			&& count($properNames[0] ?? []) >= 2;
        $markers = $this->imageChromeMarkers($query);
        $candidates = [];
        $seen = [];
        foreach ($matches[1] as $raw) {
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_contains($raw, 'murl')) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $url = trim((string)($data['murl'] ?? ''));
            if (!$this->isSafeHttpUrl($url)) {
                continue;
            }
            $lower = strtolower($url);
            $skip = false;
            foreach ($markers as $marker) {
                if (str_contains($lower, $marker)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $preview = trim((string)($data['turl'] ?? ''));
            if (!$this->isSafeHttpUrl($preview)) {
                $preview = $url;
            }
            $title = $this->clamp(
                trim(preg_replace('/\s+/u', ' ', $this->stripBingHighlightMarks((string)($data['t'] ?? ''))) ?? ''),
                self::MAX_TITLE_CHARS,
            );
            $page = trim((string)($data['purl'] ?? ''));
            if (!$this->isSafeHttpUrl($page)) {
                $page = '';
            }
			$identityHaystack = mb_strtolower($title . ' ' . $page);
			$identityHits = 0;
			foreach ($identityTerms as $term) {
				if (str_contains($identityHaystack, $term)) $identityHits++;
			}
			if ($namedPersonSearch && $identityHits < 2) {
				continue;
			}
			$candidates[] = [
                'url' => $url,
                'preview' => $preview,
                'title' => $title !== '' ? $title : $query,
                'page' => $page,
                // Relevance is not scored from the picture itself (there is no
                // text to score); the engine order is kept, but a title that
                // mentions the query is preferred so an unrelated hit sitting
                // above the real ones does not win the first picture.
				'score' => $this->imageTitleScore($title, $queryTerms) + $identityHits * 4,
            ];
        }
        // Stable: engine order is the tie-breaker, a query-matching title wins.
        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $out = [];
        foreach (array_slice($candidates, 0, $count) as $c) {
            unset($c['score']);
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Chrome markers that apply to this query.
     *
     * The marker list exists to strip a page's own furniture (logos, avatars,
     * sprites) from article images. In an explicit image search the query is the
     * user's intent, so a query that asks for a logo or an icon must not have the
     * word "logo"/"icon" filter its own results away - that is exactly how
     * "show me pictures of a Nextcloud Hub logo" came back almost empty.
     *
     * @return list<string>
     */
    private function imageChromeMarkers(string $query): array
    {
        $needle = mb_strtolower($query);
        return array_values(array_filter(
            self::IMAGE_CHROME_MARKERS,
            static fn(string $marker): bool => !str_contains($needle, $marker),
        ));
    }

    /** Removes the private-use highlight markers the image index wraps query words in. */    private function stripBingHighlightMarks(string $text): string {
        return trim(preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $text) ?? $text);
    }

    /** @param list<string> $queryTerms */
    private function imageTitleScore(string $title, array $queryTerms): int {
        if ($title === '' || $queryTerms === []) {
            return 0;
        }
        $haystack = mb_strtolower($title);
        $score = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($haystack, $term)) {
                $score += mb_strlen($term) >= 5 ? 2 : 1;
            }
        }
        return $score;
    }

    /**
     * Read a single page on demand.
     *
     * A search snippet is a teaser; this is what lets the model actually read a
     * source it found, which is the difference between quoting a page and
     * guessing at it. The text budget is deliberately far larger than the one
     * used during a search, because reading one page deeply is the whole point.
     *
     * @return array{ok:bool,url:string,title:string,text:string,highlights:string,images:list<array{url:string,alt:string,width:int,height:int}>,published:?int,truncated:bool,error:?string}
     */
    public function openPage(string $url, string $query = ''): array {
        $url = trim($url);
        $empty = ['url' => $url, 'title' => '', 'text' => '', 'highlights' => '', 'images' => [], 'published' => null, 'truncated' => false];
        if (!$this->isEnabled()) {
            return $empty + ['ok' => false, 'error' => 'Web search is disabled by the administrator.'];
        }
        if (!$this->isFetchablePage($url)) {
            return $empty + ['ok' => false, 'error' => 'That URL cannot be opened. Only http(s) pages with readable content are supported.'];
        }

        $bodies = $this->fetchMany([$url]);
        $html = (string)($bodies[0] ?? '');
        $page = $html === '' ? ['text' => '', 'images' => []] : $this->extractPage($html, $url);
        $text = $page['text'];

        // A plainly fetched page can be empty for two very different reasons: it
        // refuses scripted clients, or its text only exists once its own scripts
        // have run. Both are answered by the same thing - asking a browser - so
        // the fallback covers an empty body as well as a thin one.
        // An explicit open request asks for the page itself, not a search
        // teaser. When the browser is available, always execute the page so
        // client-rendered content, lazy sections and consent-gated DOM are
        // included. The static response remains the fallback when rendering is
        // unavailable or produces less readable text.
        if ($this->browserRendering()) {
            $rendered = $this->renderMany([$url]);
            $renderedHtml = (string)($rendered[0]['html'] ?? '');
            if ($renderedHtml !== '') {
                $renderedUrl = (string)($rendered[0]['finalUrl'] ?? $url);
                if (!$this->isFetchablePage($renderedUrl)) {
                    $renderedUrl = $url;
                }
                $renderedPage = $this->extractPage($renderedHtml, $renderedUrl);
                if (mb_strlen($renderedPage['text']) > mb_strlen($text)) {
                    $page = $renderedPage;
                    $html = $renderedHtml;
                    $text = $renderedPage['text'];
                    $url = $renderedUrl;
                }
            }
        }

        if ($text === '') {
            return $empty + ['ok' => false, 'error' => 'The page contained no readable text (it may require JavaScript or a login).'];
        }

        $limit = self::ABSOLUTE_MAX_OPEN_CHARS;
        $published = $this->publishedTimestamp($html);
        return [
            'ok' => true,
            'url' => $url,
            'title' => $this->clamp($this->pageTitle($html), self::MAX_TITLE_CHARS),
            'text' => mb_substr($text, 0, $limit),
            'highlights' => $this->highlights($text, $query),
            'images' => $page['images'],
            'published' => $published > 0 ? $published : null,
            'truncated' => mb_strlen($text) > $limit,
            'error' => null,
        ];
    }

    /** The page's own <title>, used to label an opened page. */
    private function pageTitle(string $html): string {
        if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $html, $match) !== 1) {
            return '';
        }
        return $this->normaliseText(strip_tags($match[1]));
    }

    /**
     * The publication or modification time a page declares, if any.
     *
     * News and blog pages state it in `article:published_time`,
     * `og:updated_time` or as JSON-LD `datePublished`. Knowing it is what lets
     * the answer say how old it is instead of presenting a 2019 article as
     * current news.
     */
    private function publishedTimestamp(string $html): int {
        foreach ([
            '~<meta[^>]+(?:property|name)=["\'](?:article:published_time|article:modified_time|og:updated_time|datePublished|date)["\'][^>]*content=["\']([^"\']+)["\']~i',
            '~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\'](?:article:published_time|article:modified_time|og:updated_time|datePublished)["\']~i',
            '~["\']datePublished["\']\s*:\s*["\']([^"\']+)["\']~i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                $timestamp = strtotime(trim($match[1]));
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            }
        }
        return 0;
    }

    /**
     * Explain why a provider produced no results. For DuckDuckGo the concrete
     * cause (anti-bot page, unreachable endpoint) is much more useful than a
     * generic "no results", because the fix differs entirely.
     */
    private function emptyResultError(string $provider, string $mode = 'web', array $failures = []): string {
        if ($failures !== []) {
            // Report the concrete reason instead of a generic "no results": the
            // fix (a different provider, a key) depends on it.
            return implode(' ', array_unique($failures));
        }
        if ($provider === 'duckduckgo' && $this->lastDuckDuckGoError !== null) {
            return $this->lastDuckDuckGoError;
        }
        if ($mode === 'news') {
            return 'The news feeds returned no articles for this query. Try a broader query, or search the web instead.';
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
    private function rankResults(array $results, string $query, bool $useContent = false, string $mode = 'web'): array {
        $terms = $this->queryTerms($query);
        if ($terms === [] || count($results) < 2) {
            return array_values($results);
        }

        // The content score only means something once page text exists. When
        // enrichment is off (or every fetch failed) this stays a metadata rank.
        $contentAvailable = false;
        if ($useContent) {
            foreach ($results as $result) {
                if (trim((string)($result['content'] ?? '')) !== '') {
                    $contentAvailable = true;
                    break;
                }
            }
        }

        $scored = [];
        $hostSeen = [];
        foreach ($results as $index => $result) {
            $url = (string)($result['url'] ?? '');
            $title = mb_strtolower((string)($result['title'] ?? ''));
            $snippet = mb_strtolower((string)($result['snippet'] ?? ''));
            $path = mb_strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));

            $score = 0;
            $inTitle = 0;
            foreach ($terms as $term) {
                if (mb_strpos($title, $term) !== false) {
                    $score += 3;
                    $inTitle++;
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
            if ($inTitle === count($terms)) {
                $score += 5;
            }

            if ($contentAvailable) {
                $content = mb_strtolower((string)($result['content'] ?? ''));
                if ($content === '') {
                    // A page that could not be read is unverified: it stays in
                    // the list but never outranks a page we actually saw.
                    $score -= 2;
                } else {
                    // Presence of the terms in the body is the real relevance
                    // signal, and it is what lets a page ranked fifth by the
                    // engine overtake a weaker first hit.
                    $inContent = 0;
                    foreach ($terms as $term) {
                        if (mb_strpos($content, $term) !== false) {
                            $score += 2;
                            $inContent++;
                        }
                    }
                    if ($inContent === count($terms)) {
                        $score += 4;
                    }
                    if (mb_strpos($content, mb_strtolower($query)) !== false) {
                        // The exact phrase in the body: a very strong match.
                        $score += 4;
                    }
                }
            }

            if ($this->isLowValueHost($url)) {
                $score -= 6;
            }

            // Recency. A chat model answers a current question from what it was
            // trained on, which is exactly how users end up with outdated
            // answers, so how old a page is has to weigh in.
            $score += $this->recencyScore((int)($result['published'] ?? 0), $mode);

            // Diversity: five hits from one domain describe one source, not
            // five answers. Repeats are demoted, never removed, so a genuinely
            // dominant source can still come first.
            $host = mb_strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host !== '') {
                $repeats = $hostSeen[$host] ?? 0;
                $hostSeen[$host] = $repeats + 1;
                $score -= 4 * $repeats;
            }

            $scored[] = ['score' => $score, 'index' => $index, 'result' => $result];
        }

        usort($scored, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']);
        });

        return array_values(array_map(static fn(array $row): array => $row['result'], $scored));
    }

    /**
     * How much a page's age counts, which depends entirely on what was asked.
     *
     * In **web** mode currency is a tie-breaker: someone asking about a protocol
     * or a technique wants the best explanation, and the 2019 article that
     * explains it should still win, so age only nudges the order.
     *
     * In **news** mode currency *is* the question. A two-year-old post is not
     * news, and an item with no date at all is stale by definition. Both an old
     * and an undated item therefore have to lose by more than any amount of term
     * overlap can win, or "show me the latest" quietly returns the same stale
     * pages the model already knew - which is the exact failure this ranking
     * exists to prevent.
     */
    private function recencyScore(int $published, string $mode): int {
        $news = $mode === 'news';
        if ($published <= 0) {
            // Only news feeds carry dates reliably; an undated web hit must not
            // be punished for a page that simply does not declare one.
            return $news ? -4 : 0;
        }
        $ageDays = (time() - $published) / 86400;
        if ($ageDays < 0) {
            // A date in the future is a wrong date, not a fresh document.
            return $news ? -4 : 0;
        }
        if ($ageDays <= self::FRESH_DAYS) {
            return $news ? 14 : 4;
        }
        if ($ageDays <= 90) {
            return $news ? 9 : 3;
        }
        if ($ageDays <= self::RECENT_DAYS) {
            return $news ? 3 : 2;
        }
        if ($ageDays <= 3 * self::RECENT_DAYS) {
            return $news ? -7 : 0;
        }
        return $news ? -16 : -2;
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
    private function enrichWithPageContent(array $results, string $query): array {
        $enriched = [];
        foreach ($results as $result) {
            $enriched[] = $result + ['content' => '', 'highlights' => '', 'images' => []];
        }
        // Reading the page is what both the text and the images come from, so
        // the single request-per-page switch governs the whole enrichment. With
        // it off, nothing is fetched and nothing is sent anywhere.
        if ($enriched === [] || !$this->fetchContent()) {
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
        $wantContent = $this->fetchContent();
        $wantImages = $this->imagesEnabled();
        $bodies = $this->fetchMany($targets);

        // Pages whose text did not arrive are handed to a browser, in one batch
        // and bounded in number. This is deliberately the *second* pass: the
        // cheap fetch answers most results, and rendering only the remainder is
        // what keeps a search inside its time budget.
        if ($this->browserRendering()) {
            $thin = [];
            foreach ($targets as $index => $url) {
                $candidate = isset($bodies[$index]) ? $this->extractPage((string)$bodies[$index], $url) : ['text' => ''];
                if (mb_strlen((string)($candidate['text'] ?? '')) < self::THIN_PAGE_CHARS) {
                    $thin[$index] = $url;
                }
            }
            if ($thin !== []) {
                $thin = \array_slice($thin, 0, self::ABSOLUTE_MAX_RENDER_PAGES, true);
                foreach ($this->renderMany($thin) as $index => $page) {
                    if (isset($page['html'])) {
                        $bodies[$index] = $page['html'];
                    }
                }
            }
        }

        foreach ($bodies as $index => $html) {
            // One parse yields both the readable text and the page's images, so
            // collecting images costs no extra request and no extra DOM pass.
            $page = $this->extractPage($html, (string)($enriched[$index]['url'] ?? ''));
            if ($wantContent && $page['text'] !== '') {
                $full = $page['text'];
                $enriched[$index]['content'] = $this->clamp($full, $maxChars);
                // The passages that actually mention the query. A long page
                // often buries its answer, and this is what keeps the model from
                // grounding itself in the introduction instead.
                $enriched[$index]['highlights'] = $this->highlights($full, $query);
            }
            if ($wantImages && $page['images'] !== []) {
                $enriched[$index]['images'] = $page['images'];
            }
        }

        return $enriched;
    }

    /**
     * The sentences of a page that best match the query.
     *
     * Page content is truncated to a fixed budget, so the part of a long
     * document that answers the question can fall outside it. These passages are
     * picked by term coverage instead, and are what the model should quote.
     *
     * @return string
     */
    private function highlights(string $content, string $query): string {
        $terms = $this->queryTerms($query);
        if ($terms === [] || $content === '') {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $content) ?: [];
        $scored = [];
        foreach ($sentences as $position => $sentence) {
            $sentence = trim($sentence);
            $length = mb_strlen($sentence);
            if ($length < 40 || $length > 600) {
                continue;
            }
            $lower = mb_strtolower($sentence);
            $hits = 0;
            foreach ($terms as $term) {
                if (mb_strpos($lower, $term) !== false) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            // Prefer coverage first, then earlier sentences (introductions are
            // more likely to define the topic than a trailing footnote).
            $scored[] = ['hits' => $hits, 'position' => $position, 'sentence' => $sentence];
        }
        if ($scored === []) {
            return '';
        }
        usort($scored, static function (array $a, array $b): int {
            return ($b['hits'] <=> $a['hits']) ?: ($a['position'] <=> $b['position']);
        });
        $picked = array_slice($scored, 0, 3);
        usort($picked, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        $out = '';
        foreach ($picked as $row) {
            $candidate = $out === '' ? $row['sentence'] : $out . ' … ' . $row['sentence'];
            if (mb_strlen($candidate) > 1200) {
                break;
            }
            $out = $candidate;
        }
        return $out;
    }

    /**
     * Only fetch URLs that can plausibly yield readable text. Binary documents
     * and media would waste a request and produce nothing useful.
     */
    private function isFetchablePage(string $url): bool {
        if (!$this->isFetchableUrl($url)) {
            return false;
        }
        if ($this->isAggregatorRedirect($url)) {
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
     * Hosts that never serve the article the result claims to point at.
     *
     * Measured on a live instance: a Google News item is a redirect link, and
     * rendering it lands on Google's consent interstitial - "Before you continue"
     * with ~1,400 characters of cookie notice, not the article. A batch of three
     * such links spent an entire 26-second render budget and produced nothing.
     * They are headline references with a date and a publication, which is worth
     * keeping in the list, but spending a fetch or a render on them is not, and
     * the consent text must never be quotable as if it were the article.
     */
    private const UNREADABLE_HOSTS = ['news.google.com', 'consent.google.com'];

    /** Whether a URL is a known intermediary that never carries the article itself. */
    private function isAggregatorRedirect(string $url): bool
    {
        $host = mb_strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach (self::UNREADABLE_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return true;
            }
        }
        return false;
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
        $bodies = [];
        $pending = [];
        foreach ($urls as $index => $url) {
            // The starting URL is validated here as well as by the caller, so no
            // fetch path can reach this method with an internal address.
            if ($this->isFetchableUrl($url)) {
                $pending[$index] = $url;
            }
        }

        // Redirects are followed by hand rather than by cURL. `FOLLOWLOCATION`
        // would jump to whatever the next hop names - including 127.0.0.1 or the
        // metadata service - without giving this code a chance to look at it. One
        // batched request per hop keeps the parallelism and closes that hole.
        for ($hop = 0; $hop <= self::MAX_PAGE_REDIRECTS && $pending !== []; $hop++) {
            $responses = $this->fetchManyOnce($pending);
            $next = [];
            foreach ($responses as $index => $response) {
                $status = (int)$response['status'];
                if ($status >= 300 && $status < 400) {
                    $target = $this->resolveRedirect($pending[$index], (string)$response['location']);
                    // The last hop's redirect is not followed: a page that needs
                    // more hops than the budget is dropped, not chased.
                    if ($target !== null && $hop < self::MAX_PAGE_REDIRECTS) {
                        $next[$index] = $target;
                    }
                    continue;
                }
                if ($status >= 200 && $status < 300 && $response['body'] !== '') {
                    // Keep the caller's index, which identifies the result the
                    // body belongs to.
                    $bodies[$index] = $response['body'];
                }
            }
            $pending = $next;
        }

        return $bodies;
    }

    /**
     * Absolute, still-public URL of a redirect target, or null when the target is
     * relative junk, another scheme, or an address that must not be fetched.
     */
    private function resolveRedirect(string $baseUrl, string $location): ?string {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $location) === 1) {
            $absolute = $location;
        } elseif (str_starts_with($location, '//')) {
            $absolute = (string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https') . ':' . $location;
        } else {
            $parts = parse_url($baseUrl);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $authority = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (str_starts_with($location, '/')) {
                $absolute = $authority . $location;
            } else {
                $directory = (string)(parse_url($baseUrl, PHP_URL_PATH) ?? '/');
                $directory = substr($directory, 0, (int)strrpos($directory, '/') + 1) ?: '/';
                $absolute = $authority . $directory . $location;
            }
        }
        return $this->isFetchableUrl($absolute) ? $absolute : null;
    }

    /**
     * One batch of parallel GETs. Redirects are reported rather than followed, and
     * each response is returned with its status so the caller can decide.
     *
     * @param array<int,string> $urls index => url
     * @return array<int,array{status:int,body:string,location:string}>
     */
    private function fetchManyOnce(array $urls): array {
        $multi = curl_multi_init();
        $timeout = max(3, min(self::CONTENT_FETCH_TIMEOUT_CEILING, $this->timeout()));
        $handles = [];
        $locations = [];
        foreach ($urls as $index => $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            $locations[$index] = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                // Deliberately off: every hop is validated by fetchMany instead.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => self::BROWSER_USER_AGENT,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,de;q=0.8',
                ],
                CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$locations, $index): int {
                    if (preg_match('~^location:\s*(.+?)\s*$~i', $header, $match) === 1) {
                        $locations[$index] = $match[1];
                    }
                    return strlen($header);
                },
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$index] = $ch;
        }

        $responses = [];
        if ($handles === []) {
            curl_multi_close($multi);
            return $responses;
        }

        $running = 0;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $index => $ch) {
            $body = curl_multi_getcontent($ch);
            $responses[$index] = [
                'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => is_string($body) ? $body : '',
                'location' => (string)($locations[$index] ?? ''),
            ];
            curl_multi_remove_handle($multi, $ch);
            // No curl_close(): handles are freed automatically since PHP 8.0 and
            // the call is deprecated in 8.5.
        }
        curl_multi_close($multi);

        return $responses;
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
        return $this->extractPage($html, '')['text'];
    }

    /**
     * Read a fetched page once and take everything useful from it: the readable
     * text and the page's own images.
     *
     * Both come out of a single DOM parse, because the images worth offering are
     * exactly the ones inside the article container that the text pass already
     * located - header logos and sidebar artwork are chrome, not content.
     *
     * @return array{text:string,images:list<array{url:string,alt:string,width:int,height:int}>}
     */
    private function extractPage(string $html, string $baseUrl): array {
        if ($html === '') {
            return ['text' => '', 'images' => []];
        }
        if (class_exists(\DOMDocument::class)) {
            $page = $this->extractPageWithDom($html, $baseUrl);
            // Keep the DOM result when it produced anything at all: a picture
            // gallery can carry images without carrying much text, and falling
            // back to the regex pass would drop them.
            if ($page !== null && ($page['text'] !== '' || $page['images'] !== [])) {
                return $page;
            }
        }
        return [
            'text' => $this->extractTextWithRegex($html),
            'images' => $this->collectImagesWithRegex($html, $baseUrl),
        ];
    }

    /**
     * Read a page with a real DOM parse.
     *
     * Patterns cannot tell a layout wrapper <div> from a navigation <div>, and
     * getting that wrong either keeps menus or deletes the article. The DOM pass
     * removes the element types and the class/id keywords that never carry answer
     * text, prefers a marked main container, and only then reads the text.
     *
     * @return array{text:string,images:list<array{url:string,alt:string,width:int,height:int}>}|null
     */
    private function extractPageWithDom(string $html, string $baseUrl): ?array {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return null;
        }

        $xpath = new \DOMXPath($document);
        // The hero image is declared in <head>, which is outside the content
        // root and survives the chrome removal below; collect it first.
        $heroImages = $this->collectMetaImages($xpath, $baseUrl);
        $root = $this->findContentRoot($xpath);
        if ($root === null) {
            return null;
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

        return [
            'text' => $this->normaliseText((string)$root->textContent),
            'images' => $this->collectImages($xpath, $root, $baseUrl, $heroImages),
        ];
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
     * The page's declared hero image (Open Graph / Twitter card).
     *
     * Publishers set these to the image that represents the article, which is
     * exactly the right choice for an answer, and they sit in <head> outside the
     * content root.
     *
     * @return list<array{url:string,alt:string,width:int,height:int}>
     */
    private function collectMetaImages(\DOMXPath $xpath, string $baseUrl): array {
        if ($baseUrl === '' || !$this->imagesEnabled()) {
            return [];
        }
        // The property must match *exactly*. A "contains" test also matched the
        // sibling declarations og:image:type / og:image:width / og:image:alt,
        // and og:image:type's value ("image/jpeg") then resolved against the
        // page path and was offered as an image URL.
        $lower = 'translate(@%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "%s"';
        $query = '//meta['
            . sprintf($lower, 'property', 'og:image') . ' or ' . sprintf($lower, 'name', 'og:image')
            . ' or ' . sprintf($lower, 'property', 'twitter:image') . ' or ' . sprintf($lower, 'name', 'twitter:image')
            . ']/@content';
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }
        $out = [];
        foreach ($nodes as $node) {
            // "og:image:width"-style siblings are separate meta tags; only the
            // plain image URL is used here and the real dimensions are read
            // later from the rendered <img> when the page provides one.
            // Open Graph/Twitter images are publisher-declared hero images. A
            // filename containing "logo" is still useful here (Wikipedia and
            // many product pages use the site or article logo as their only
            // representative image), so the generic chrome filter is skipped
            // after the URL safety checks have passed.
            $url = $this->resolveImageUrl(trim((string)$node->nodeValue), $baseUrl, true);
            if ($url !== null) {
                $out[$url] = ['url' => $url, 'alt' => '', 'width' => 0, 'height' => 0];
            }
        }
        return array_values($out);
    }

    /**
     * Images that sit inside the article container.
     *
     * Only images holding real size are kept: inline icons, spacers and tracking
     * pixels are the majority of <img> tags on a page and none of them help an
     * answer. The hero image, when the page declares one, always leads.
     *
     * @param list<array{url:string,alt:string,width:int,height:int}> $heroImages
     * @return list<array{url:string,alt:string,width:int,height:int}>
     */
    private function collectImages(\DOMXPath $xpath, \DOMNode $root, string $baseUrl, array $heroImages): array {
        $images = [];
        foreach ($heroImages as $image) {
            $images[$image['url']] = $image;
        }
        if ($baseUrl === '' || !$this->imagesEnabled()) {
            return array_values($images);
        }

        $nodes = $xpath->query('.//img', $root);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                // Lazy loaders keep the real URL in a data attribute and leave a
                // placeholder (often just a pixel size) in src, so every
                // candidate is tried in turn and the first usable one wins.
                $url = null;
                foreach (['src', 'data-src', 'data-original', 'data-lazy-src'] as $attribute) {
                    $candidate = (string)$node->getAttribute($attribute);
                    if ($candidate === '') {
                        continue;
                    }
                    $resolved = $this->resolveImageUrl($candidate, $baseUrl);
                    if ($resolved !== null && $this->looksLikeImageResource($resolved)) {
                        $url = $resolved;
                        break;
                    }
                }
                if ($url === null || isset($images[$url])) {
                    continue;
                }
                $width = (int)$node->getAttribute('width');
                $height = (int)$node->getAttribute('height');
                if ($width > 0 || $height > 0) {
                    if ($width > 0 && $width < self::MIN_IMAGE_DIMENSION) {
                        continue;
                    }
                    if ($height > 0 && $height < self::MIN_IMAGE_DIMENSION) {
                        continue;
                    }
                }
                $images[$url] = [
                    'url' => $url,
                    'alt' => $this->clamp((string)$node->getAttribute('alt'), 160),
                    'width' => max(0, $width),
                    'height' => max(0, $height),
                ];
            }
        }

        return array_slice(array_values($images), 0, self::MAX_IMAGES_PER_RESULT);
    }

    /**
     * Image extraction for installs without ext-dom. It has no notion of the
     * article container, so it accepts any plausible image URL - the size and
     * chrome filters still apply.
     *
     * @return list<array{url:string,alt:string,width:int,height:int}>
     */
    private function collectImagesWithRegex(string $html, string $baseUrl): array {
        if ($baseUrl === '' || !$this->imagesEnabled() || !preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            return [];
        }
        $images = [];
        foreach ($matches[0] as $tag) {
            // Same candidate order as the DOM pass: a lazy loader may leave only
            // a placeholder in src and the real URL in a data attribute.
            $url = null;
            foreach (['src', 'data-src', 'data-original', 'data-lazy-src'] as $attribute) {
                if (preg_match('/\b' . $attribute . '\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                    $resolved = $this->resolveImageUrl($srcMatch[1], $baseUrl);
                    if ($resolved !== null && $this->looksLikeImageResource($resolved)) {
                        $url = $resolved;
                        break;
                    }
                }
            }
            if ($url === null || isset($images[$url])) {
                continue;
            }
            $width = preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $tag, $w) ? (int)$w[1] : 0;
            $height = preg_match('/\bheight\s*=\s*["\']?(\d+)/i', $tag, $h) ? (int)$h[1] : 0;
            if (($width > 0 && $width < self::MIN_IMAGE_DIMENSION) || ($height > 0 && $height < self::MIN_IMAGE_DIMENSION)) {
                continue;
            }
            $images[$url] = [
                'url' => $url,
                'alt' => preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $alt) ? $this->clamp($alt[1], 160) : '',
                'width' => $width,
                'height' => $height,
            ];
        }
        return array_slice(array_values($images), 0, self::MAX_IMAGES_PER_RESULT);
    }

    /**
     * Whether a resolved URL really points at a picture.
     *
     * Article pages are full of <img> tags that are not pictures: responsive
     * image plugins leave a MIME fragment in src ("…/image/jpeg"), and some
     * layouts use a page link as the image source. Embedding those would show a
     * broken image in an answer, so an <img> is accepted only when its path ends
     * in an image file name, or when the URL carries a size or format hint - the
     * extension-less CDN thumbnails ("…/photo-1234?w=800") that are real
     * pictures. A declared og:image/twitter:image is trusted as-is, because the
     * publisher chose it deliberately.
     */
    private function looksLikeImageResource(string $url): bool {
        $path = mb_strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        if (preg_match('~/(?:image|img|mime)/(?:jpe?g|png|gif|webp|avif|svg)$~', $path) === 1) {
            return false;
        }
        foreach (self::IMAGE_EXTENSIONS as $extension) {
            if (str_ends_with($path, '.' . $extension)) {
                return true;
            }
        }
        $query = mb_strtolower((string)(parse_url($url, PHP_URL_QUERY) ?? ''));
        return $query !== ''
            && preg_match('/(?:^|[&?])(?:w|h|width|height|resize|fit|format|auto|q)=/', $query) === 1;
    }

    /**
     * Resolve an image reference to an absolute http(s) URL.
     *
     * A result page may serve images from anywhere, so the URL is normalised
     * (protocol-relative, root-relative and path-relative forms all occur in the
     * wild) and then bounded like every other URL: only http(s), no embedded
     * credentials, and no data: or javascript: pseudo-URLs.
     */
    private function resolveImageUrl(string $raw, string $baseUrl, bool $allowChrome = false): ?string {
        $raw = trim(str_replace(["\n", "\r", "\t", ' '], '', $raw));
        if ($raw === '') {
            return null;
        }
        // Any other scheme (data:, blob:, javascript:, file:, …) is refused
        // outright instead of being treated as a relative path.
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $raw) === 1 && preg_match('~^https?://~i', $raw) !== 1) {
            return null;
        }
        // A bare image MIME type is a declaration value (og:image:type), never a
        // URL; resolving it against the page produced "…/article/image/jpeg".
        if (preg_match('~^(?:image|img)/(?:jpe?g|png|gif|webp|avif|svg\+xml|bmp|tiff|x-icon)$~i', $raw) === 1) {
            return null;
        }
        $lower = mb_strtolower($raw);
        if (!$allowChrome) {
            foreach (self::IMAGE_CHROME_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    return null;
                }
            }
        }
        // Lazy-loading plugins put a *size* in src and the real URL in a data
        // attribute (<img src="1600" data-src="/photo.jpg">), which would
        // otherwise resolve to "https://host/article/1600" and be shown as an
        // image. A relative reference must look like a path. Absolute and
        // root-relative URLs are exempt because extension-less image CDNs
        // (Unsplash and friends) are legitimate.
        $isRelative = !str_starts_with($raw, '//')
            && !str_starts_with($raw, '/')
            && preg_match('~^https?://~i', $raw) !== 1;
        if ($isRelative && !str_contains($raw, '.') && !str_contains($raw, '/')) {
            return null;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($raw, '//')) {
            $absolute = $base['scheme'] . ':' . $raw;
        } elseif (str_starts_with($raw, '/')) {
            $absolute = $origin . $raw;
        } elseif (preg_match('~^https?://~i', $raw) === 1) {
            $absolute = $raw;
        } else {
            $directory = (string)(parse_url($baseUrl, PHP_URL_PATH) ?? '/');
            $directory = substr($directory, 0, (int)strrpos($directory, '/') + 1);
            $absolute = $origin . $directory . $raw;
        }
        // Collapse ./ and ../ segments so the stored link is directly usable.
        $parts = parse_url($absolute);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            // Embedded credentials must never be rendered into an answer.
            return null;
        }
        $segments = [];
        foreach (explode('/', (string)($parts['path'] ?? '')) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $path = '/' . implode('/', $segments);
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
        return $this->isSafeHttpUrl($url) ? $url : null;
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
            // A duplicate that carries the publication date, the source name or
            // the news flag must not lose it to the record that arrived first.
            foreach (['published', 'source', 'news'] as $key) {
                if (isset($result[$key]) && !isset($byUrl[$url][$key])) {
                    $byUrl[$url][$key] = $result[$key];
                }
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
    /**
     * @param string $accept replaces the default Accept header. The feed
     *        endpoints serve XML, and asking a browser-like client for HTML
     *        there can return the HTML site instead of the feed.
     */
    private function browserHeaders(string $endpoint = '', string $accept = ''): array {
        $headers = [
            $accept !== '' ? 'Accept: ' . $accept : 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
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

    /**
     * Bing web results, read from its RSS endpoint (no API key).
     *
     * Bing's HTML page is a script-driven application whose result list cannot
     * be parsed reliably, but the same search is available as RSS with real
     * titles, direct result URLs and descriptions. Google offers no equivalent:
     * its result page is JavaScript-only and its feeds cover news, not the web.
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchBing(string $query, int $count): array {
        $url = self::BING_WEB_ENDPOINT . '?' . http_build_query([
            'q' => $query,
            'format' => 'RSS',
            'count' => min(50, max(10, $count)),
        ]);
        $body = $this->httpGet($url, $this->browserHeaders(self::BING_WEB_ENDPOINT, self::FEED_ACCEPT));
        return $this->parseRssResults($body, 'web', $count);
    }

    /**
     * News from the free feeds: Bing News and Google News.
     *
     * Both need no API key and both carry what a web result lacks - the
     * publication date and the source name. Bing's items link straight to the
     * publisher; Google's items are redirect links that open the article in a
     * browser, so they are kept but never preferred over a direct link.
     *
     * @return list<array{title:string,url:string,snippet:string,published:int,source:string,news:bool}>
     */
    private function searchNews(string $query, int $count): array {
        $locale = $this->newsLocale();
        $feeds = [
            self::BING_NEWS_ENDPOINT . '?' . http_build_query(['q' => $query, 'format' => 'RSS']) => $this->browserHeaders(self::BING_NEWS_ENDPOINT, self::FEED_ACCEPT),
            self::GOOGLE_NEWS_ENDPOINT . '?' . http_build_query([
                'q' => $query,
                'hl' => $locale['hl'],
                'gl' => $locale['gl'],
                'ceid' => $locale['ceid'],
            ]) => $this->browserHeaders(self::GOOGLE_NEWS_ENDPOINT, self::FEED_ACCEPT),
        ];
        $collected = [];
        foreach ($feeds as $url => $headers) {
            try {
                $body = $this->httpGet((string)$url, $headers);
                foreach ($this->parseRssResults($body, 'news', $count) as $row) {
                    $collected[] = $row;
                }
            } catch (\Throwable $e) {
                // One feed being unavailable must not lose the other one.
                $this->logger->info('eva_ai: news feed unavailable', ['feed' => (string)parse_url((string)$url, PHP_URL_HOST), 'error' => $e->getMessage()]);
            }
        }
        if ($collected === []) {
            throw new ProviderException('The news feeds could not be read.');
        }
        return $this->deduplicate($collected, $count);
    }

    /**
     * Read an RSS/Atom feed into the normalized result shape.
     *
     * Only http(s) item links survive, so a feed can never introduce a
     * javascript: or file: link into an answer. A Bing item wraps the publisher
     * URL in an apiclick redirect; the real URL is taken from its `url=`
     * parameter so the user ends up at the article rather than a tracking page.
     *
     * @return list<array<string,mixed>>
     */
    private function parseRssResults(string $xml, string $kind, int $count): array {
        if (trim($xml) === '') {
            return [];
        }
        $items = $this->rssItems($xml);
        $out = [];
        foreach ($items as $item) {
            $title = $this->clamp((string)($item['title'] ?? ''), self::MAX_TITLE_CHARS);
            $link = $this->decodeFeedUrl(trim((string)($item['link'] ?? '')));
            if ($link === '' || !$this->isSafeHttpUrl($link)) {
                continue;
            }
            $snippet = $this->clamp((string)($item['description'] ?? ''), self::MAX_SNIPPET_CHARS);
            $row = [
                'title' => $title !== '' ? $title : $link,
                'url' => $link,
                'snippet' => $snippet,
            ];
            if ($kind === 'news') {
                $row['published'] = $this->parseFeedDate((string)($item['pubDate'] ?? ''));
                $source = $this->clamp((string)($item['source'] ?? ''), 120);
                if ($source === '') {
                    $source = (string)(parse_url($link, PHP_URL_HOST) ?? '');
                }
                $row['source'] = $source;
                $row['news'] = true;
            }
            $out[] = $row;
            if (count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    /**
     * Flatten a feed into simple item arrays.
     *
     * SimpleXML is used when present; the regex path keeps news working on an
     * install without it, and a feed that is not XML at all (a block page, an
     * error document) simply yields nothing.
     *
     * @return list<array<string,string>>
     */
    private function rssItems(string $xml): array {
        if (class_exists(\SimpleXMLElement::class)) {
            $previous = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOENT);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($doc !== false) {
                $out = [];
                foreach ($doc->channel->item ?? [] as $item) {
                    $row = [];
                    foreach (['title', 'link', 'description', 'pubDate'] as $field) {
                        $row[$field] = html_entity_decode((string)$item->{$field}, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    $source = $item->source ?? null;
                    $row['source'] = $source !== null ? html_entity_decode((string)$source, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
                    $out[] = $row;
                }
                return $out;
            }
        }

        if (!preg_match_all('~<item\b[^>]*>(.*?)</item>~is', $xml, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $raw) {
            $row = [];
            foreach (['title', 'link', 'description', 'pubDate', 'source'] as $field) {
                $row[$field] = preg_match('~<' . $field . '\b[^>]*>(.*?)</' . $field . '>~is', $raw, $m)
                    ? html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : '';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Unwrap a feed's redirect link.
     *
     * Bing publishes `…/news/apiclick.aspx?…&url=<encoded publisher URL>`; the
     * encoded parameter is the article, and using the wrapper instead would send
     * the reader through a tracking redirect.
     */
    private function decodeFeedUrl(string $link): string {
        if ($link === '') {
            return '';
        }
        $parts = parse_url($link);
        if (!is_array($parts) || !isset($parts['query'])) {
            return $link;
        }
        parse_str((string)$parts['query'], $query);
        $target = trim((string)($query['url'] ?? ''));
        return $target !== '' && $this->isSafeHttpUrl($target) ? $target : $link;
    }

    /** Publication time of a feed item as a Unix timestamp (0 when unknown). */
    private function parseFeedDate(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * Language and region for the news feeds, taken from the user's own
     * language so a German account gets German coverage rather than the
     * English edition of a global feed.
     *
     * @return array{hl:string,gl:string,ceid:string}
     */
    private function newsLocale(): array {
        $language = '';
        $userId = $this->config->userId();
        if ($userId !== null && $userId !== '') {
            try {
                $language = (string)$this->rawConfig->getUserValue($userId, 'core', 'lang', '');
            } catch (\Throwable $e) {
                $language = '';
            }
        }
        $language = mb_strtolower(trim($language));
        if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/', $language) !== 1) {
            $language = 'en';
        }
        $language = str_replace('_', '-', $language);
        $language = substr($language, 0, 2);
        $regions = ['de' => 'DE', 'en' => 'US', 'fr' => 'FR', 'es' => 'ES', 'it' => 'IT', 'nl' => 'NL', 'pl' => 'PL', 'pt' => 'PT', 'sv' => 'SE', 'da' => 'DK', 'cs' => 'CZ', 'tr' => 'TR'];
        $region = $regions[$language] ?? mb_strtoupper($language);
        return [
            'hl' => $language . '-' . $region,
            'gl' => $region,
            'ceid' => $region . ':' . $language,
        ];
    }

    /**
     * Merge two result lists, keeping the richer record for a shared URL.
     *
     * @param list<array<string,mixed>> $primary
     * @param list<array<string,mixed>> $extra
     * @return list<array<string,mixed>>
     */
    private function mergeByUrl(array $primary, array $extra, int $limit): array {
        $byUrl = [];
        foreach ([...$primary, ...$extra] as $row) {
            $url = (string)($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (!isset($byUrl[$url])) {
                $byUrl[$url] = $row;
                continue;
            }
            if (mb_strlen((string)($row['snippet'] ?? '')) > mb_strlen((string)($byUrl[$url]['snippet'] ?? ''))) {
                $byUrl[$url]['snippet'] = $row['snippet'];
            }
            foreach (['published', 'source', 'news'] as $key) {
                if (isset($row[$key]) && !isset($byUrl[$url][$key])) {
                    $byUrl[$url][$key] = $row[$key];
                }
            }
        }
        return array_slice(array_values($byUrl), 0, max(1, $limit));
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

    /**
     * Whether the server may fetch this URL itself.
     *
     * Stricter than {@see isSafeHttpUrl()}, and deliberately separate from it: a
     * URL that is only ever *shown* (a search hit's link, an image the browser
     * loads from the result page) must not be resolved server-side, or an
     * unrelated DNS outage would silently empty the answer. A URL the server
     * really requests has to point at a public address, so a model-named target
     * like http://127.0.0.1, the cloud metadata service (169.254.169.254) or a
     * host on the internal network cannot be read and repeated back in the chat.
     */
    private function isFetchableUrl(string $url): bool {
        if (!$this->isSafeHttpUrl($url)) {
            return false;
        }
        return $this->isPublicHost((string)parse_url($url, PHP_URL_HOST));
    }

    /**
     * Whether a host resolves only to publicly routable addresses.
     *
     * Checked per address and not per name: a host is refused when *any* of its
     * addresses is private, so a DNS answer that mixes a public address with an
     * internal one cannot be used to reach the internal one. A host that does not
     * resolve at all is refused too - the fetch would fail anyway, and failing
     * closed is what keeps this a guard rather than a hint.
     */
    private function isPublicHost(string $host): bool {
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }
        $lowered = mb_strtolower(trim($host, '.'));
        if ($lowered === 'localhost' || $lowered === 'metadata') {
            return false;
        }
        foreach (self::INTERNAL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($lowered, $suffix)) {
                return false;
            }
        }

        $cached = $this->hostCache[$lowered] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $public = $this->resolvesToPublicAddress($lowered);
        $this->hostCache[$lowered] = $public;
        return $public;
    }

    private function resolvesToPublicAddress(string $host): bool {
        // A literal address needs no lookup; a name needs at least one answer.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host);
        }
        $addresses = [];
        foreach ((array)@gethostbynamel($host) as $ipv4) {
            $addresses[] = (string)$ipv4;
        }
        if (function_exists('dns_get_record')) {
            foreach ((array)@dns_get_record($host, DNS_AAAA) as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string)$record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                return false;
            }
        }
        return true;
    }

    /** Reject loopback, link-local, private and otherwise reserved addresses. */
    private function isPublicAddress(string $address): bool {
        // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) must be judged on the
        // address it really points at, or the guard only checks the wrapper.
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // 0.0.0.0/8 and ::/128 reach the local host on many systems.
        if ($address === '::' || $address === '::1' || str_starts_with($address, '0.')) {
            return false;
        }
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
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
