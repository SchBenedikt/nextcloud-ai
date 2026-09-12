<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\RagService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * An answer that used the web has to say where it came from.
 *
 * The model may or may not repeat a URL in its prose, and the user cannot check
 * a claim they cannot see a source for, so the pages a tool actually retrieved
 * are attached to the answer structurally. These tests cover what gets attached
 * (and, just as importantly, what does not).
 */
final class AnswerWebSourcesTest extends TestCase {
    private function service(): RagService {
        return (new ReflectionClass(RagService::class))->newInstanceWithoutConstructor();
    }

    /** @param array<int,mixed> $args */
    private function call(RagService $service, string $method, array $args): mixed {
        return (new ReflectionClass(RagService::class))->getMethod($method)->invokeArgs($service, $args);
    }

    /** @param array<int,mixed> $args */
    private function collect(RagService $service, string $method, array $args): void {
        $this->call($service, $method, $args);
    }

    /** @return array{ok:bool,result:array<string,mixed>} */
    private function webSearchResult(array $results): array {
        return ['ok' => true, 'result' => ['query' => 'q', 'provider' => 'duckduckgo', 'external' => true, 'results' => $results]];
    }

    public function testEveryRetrievedPageBecomesASource(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => 'Nextcloud Hub 26', 'url' => 'https://nextcloud.com/hub26/', 'snippet' => 'Die neue Version.', 'published' => 1750000000],
            ['title' => 'Release notes', 'url' => 'https://github.com/nextcloud/server/releases', 'snippet' => 'Alle Releases.', 'source' => 'GitHub'],
        ])]);
        $sources = $this->call($service, 'answerSources', [[]]);

        self::assertCount(2, $sources);
        self::assertSame('https://nextcloud.com/hub26/', $sources[0]['url']);
        self::assertSame('Nextcloud Hub 26', $sources[0]['name']);
        self::assertSame('nextcloud.com', $sources[0]['host']);
        self::assertTrue($sources[0]['external']);
        self::assertSame(['Die neue Version.'], $sources[0]['excerpts']);
        self::assertSame(1750000000, $sources[0]['published']);
        self::assertSame('GitHub', $sources[1]['publisher']);
    }

    /** A failed search must not claim it found anything. */
    public function testAFailedSearchAddsNoSource(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['web_search', ['ok' => false, 'error' => 'unreachable']]);
        $this->collect($service, 'collectToolSources', ['web_search', ['ok' => true, 'result' => ['results' => []]]]);
        self::assertSame([], $this->call($service, 'answerSources', [[]]));
    }

    /** The same page from two searches is listed once, but keeps its detail. */
    public function testTheSamePageIsListedOnce(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => '', 'url' => 'https://example.org/a', 'snippet' => ''],
        ])]);
        $this->collect($service, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => 'Die Seite', 'url' => 'https://example.org/a', 'snippet' => 'Ein Auszug.'],
        ])]);
        $sources = $this->call($service, 'answerSources', [[]]);

        self::assertCount(1, $sources);
        self::assertSame('Die Seite', $sources[0]['name']);
        self::assertSame(['Ein Auszug.'], $sources[0]['excerpts']);
    }

    /** Opening a page in full adds it, and is marked as actually read. */
    public function testOpenedPageIsMarkedAndGetsASnippetFromItsText(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['open_website', [
            'ok' => true,
            'result' => [
                'url' => 'https://example.org/doku',
                'title' => 'Dokumentation',
                'highlights' => 'Die Schnittstelle liefert JSON.',
                'text' => 'Langer Seitentext.',
            ],
        ]]);
        $sources = $this->call($service, 'answerSources', [[]]);

        self::assertCount(1, $sources);
        self::assertTrue($sources[0]['opened']);
        self::assertSame(['Die Schnittstelle liefert JSON.'], $sources[0]['excerpts']);
    }

    /** Only a usable web link may become a source. */
    public function testNonHttpUrlsAreNeverListed(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => 'Lokal', 'url' => 'file:///etc/passwd', 'snippet' => 'x'],
            ['title' => 'Leer', 'url' => '', 'snippet' => 'x'],
            ['title' => 'Skript', 'url' => 'javascript:alert(1)', 'snippet' => 'x'],
            ['title' => 'Kein Feld', 'snippet' => 'x'],
        ])]);
        self::assertSame([], $this->call($service, 'answerSources', [[]]));
    }

    /** The user's own files stay first; web pages are appended after them. */
    public function testFileSourcesPrecedeWebSources(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => 'Web', 'url' => 'https://example.org/w', 'snippet' => 'x'],
        ])]);
        $sources = $this->call($service, 'answerSources', [[
            ['path' => 'Budget.md', 'name' => 'Budget.md', 'url' => '/f/1'],
        ]]);

        self::assertCount(2, $sources);
        self::assertSame('Budget.md', $sources[0]['name']);
        self::assertSame('https://example.org/w', $sources[1]['url']);
    }

    /** A tool that is not a web lookup must not add a source. */
    public function testOtherToolsAddNoSource(): void {
        $service = $this->service();
        $this->collect($service, 'collectToolSources', ['create_note', ['ok' => true, 'result' => ['url' => 'https://example.org/not-a-source']]]);
        $this->collect($service, 'collectToolSources', ['list_files', ['ok' => true, 'result' => ['url' => 'https://example.org/also-not']]]);
        self::assertSame([], $this->call($service, 'answerSources', [[]]));
    }

    /**
     * The collector is per request, never shared. Were it static, one user's
     * retrieved pages would appear as sources under another user's answer.
     */
    public function testOneAnswerNeverSeesAnothersSources(): void {
        $first = $this->service();
        $second = $this->service();
        $this->collect($first, 'collectToolSources', ['web_search', $this->webSearchResult([
            ['title' => 'Erste Frage', 'url' => 'https://example.org/one', 'snippet' => 'x'],
        ])]);

        self::assertCount(1, $this->call($first, 'answerSources', [[]]));
        self::assertSame([], $this->call($second, 'answerSources', [[]]), 'a second answer must start empty');
    }

    /**
     * Both answer paths have to collect, or the streaming chat (the one the web
     * UI actually uses) would show no sources at all.
     */
    public function testBothAnswerPathsCollectToolSources(): void {
        $source = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        foreach (['public function ask(', 'public function askStream('] as $signature) {
            $start = strpos($source, $signature);
            self::assertNotFalse($start, $signature . ' must exist');
            $next = strpos($source, 'private function clientDisconnected', $start);
            $method = substr($source, $start, $next - $start);
            self::assertStringContainsString('$this->toolSources = [];', $method, $signature . ' must start from a clean source list');
            self::assertStringContainsString('$this->collectToolSources(', $method, $signature . ' must record retrieved pages');
            self::assertStringContainsString('$this->answerSources($byDoc)', $method, $signature . ' must return the collected sources');
        }
    }
}
