<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\WebSearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * "Show me pictures of X" must return pictures.
 *
 * The tool exists because a text search answers with pages, which is how the
 * model ended up telling users it cannot display images. These tests cover the
 * parser that turns the image index page into embeddable pictures, using a
 * recorded fixture so the behaviour is checked without network access.
 */
final class WebImageSearchTest extends TestCase {
    /** One image index entry, as the page encodes it (HTML-escaped JSON). */
    private function entry(string $murl, string $title, string $page = 'https://example.org/article'): string {
        $json = json_encode([
            'murl' => $murl,
            'turl' => 'https://ts.mm.bing.net/th?id=OIP.example&pid=15.1',
            't' => $title,
            'purl' => $page,
        ], JSON_UNESCAPED_SLASHES);
        return '<a class="iusc" m="' . htmlspecialchars($json, ENT_QUOTES) . '"></a>';
    }

    /** @param list<array<string,mixed>> $entries */
    private function parse(string $html, int $count, string $query): array {
        $service = (new ReflectionClass(WebSearchService::class))->newInstanceWithoutConstructor();
        return (new ReflectionClass(WebSearchService::class))
            ->getMethod('parseBingImages')
            ->invoke($service, $html, $count, $query);
    }

    public function testEntriesBecomeEmbeddablePicturesWithTitleAndSourcePage(): void {
        $html = $this->entry('https://cdn.example.org/golden.jpg', 'Golden Retriever puppy');
        $images = $this->parse($html, 6, 'golden retriever');

        self::assertCount(1, $images);
        self::assertSame('https://cdn.example.org/golden.jpg', $images[0]['url']);
        self::assertSame('Golden Retriever puppy', $images[0]['title']);
        self::assertSame('https://example.org/article', $images[0]['page']);
        // A thumbnail that always loads is offered next to the original.
        self::assertStringContainsString('bing.net', $images[0]['preview']);
    }

    /**
     * The engine wraps the matched words in private-use marker characters.
     * They are an index artefact, not part of the caption, and would otherwise
     * show up as boxes in the answer.
     */
    public function testHighlightMarkersAreStrippedFromTitles(): void {
        $html = $this->entry('https://cdn.example.org/a.jpg', "Golden \u{E000}Retriever\u{E001} photo");
        $images = $this->parse($html, 6, 'golden retriever');

        self::assertSame('Golden Retriever photo', $images[0]['title']);
    }

    public function testNonHttpPicturesAreDropped(): void {
        $html = $this->entry('javascript:alert(1)', 'Bad')
            . $this->entry('data:image/png;base64,AAAA', 'Also bad')
            . $this->entry('', 'Empty');
        self::assertSame([], $this->parse($html, 6, 'anything'));
    }

    public function testLayoutChromeIsFilteredOut(): void {
        $html = $this->entry('https://cdn.example.org/site-logo.png', 'Site logo')
            . $this->entry('https://cdn.example.org/tracking-pixel.gif', 'Pixel')
            . $this->entry('https://cdn.example.org/photo.jpg', 'A real photo');
        $images = $this->parse($html, 6, 'some subject');

        self::assertCount(1, $images);
        self::assertSame('https://cdn.example.org/photo.jpg', $images[0]['url']);
    }

    /**
     * The chrome filter must not eat the answer: someone asking for a logo gets
     * logos. This was the difference between an almost empty result and the
     * actual product logo when the query was "Nextcloud Hub logo".
     */
    public function testChromeFilterIsDisabledForWordsTheQueryItselfAsksFor(): void {
        $html = $this->entry('https://nextcloud.com/wp-content/uploads/hub-logo.png', 'Nextcloud Hub logo')
            . $this->entry('https://cdn.example.org/icon-set.png', 'Icon set');
        $images = $this->parse($html, 6, 'Nextcloud Hub logo');

        // The logo survives because the query asks for it; the icon set does not,
        // because "icon" was not part of the request.
        self::assertCount(1, $images, 'a query about logos must keep logo pictures');
        self::assertSame('https://nextcloud.com/wp-content/uploads/hub-logo.png', $images[0]['url']);
    }

    /**
     * An unrelated hit can sit above the real ones in engine order. A title that
     * mentions what was asked for wins, so the first picture is the relevant one.
     */
    public function testATitleMatchingTheQueryIsPreferredOverEngineOrder(): void {
        $html = $this->entry('https://cdn.example.org/unrelated.jpg', 'A church in Andalusia')
            . $this->entry('https://cdn.example.org/real.jpg', 'Brandenburger Tor in Berlin');
        $images = $this->parse($html, 6, 'Brandenburger Tor');

        self::assertSame('https://cdn.example.org/real.jpg', $images[0]['url']);
    }

    public function testTheRequestedCountIsRespected(): void {
        $html = '';
        for ($i = 1; $i <= 8; $i++) {
            $html .= $this->entry('https://cdn.example.org/p' . $i . '.jpg', 'Picture ' . $i);
        }
        self::assertCount(3, $this->parse($html, 3, 'picture'));
    }

    public function testAPageWithNoPicturesReturnsNothingRatherThanFailing(): void {
        self::assertSame([], $this->parse('<html><body>no images here</body></html>', 6, 'x'));
    }

    public function testTheServiceRefusesAQueryWhenImageSearchIsDisabled(): void {
        $service = (new ReflectionClass(WebSearchService::class))->newInstanceWithoutConstructor();
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('get')->willReturnCallback(static fn(string $key): string => $key === 'web_search_images' ? '0' : '1');
        $config->method('getInt')->willReturn(8);
        $reflect = new ReflectionClass(WebSearchService::class);
        $property = $reflect->getProperty('config');
        $property->setValue($service, $config);

        $result = $service->searchImages('anything', 4);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('disabled', (string)$result['error']);
        self::assertSame([], $result['images']);
    }
}
