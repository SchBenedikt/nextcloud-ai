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

    /** Resolved once per request: probing for Node is a process spawn. */
    private ?string $resolvedNode = null;

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
        return '';
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
    private function hasPlaywright(): bool
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
        $process = @proc_open(
            [$node, $this->scriptPath()],
            $descriptors,
            $pipes,
            \dirname($this->scriptPath()),
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
