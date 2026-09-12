<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use Psr\Log\LoggerInterface;

/**
 * Runs the headless-browser page renderer for the web search.
 *
 * A plain HTTP GET reads the bytes a server sends; it cannot run the page's
 * JavaScript. For a growing share of the web - news sites, documentation portals
 * and anything behind a client-side shell - those bytes contain no article, so
 * an answer built from them is a guess dressed up as a quotation. This service
 * hands a URL to `bin/render-page.mjs`, which loads it in Chromium, and passes
 * the resulting DOM back to the caller for the same extraction the static path
 * uses.
 *
 * Three properties matter and are why this is not a one-liner:
 *
 *  - **It may simply be unavailable.** Rendering needs Node and Playwright on
 *    the server, next to an app that otherwise needs only PHP. Availability is
 *    therefore detected and *explained*, never assumed: the admin UI shows the
 *    concrete reason, and the search silently keeps working without it.
 *  - **It must terminate.** A page with a runaway script would otherwise hold a
 *    PHP worker forever, so the child is killed on a wall-clock deadline and the
 *    pipe is drained, not waited on.
 *  - **It must not become an SSRF hole.** A browser follows redirects and
 *    subresources on its own, which is exactly how a checked URL silently becomes
 *    a request to an internal address. The caller therefore supplies its URL
 *    policy; every URL that went in has to pass a caller check, and every URL the
 *    browser *ended up on* is validated again before its content is used.
 */
class BrowserRenderer {
    /** Hard bounds; the configuration narrows them, it cannot widen them. */
    public const MAX_PAGES_PER_CALL = 8;
    private const ABSOLUTE_MAX_HTML_CHARS = 2000000;
    private const DEFAULT_TIMEOUT_MS = 20000;
    private const MIN_TIMEOUT_MS = 3000;
    private const MAX_TIMEOUT_MS = 60000;
    /**
     * Chromium's cold start is slow, and it happens before the first page is
     * fetched, so the wall clock has to allow for it on top of the page timeout.
     */
    private const LAUNCH_GRACE_MS = 6000;
    /**
     * Pages rendered at once. The wall-clock deadline is derived from it, because
     * a batch of four pages takes two rounds: killing a legitimate batch because
     * the deadline only allowed one would turn a working feature into a flaky one.
     */
    private const CONCURRENCY = 3;
    public const NODE_BINARY_KEY = 'web_search_browser_node';
    public const ENABLED_KEY = 'web_search_browser';
    public const BROWSERS_PATH_KEY = 'web_search_browser_browsers_path';

    /** Resolved once per request: probing for Node is a process spawn. */
    private ?string $resolvedNode = null;
    /** Resolved once per request: locating a browser build touches the disk. */
    private ?string $resolvedBrowsersPath = null;
    private ?string $resolvedBrowser = null;

    public function __construct(
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /** The renderer script shipped with the app. */
    public function scriptPath(): string
    {
        return \dirname(__DIR__, 2) . '/bin/render-page.mjs';
    }

    /**
     * Whether rendering can be used right now.
     *
     * Deliberately checks all three prerequisites rather than only the setting:
     * a switch that is on while Node or Playwright is missing produces a search
     * that is quietly slower and no better, which is worse than not offering it.
     */
    public function isAvailable(): bool
    {
        return $this->unavailableReason() === '';
    }

    /**
     * Why rendering is unavailable, as a sentence for the admin, or '' when it is
     * available. The reason is meant to be actionable: it names the missing piece.
     */
    public function unavailableReason(): string
    {
        if ($this->config->get(self::ENABLED_KEY) !== '1') {
            return 'Browser rendering is switched off.';
        }
        if (!is_file($this->scriptPath())) {
            return 'The renderer script bin/render-page.mjs is missing from the app directory.';
        }
        if ($this->nodeBinary() === null) {
            $configured = trim($this->config->get(self::NODE_BINARY_KEY));
            if ($configured !== '') {
                return 'The configured Node.js path is not an executable file: ' . $configured;
            }
            return 'Node.js was not found. Set the Node binary path in the settings, or make `node` available on PATH.';
        }
        if (!$this->hasPlaywright()) {
            return 'The Playwright package is missing. Install it next to the app (npm install --omit=dev playwright) so the renderer can drive a browser.';
        }
        // The package and the browser are separate downloads. Reporting only the
        // package would leave a feature that looks ready and fails on the first
        // request, so the browser build is checked for as well - and the fix is
        // spelled out, because "Playwright is installed" is what an admin has
        // just done and would find confusing to be told again.
        if ($this->browserExecutable() === null) {
            return 'The Playwright package is installed but its Chromium browser is not, so nothing can be rendered. Run this as the user the web server runs as: '
                . $this->installCommand();
        }
        return '';
    }

    /**
     * Where Playwright keeps its browser builds.
     *
     * Mirrors Playwright's own resolution order - the environment variable, then
     * the current user's home - so the directory this class *checks* is the one
     * the renderer *uses*. An explicit setting wins, which is how an
     * administrator points at an existing installation, or at a shared location
     * the web server user can read but whose own home it cannot.
     */
    public function browsersPath(): string
    {
        if ($this->resolvedBrowsersPath !== null) {
            return $this->resolvedBrowsersPath;
        }
        $configured = trim($this->config->get(self::BROWSERS_PATH_KEY));
        $fromEnvironment = trim((string)getenv('PLAYWRIGHT_BROWSERS_PATH'));
        $home = trim((string)getenv('HOME'));
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            // A service account usually has no HOME in its environment, and a
            // child process started without one cannot find an installed
            // browser. The account's own home is what Node falls back to, so it
            // is what this has to check.
            $account = @posix_getpwuid(posix_geteuid());
            $home = is_array($account) ? trim((string)($account['dir'] ?? '')) : '';
        }
        if ($configured !== '') {
            $path = $configured;
        } elseif ($fromEnvironment !== '') {
            $path = $fromEnvironment;
        } elseif ($home !== '') {
            $path = rtrim($home, '/') . '/.cache/ms-playwright';
        } else {
            $path = '';
        }
        $this->resolvedBrowsersPath = $path;
        return $path;
    }

    /**
     * The Chromium binary Playwright would launch, or null when there is none.
     *
     * Both layouts Playwright uses are looked for: the full browser it takes for
     * a headed launch and the headless shell used for a headless one. The
     * directory below the build has been renamed over the years
     * (chrome-linux, chrome-linux-arm64, ...), so the binary is found by name
     * rather than by a path this class would have to keep in sync.
     */
    public function browserExecutable(): ?string
    {
        if ($this->resolvedBrowser !== null) {
            return $this->resolvedBrowser === '' ? null : $this->resolvedBrowser;
        }
        $this->resolvedBrowser = '';
        $base = $this->browsersPath();
        if ($base === '' || !is_dir($base)) {
            return null;
        }
        $names = ['chrome', 'chrome-headless-shell', 'headless_shell'];
        foreach ((array)glob($base . '/*', GLOB_ONLYDIR) as $build) {
            foreach ((array)glob($build . '/*', GLOB_ONLYDIR) as $architecture) {
                foreach ($names as $name) {
                    $candidate = $architecture . '/' . $name;
                    if (is_file($candidate) && is_executable($candidate)) {
                        $this->resolvedBrowser = $candidate;
                        return $candidate;
                    }
                }
            }
        }
        return null;
    }

    /**
     * The command that installs the browser build.
     *
     * Generated rather than written out in the docs alone, because the two ways
     * this goes wrong are both silent: installing as root puts the build in a
     * home the web server cannot read, and omitting the browsers path installs
     * it where this check does not look.
     */
    public function installCommand(): string
    {
        $appRoot = \dirname(__DIR__, 2);
        $path = $this->browsersPath();
        $prefix = $path === '' ? '' : 'PLAYWRIGHT_BROWSERS_PATH=' . $path . ' ';
        return 'cd ' . $appRoot . ' && ' . $prefix . 'npx playwright install chromium';
    }

    /**
     * The Node executable to use, or null when there is none.
     *
     * An absolute path is honoured directly, which is what makes this usable on a
     * server whose PHP process has almost no PATH. Otherwise PATH is searched,
     * because the common case is a Node installed normally.
     */
    public function nodeBinary(): ?string
    {
        if ($this->resolvedNode !== null) {
            return $this->resolvedNode === '' ? null : $this->resolvedNode;
        }
        $this->resolvedNode = '';
        $configured = trim($this->config->get(self::NODE_BINARY_KEY));
        // An explicitly configured path is authoritative. Falling back to PATH
        // when it is wrong would mean an administrator looks at a typo in the
        // settings, sees a working feature, and never learns which binary is
        // actually being run.
        if ($configured !== '') {
            if (is_file($configured) && is_executable($configured)) {
                $this->resolvedNode = $configured;
                return $configured;
            }
            return null;
        }
        foreach (['node', '/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
            if (str_contains($candidate, '/')) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $this->resolvedNode = $candidate;
                    return $candidate;
                }
                continue;
            }
            // Resolve through PATH without shelling out for it.
            $path = getenv('PATH') ?: '';
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                $directory = trim($directory);
                if ($directory === '') {
                    continue;
                }
                $full = rtrim($directory, '/') . '/' . $candidate;
                if (is_file($full) && is_executable($full)) {
                    $this->resolvedNode = $full;
                    return $full;
                }
            }
        }
        return null;
    }

    /**
     * Whether the Playwright package the script imports is resolvable.
     *
     * Checked by path rather than by asking Node, so that a half-installed
     * dependency is reported instantly instead of costing a process spawn on
     * every page load of the settings screen. Node resolves upward from the
     * script, so both the app's own node_modules and the one above it count.
     */
    protected function hasPlaywright(): bool
    {
        $appRoot = \dirname(__DIR__, 2);
        foreach ([$appRoot . '/node_modules/playwright/package.json', \dirname($appRoot) . '/node_modules/playwright/package.json'] as $marker) {
            if (is_file($marker)) {
                return true;
            }
        }
        return false;
    }

    /** The configured render timeout in milliseconds, clamped to the hard bounds. */
    public function timeoutMs(): int
    {
        $seconds = $this->config->getInt('web_search_browser_timeout', 20);
        $ms = $seconds * 1000;
        if ($ms < self::MIN_TIMEOUT_MS) {
            return self::MIN_TIMEOUT_MS;
        }
        return min($ms, self::MAX_TIMEOUT_MS);
    }

    /**
     * Render several URLs in one browser.
     *
     * Batching is not an optimisation detail: launching Chromium costs more than
     * rendering a page, so one process for the whole set is what keeps rendering
     * inside a chat request's budget.
     *
     * @param array<int,string> $urls index => url, as the caller wants them back
     * @param callable(string):bool $isAllowedUrl the caller's URL policy. Applied
     *        to every URL going in and again to every URL the browser ended on,
     *        because a redirect is a new request in all but name.
     * @return array<int,array{html:string,text:string,title:string,finalUrl:string}> only successful pages
     */
    public function renderMany(array $urls, callable $isAllowedUrl, bool $withImages = false): array
    {
        if ($urls === [] || !$this->isAvailable()) {
            return [];
        }
        $node = $this->nodeBinary();
        if ($node === null) {
            return [];
        }

        $allowed = [];
        foreach ($urls as $index => $url) {
            if ($isAllowedUrl($url)) {
                $allowed[$index] = $url;
            }
        }
        if ($allowed === []) {
            return [];
        }
        // The request is sent in the caller's index order; the options are
        // per call, so the count cap is only about the batch size.
        $allowed = \array_slice($allowed, 0, self::MAX_PAGES_PER_CALL, true);

        $timeoutMs = $this->timeoutMs();
        $payload = json_encode([
            'urls' => array_values($allowed),
            'timeoutMs' => $timeoutMs,
            'maxChars' => self::ABSOLUTE_MAX_HTML_CHARS,
            'executablePath' => $this->browserExecutable(),
            'withImages' => $withImages,
            'concurrency' => self::CONCURRENCY,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return [];
        }

        // The pages are rendered in rounds of CONCURRENCY, so the budget is one
        // launch plus one timeout per round - not one timeout for the batch.
        $rounds = (int)ceil(count($allowed) / self::CONCURRENCY);
        $budgetMs = self::LAUNCH_GRACE_MS + ($timeoutMs * max(1, $rounds));
        $response = $this->run($node, $payload, $budgetMs);
        if ($response === null) {
            return [];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $error = is_array($decoded) ? (string)($decoded['error'] ?? 'unknown error') : 'unparseable output';
            $this->logger->warning('eva_ai: browser render failed: ' . $error);
            return [];
        }

        $out = [];
        $indexes = array_keys($allowed);
        foreach ((array)($decoded['pages'] ?? []) as $key => $page) {
            if (!is_array($page) || ($page['ok'] ?? false) !== true) {
                continue;
            }
            $position = (int)$key;
            $index = $indexes[$position] ?? null;
            if ($index === null) {
                continue;
            }
            $finalUrl = (string)($page['finalUrl'] ?? '');
            // The browser may have been redirected anywhere. Content is only
            // handed on when the place it actually landed is somewhere the
            // caller's policy allows; otherwise the redirect was the attack.
            if ($finalUrl !== '' && !$isAllowedUrl($finalUrl)) {
                $this->logger->warning('eva_ai: browser render discarded, final URL is not allowed: ' . $finalUrl);
                continue;
            }
            $html = (string)($page['html'] ?? '');
            if ($html === '') {
                continue;
            }
            $out[$index] = [
                'html' => $html,
                'text' => (string)($page['text'] ?? ''),
                'title' => (string)($page['title'] ?? ''),
                'finalUrl' => $finalUrl,
            ];
        }
        return $out;
    }

    /**
     * Execute the renderer and return its stdout, or null on any failure.
     *
     * The child is killed at the deadline: a page can run forever, but a PHP
     * worker cannot. Output is read with a non-blocking select loop so a child
     * that produces nothing still hits the deadline instead of blocking on read.
     */
    private function run(string $node, string $payload, int $timeoutMs): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // The child inherits this process's environment with the browser
        // location pinned to the path this class validated. That matters for
        // more than convenience: the web server's own environment usually has no
        // HOME, and it means the directory that was checked for a browser build
        // is the directory the renderer searches, so diagnosis and execution
        // cannot disagree.
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $browsersPath = $this->browsersPath();
        if ($browsersPath !== '') {
            $environment['PLAYWRIGHT_BROWSERS_PATH'] = $browsersPath;
        }
        // Chromium's bundled crash reporter expects a writable HOME. PHP-FPM
        // often clears HOME for www-data, which makes a valid executable exit
        // immediately before Playwright can create a page.
        if (!isset($environment['HOME']) || !is_dir((string)$environment['HOME']) || !is_writable((string)$environment['HOME'])) {
            $environment['HOME'] = sys_get_temp_dir();
        }
        $process = @proc_open(
            [$node, $this->scriptPath()],
            $descriptors,
            $pipes,
            \dirname($this->scriptPath()),
            $environment,
        );
        if (!is_resource($process)) {
            $this->logger->warning('eva_ai: could not start the browser renderer process.');
            return null;
        }

        try {
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + ($timeoutMs / 1000);
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while ($open !== []) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $read = array_values($open);
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 0, (int)min(200000, $remaining * 1000000));
                if ($ready === false) {
                    break;
                }
                if ($ready === 0) {
                    continue;
                }
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    $at = array_search($stream, $open, true);
                    if ($chunk === false || $chunk === '') {
                        if (feof($stream)) {
                            fclose($stream);
                            unset($open[$at]);
                        }
                        continue;
                    }
                    if ($at === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            foreach ($open as $stream) {
                fclose($stream);
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                // The deadline passed. Kill, then reap, so the process cannot
                // outlive the request that started it.
                proc_terminate($process, 9);
                $this->logger->warning('eva_ai: browser render timed out after ' . $timeoutMs . ' ms.');
            }
            if ($stderr !== '') {
                $this->logger->debug('eva_ai: browser render stderr: ' . trim($stderr));
            }
            return $stdout === '' ? null : $stdout;
        } finally {
            proc_close($process);
        }
    }
}
