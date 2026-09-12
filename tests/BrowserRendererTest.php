<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BrowserRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The wrapper around the headless-browser renderer.
 *
 * The browser itself is covered by `tests/renderer.test.cjs`, which proves real
 * JavaScript ran. What is tested here is everything around it, because each of
 * those pieces is a way the feature can hurt: a switch that claims to work when
 * the server cannot do it, a child process that never dies, a redirect into the
 * local network, or a renderer that writes junk.
 *
 * Most cases drive a stub script instead of Chromium. That is deliberate - the
 * deadline, the exit handling and the JSON contract are what matter, and a stub
 * makes them deterministic in milliseconds rather than seconds.
 */
final class BrowserRendererTest extends TestCase {
    /** @var list<string> */
    private array $tempFiles = [];
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
        $this->removeBrowserDirectories();
        parent::tearDown();
    }

    /**
     * A renderer whose script is supplied by the test.
     *
     * `scriptPath()` is the only seam needed: everything else - the process
     * handling, the parsing, the URL checks - is the real implementation.
     */
    private function renderer(
        array $values,
        string $script,
        ?LoggerInterface $logger = null,
        bool $playwrightInstalled = true,
    ): BrowserRenderer {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): string => $values[$key] ?? ''
        );
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => isset($values[$key]) ? (int)$values[$key] : (int)($default ?? 0)
        );
        return new class($config, $logger ?? $this->createMock(LoggerInterface::class), $script, $playwrightInstalled) extends BrowserRenderer {
            public function __construct(
                AppConfig $config,
                LoggerInterface $logger,
                private string $stubScript,
                private bool $stubPlaywrightInstalled,
            ) {
                parent::__construct($config, $logger);
            }

            public function scriptPath(): string
            {
                return $this->stubScript;
            }

            protected function hasPlaywright(): bool
            {
                return $this->stubPlaywrightInstalled;
            }
        };
    }

    /**
     * An installed-looking browser build.
     *
     * The layout mirrors what Playwright unpacks - a revision directory with the
     * binary inside an architecture-named one - so these cases assert against the
     * real lookup rather than against a single hardcoded path. It is a temp file,
     * not Chromium: what is being tested is that a browser is *found* and handed
     * to the renderer, which the stub script stands in for.
     */
    private function browserDirectory(): string
    {
        $base = sys_get_temp_dir() . '/eva-playwright-' . bin2hex(random_bytes(4));
        $binary = $base . '/chromium-9999/chrome-linux/chrome';
        if (!is_dir(\dirname($binary)) && !mkdir(\dirname($binary), 0700, true)) {
            self::fail('could not create the browser fixture');
        }
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0700);
        $this->tempDirs[] = $base;
        return $base;
    }

    /** A renderer that is switched on, has a browser and a script of the caller's choosing. */
    private function rendererWithBrowser(array $values, string $script): BrowserRenderer
    {
        return $this->renderer(
            $values + [
                'web_search_browser' => '1',
                'web_search_browser_node' => $this->nodePath(),
                'web_search_browser_browsers_path' => $this->browserDirectory(),
            ],
            $script,
        );
    }

    /**
     * A stand-in for the renderer script.
     *
     * PHP is used as the "Node" for these cases and the stub is a PHP script, so
     * the stub can build its own response instead of quoting JSON through a
     * shell. The wrapper cannot tell the difference: it writes a request to the
     * child's stdin and reads JSON from its stdout, which is exactly what is
     * being tested.
     */
    private function stub(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eva-render-');
        if ($path === false) {
            self::fail('could not create a stub script');
        }
        $this->tempFiles[] = $path;
        file_put_contents($path, "<?php\nfwrite(STDIN, '');\n" . $body . "\n");
        chmod($path, 0700);
        return $path;
    }

    /** Drain stdin, which is what the real renderer does before it answers. */
    private function drainStdin(): string
    {
        return "stream_get_contents(STDIN);\n";
    }

    /** A path that certainly exists, so availability never fails on the binary. */
    private function nodePath(): string
    {
        return PHP_BINARY;
    }

    private function removeBrowserDirectories(): void
    {
        foreach ($this->tempDirs as $base) {
            $binary = $base . '/chromium-9999/chrome-linux/chrome';
            if (is_file($binary)) {
                @unlink($binary);
            }
            @rmdir($base . '/chromium-9999/chrome-linux');
            @rmdir($base . '/chromium-9999');
            @rmdir($base);
        }
        $this->tempDirs = [];
    }

    /** @param array<string,mixed> $pages */
    private function stubPages(array $pages): string
    {
        return $this->stub(
            $this->drainStdin()
            . 'fwrite(STDOUT, json_encode(["ok" => true, "pages" => ' . var_export($pages, true) . ']));'
        );
    }

    public function testSwitchedOffIsReportedAsSuchAndNothingIsRendered(): void
    {
        $renderer = $this->renderer(['web_search_browser' => '0'], $this->stub('exit(0);'));

        self::assertFalse($renderer->isAvailable());
        self::assertStringContainsString('switched off', $renderer->unavailableReason());
        self::assertSame([], $renderer->renderMany(['https://example.org/'], static fn(): bool => true));
    }

    public function testAMissingNodeIsNamedAsTheReason(): void
    {
        $renderer = $this->renderer(
            ['web_search_browser' => '1', 'web_search_browser_node' => '/nonexistent/node-binary'],
            $this->stub('exit(0);'),
        );

        self::assertFalse($renderer->isAvailable());
        // The message has to name the missing piece, otherwise the admin cannot
        // tell a missing Node from a missing Playwright.
        self::assertStringContainsString('Node.js', $renderer->unavailableReason());
    }

    public function testTheConfiguredNodeExecutableIsHonoured(): void
    {
        $renderer = $this->renderer(
            ['web_search_browser' => '1', 'web_search_browser_node' => $this->nodePath()],
            $this->stub('exit(0);'),
        );

        self::assertSame($this->nodePath(), $renderer->nodeBinary());
    }

    /**
     * The package and the browser are separate downloads, so "Playwright is
     * installed" is not the same as "a browser can be launched". Without this
     * distinction the settings page reports a ready feature that fails on the
     * first page it tries to read.
     */
    public function testAMissingBrowserBuildIsNamedAsTheReasonWithTheCommandThatFixesIt(): void
    {
        $empty = sys_get_temp_dir() . '/eva-playwright-empty-' . bin2hex(random_bytes(4));
        @mkdir($empty, 0700, true);
        $this->tempDirs[] = $empty;

        $renderer = $this->renderer(
            [
                'web_search_browser' => '1',
                'web_search_browser_node' => $this->nodePath(),
                'web_search_browser_browsers_path' => $empty,
            ],
            $this->stub('exit(0);'),
        );

        self::assertFalse($renderer->isAvailable());
        self::assertNull($renderer->browserExecutable());
        $reason = $renderer->unavailableReason();
        self::assertStringContainsString('Chromium', $reason);
        // A diagnosis that does not say what to run leaves the admin guessing
        // between two installations, so the command is part of the message.
        self::assertStringContainsString('playwright install chromium', $reason);
        self::assertStringContainsString($empty, $reason);
    }

    /** A missing package is reported as such, not as a missing browser. */
    public function testAMissingPlaywrightPackageIsReportedSeparately(): void
    {
        $renderer = $this->renderer(
            ['web_search_browser' => '1', 'web_search_browser_node' => $this->nodePath()],
            $this->stub('exit(0);'),
            null,
            false,
        );

        self::assertStringContainsString('Playwright package is missing', $renderer->unavailableReason());
    }

    public function testTheInstalledBrowserIsFoundInTheRevisionLayout(): void
    {
        $base = $this->browserDirectory();
        $renderer = $this->renderer(
            [
                'web_search_browser' => '1',
                'web_search_browser_node' => $this->nodePath(),
                'web_search_browser_browsers_path' => $base,
            ],
            $this->stub('exit(0);'),
        );

        self::assertTrue($renderer->isAvailable());
        self::assertSame($base . '/chromium-9999/chrome-linux/chrome', $renderer->browserExecutable());
        self::assertSame('', $renderer->unavailableReason());
    }

    /**
     * The child process has to look in the same place this class checked. The web
     * server's environment usually has no HOME, and a renderer that searches a
     * different directory is the failure that looks like "the browser is broken".
     */
    public function testTheCheckedBrowsersDirectoryIsExportedToTheRenderer(): void
    {
        $base = $this->browserDirectory();
        // The stub answers with the environment it was handed as the page title,
        // which is the one channel the wrapper reads back from a child process.
        $renderer = $this->renderBrowserPathProbe($base);

        $pages = $renderer->renderMany(['https://a.example/'], static fn(): bool => true);

        self::assertCount(1, $pages);
        self::assertSame($base, $pages[0]['title']);
    }

    /**
     * A renderer whose stub script answers with the PLAYWRIGHT_BROWSERS_PATH it
     * was given, so the value that actually reached the child can be asserted.
     */
    private function renderBrowserPathProbe(string $browsersPath): BrowserRenderer
    {
        return $this->renderer(
            [
                'web_search_browser' => '1',
                'web_search_browser_node' => $this->nodePath(),
                'web_search_browser_browsers_path' => $browsersPath,
            ],
            $this->stub(
                $this->drainStdin()
                . '$path = (string)getenv("PLAYWRIGHT_BROWSERS_PATH");'
                . ' fwrite(STDOUT, json_encode(["ok" => true, "pages" => ["0" => ['
                . '"ok" => true, "finalUrl" => "https://a.example/", "title" => $path, "html" => "<p>x</p>", "text" => "x"]]]));'
            ),
        );
    }

    /**
     * Resolution order, highest first: the setting, the environment, the web
     * server account's home. Asserted because the path decides which browser
     * loads, and a wrong guess here is invisible until a page fails to render.
     */
    public function testTheBrowsersPathResolutionOrder(): void
    {
        $previous = getenv('PLAYWRIGHT_BROWSERS_PATH');
        putenv('PLAYWRIGHT_BROWSERS_PATH=/tmp/eva-from-environment');
        try {
            $fromEnvironment = $this->renderer(['web_search_browser' => '1'], $this->stub('exit(0);'));
            self::assertSame('/tmp/eva-from-environment', $fromEnvironment->browsersPath());

            $fromSetting = $this->renderer(
                ['web_search_browser' => '1', 'web_search_browser_browsers_path' => '/tmp/eva-from-setting'],
                $this->stub('exit(0);'),
            );
            self::assertSame('/tmp/eva-from-setting', $fromSetting->browsersPath());
        } finally {
            if ($previous === false) {
                putenv('PLAYWRIGHT_BROWSERS_PATH');
            } else {
                putenv('PLAYWRIGHT_BROWSERS_PATH=' . $previous);
            }
        }
    }

    public function testTheTimeoutIsClampedToTheHardBounds(): void
    {
        $low = $this->renderer(['web_search_browser_timeout' => '1'], $this->stub('exit(0);'));
        $high = $this->renderer(['web_search_browser_timeout' => '600'], $this->stub('exit(0);'));

        self::assertSame(3000, $low->timeoutMs());
        self::assertSame(60000, $high->timeoutMs());
    }

    public function testRenderedPagesComeBackUnderTheCallersOwnIndexes(): void
    {
        // The renderer reports pages by position in the batch it was given; the
        // caller needs them back under its own indexes, which is how a page is
        // matched to the search hit it came from.
        $renderer = $this->rendererWithBrowser([], $this->stubPages([
            '0' => ['ok' => true, 'finalUrl' => 'https://a.example/x', 'title' => 'A', 'html' => '<p>alpha</p>', 'text' => 'alpha'],
            '1' => ['ok' => true, 'finalUrl' => 'https://b.example/y', 'title' => 'B', 'html' => '<p>beta</p>', 'text' => 'beta'],
        ]));

        $pages = $renderer->renderMany(
            [7 => 'https://a.example/x', 9 => 'https://b.example/y'],
            static fn(): bool => true,
        );

        self::assertSame([7, 9], array_keys($pages));
        self::assertStringContainsString('alpha', $pages[7]['html']);
        self::assertStringContainsString('beta', $pages[9]['html']);
    }

    /**
     * A browser follows redirects by itself, which is how a checked URL becomes a
     * request to somewhere it must never reach. The address the browser actually
     * settled on is therefore checked again, and content from a rejected landing
     * page is thrown away rather than trusted.
     */
    public function testAPageThatLandedOnADisallowedAddressIsDiscarded(): void
    {
        $renderer = $this->rendererWithBrowser([], $this->stubPages([
            '0' => ['ok' => true, 'finalUrl' => 'http://127.0.0.1:8080/admin', 'title' => 'internal', 'html' => '<p>secret</p>', 'text' => 'secret'],
        ]));

        $pages = $renderer->renderMany(
            ['https://public.example/redirect'],
            static fn(string $url): bool => !str_contains($url, '127.0.0.1'),
        );

        self::assertSame([], $pages, 'content from an internal redirect must never be used');
    }

    /** A URL the caller already rejects is never handed to a browser at all. */
    public function testARejectedUrlIsNeverRendered(): void
    {
        $renderer = $this->rendererWithBrowser([], $this->stubPages([
            '0' => ['ok' => true, 'finalUrl' => 'http://127.0.0.1/', 'title' => 'x', 'html' => '<p>x</p>', 'text' => 'x'],
        ]));

        $pages = $renderer->renderMany(['http://127.0.0.1/'], static fn(): bool => false);
        self::assertSame([], $pages);
    }

    public function testJunkOutputProducesNoResultsInsteadOfAnError(): void
    {
        $renderer = $this->rendererWithBrowser([], $this->stub($this->drainStdin() . 'echo "this is not json";'));

        self::assertSame([], $renderer->renderMany(['https://example.org/'], static fn(): bool => true));
    }

    /**
     * A page can run forever; a PHP worker cannot. The child is killed at the
     * deadline and the call returns empty instead of hanging the request.
     */
    public function testAHangingRendererIsKilledAtTheDeadline(): void
    {
        $renderer = $this->rendererWithBrowser(
            ['web_search_browser_timeout' => '3'],
            $this->stub($this->drainStdin() . 'sleep(30);'),
        );

        $started = microtime(true);
        $pages = $renderer->renderMany(['https://example.org/slow'], static fn(): bool => true);
        $elapsed = microtime(true) - $started;

        self::assertSame([], $pages);
        // One page, a 3 s page timeout: the budget is the launch grace (6 s) plus
        // that timeout. The assertion allows slack for a loaded machine while
        // still failing a hang outright.
        self::assertLessThan(20.0, $elapsed, 'the renderer was not stopped at its deadline');
    }

    /**
     * The real renderer, the real script and a real browser, against a page whose
     * text only exists after JavaScript has run. This is the one test that proves
     * the wrapper's process handling and the renderer agree on the contract.
     */
    public function testTheRealRendererReadsAJavaScriptPageThroughTheWrapper(): void
    {
        $renderer = new BrowserRenderer(
            (static function (): AppConfig {
                $config = new class extends AppConfig {
                    public function __construct() {
                    }

                    public function get(string $key): string {
                        return $key === BrowserRenderer::ENABLED_KEY ? '1' : '';
                    }

                    public function getInt(string $key, ?int $default = null): int {
                        return $key === 'web_search_browser_timeout' ? 25 : (int)($default ?? 0);
                    }
                };
                return $config;
            })(),
            $this->createMock(LoggerInterface::class),
        );

        if (!$renderer->isAvailable()) {
            // Node or Playwright is genuinely absent from this machine. Say so
            // instead of pretending the path was exercised.
            self::markTestSkipped('browser rendering is not available here: ' . $renderer->unavailableReason());
        }

        $port = $this->freePort();
        $documentRoot = sys_get_temp_dir() . '/eva-render-docroot-' . $port;
        @mkdir($documentRoot, 0700, true);
        file_put_contents(
            $documentRoot . '/index.html',
            '<!doctype html><html><head><title>Rendered fixture</title></head><body>'
            . '<div id="root">Loading…</div>'
            . '<script>document.getElementById("root").textContent = "Nur nach JavaScript sichtbar.";</script>'
            . '</body></html>',
        );

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $documentRoot],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($server)) {
            self::fail('could not start the fixture web server');
        }

        try {
            $this->waitForPort($port);
            $pages = $renderer->renderMany(
                ['http://127.0.0.1:' . $port . '/'],
                static fn(): bool => true,
            );
        } finally {
            proc_terminate($server, 9);
            proc_close($server);
            @unlink($documentRoot . '/index.html');
            @rmdir($documentRoot);
        }

        self::assertCount(1, $pages, 'the real renderer produced no page');
        $page = $pages[0];
        self::assertStringContainsString(
            'Nur nach JavaScript sichtbar.',
            $page['html'],
            'the wrapper did not carry the rendered DOM back',
        );
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail('could not allocate a port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int)substr((string)$name, (int)strrpos((string)$name, ':') + 1);
        self::assertGreaterThan(0, $port);
        return $port;
    }

    private function waitForPort(int $port, float $timeoutSeconds = 15.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }
        self::fail('the fixture web server did not start');
    }
}
