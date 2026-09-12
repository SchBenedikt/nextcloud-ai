<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The contract that makes pictures in an answer actually work.
 *
 * Three separate things have to hold, and each of them failed in practice at
 * some point: the model has to be told it *can* show pictures (it used to answer
 * that it cannot), it has to embed them without being asked when the topic is
 * something visible, and the chat page has to be allowed to load a picture from
 * a remote host - Nextcloud's own image policy is `'self' data: blob:`, so a
 * correct markdown image was blocked by the browser and degraded to a link.
 */
final class ImageEmbeddingContractTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * The tool descriptions the model actually receives.
     *
     * @return array<string,string> tool name => description
     */
    private function descriptions(): array
    {
        // Web search - and with it the picture search - is opt-in, so the policy
        // only offers these tools when the instance has it switched on.
        $config = $this->createMock(AppConfig::class);
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => $key === 'web_search_enabled' ? 1 : (int)($default ?? 0)
        );
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_WEB);

        $executor = (new \ReflectionClass(ActionExecutor::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ActionExecutor::class, 'toolPolicy'))->setValue($executor, $policy);

        $out = [];
        foreach ($executor->tools() as $tool) {
            $name = (string)($tool['function']['name'] ?? '');
            if ($name !== '') {
                $out[$name] = (string)($tool['function']['description'] ?? '');
            }
        }
        // A guard against the test passing because the tools were never offered
        // at all - an empty list would satisfy a weaker assertion.
        self::assertNotSame([], $out, 'no tools were returned for the web surface');
        return $out;
    }

    /** A picture request must never be refused, and must produce embedded pictures. */
    public function testTheImageToolSaysItCanShowPicturesAndHow(): void
    {
        $tools = $this->descriptions();
        self::assertArrayHasKey('search_images', $tools);

        $description = $tools['search_images'];
        self::assertStringContainsString('NEVER reply that you are unable to show images', $description);
        self::assertStringContainsString('![', $description, 'the description must name markdown image syntax');
        self::assertStringContainsString('two to four', $description);
    }

    /**
     * Visual topics get pictures without the user asking for them.
     *
     * The user asked for exactly this: a search about a product or an event
     * should come back with the thing visible, because a picture of what is being
     * described belongs in the answer. The rule has to be in both descriptions:
     * the one that decides to search the web at all, and the one that decides to
     * look for pictures.
     */
    public function testBothSearchToolsCarryTheShowPicturesRule(): void
    {
        $tools = $this->descriptions();

        $images = $tools['search_images'];
        self::assertStringContainsString('You do not need to be asked', $images);
        foreach (['product', 'place', 'event'] as $topic) {
            self::assertStringContainsString($topic, $images, 'the picture rule does not name ' . $topic);
        }

        $web = $tools['web_search'];
        self::assertStringContainsString('search_images', $web, 'a web search does not point at the picture search');
        foreach (['product', 'event'] as $topic) {
            self::assertStringContainsString($topic, $web, 'the web search rule does not name ' . $topic);
        }
    }

    /**
     * The chat page has to be allowed to load remote pictures.
     *
     * Asserted on the controller source rather than through a browser: the only
     * way to observe this is a real Nextcloud response header, which the browser
     * test fixture does not have. Both page entry points (the app page and the
     * standalone page) must apply it - the standalone surface shows the same
     * answers and used to be forgotten.
     */
    public function testTheChatPagesAllowRemotePictures(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/PageController.php');

        self::assertStringContainsString('private function allowWebImages', $controller);
        self::assertStringContainsString("addAllowedImageDomain('*')", $controller);
        self::assertSame(
            2,
            substr_count($controller, '$this->allowWebImages($response);'),
            'every page that renders an answer must allow the pictures in it',
        );
    }
}
