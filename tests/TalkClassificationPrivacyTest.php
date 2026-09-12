<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Listener\TalkBotListener;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Regression coverage for Issue #77: the Talk bot must not send every room
 * message to the LLM for classification. A deterministic, cheap pre-filter
 * decides first; only plausibly-addressed messages reach the classifier.
 */
final class TalkClassificationPrivacyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Build a TalkBotListener with mock collaborators.
     *
     * @param string $classifyAll '0' (default heuristic pre-filter) or '1'
     */
    private function listener(string $classifyAll = '0'): array {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key) use ($classifyAll): string {
            return match ($key) {
                'talk_bot_trigger' => 'Eva',
                'talk_classify_all' => $classifyAll,
                default => '',
            };
        });
        $ollama = $this->createMock(Ollama::class);
        $roomState = $this->createMock(\OCA\EvaAi\Service\TalkRoomState::class);
        $roomState->method('isEnabled')->willReturn(true);
        $transcripts = $this->createMock(\OCA\EvaAi\Service\TalkTranscriptService::class);
        $transcripts->method('recall')->willReturn([]);
        $listener = new TalkBotListener(
            $ollama,
            $this->createMock(TalkContextReader::class),
            $this->createMock(ActionExecutor::class),
            $config,
            $this->createMock(RagService::class),
            $roomState,
            $transcripts,
            $this->createMock(LoggerInterface::class)
        );
        return [$listener, $ollama, $config];
    }

    private function shouldRespond(TalkBotListener $listener, string $content, bool $explicit = false): bool {
        $reflection = new ReflectionClass(TalkBotListener::class);
        $method = $reflection->getMethod('shouldRespond');
        // roomId 0 keeps the classification path free of \OC::$server lookups.
        return $method->invoke($listener, $content, 'alice', 0, $explicit);
    }

    public function testOrdinaryHumanSmalltalkDoesNotTriggerAnLlmCall(): void {
        [$listener, $ollama] = $this->listener('0');
        $ollama->expects(self::never())->method('chat');

        // Pure human-to-human smalltalk: no trigger word, no question mark,
        // no assistant-directed phrasing -> the pre-filter decides without an
        // LLM call and the message never leaves the server.
        self::assertFalse($this->shouldRespond($listener, 'Die Besprechung war wirklich lang heute'));
        self::assertFalse($this->shouldRespond($listener, 'ok'));
        self::assertFalse($this->shouldRespond($listener, 'Wir treffen uns morgen im Buero'));
    }

    public function testExplicitMentionAlwaysRespondsWithoutLlmClassification(): void {
        [$listener, $ollama] = $this->listener('0');
        // Explicit mentions never need the classification LLM call.
        $ollama->expects(self::never())->method('chat');
        self::assertTrue($this->shouldRespond($listener, '@Eva kannst du mir helfen?', true));
    }

    public function testUnaddressedQuestionNeverReachesTheClassifier(): void {
        [$listener, $ollama] = $this->listener('0');
        $ollama->expects(self::never())->method('chat');
        self::assertFalse($this->shouldRespond($listener, 'Wer hat den Raum gebucht?'));
    }

    public function testClassifierCannotOverrideTheNameRequirement(): void {
        [$listener, $ollama] = $this->listener('0');
        $ollama->expects(self::never())->method('chat');
        self::assertFalse($this->shouldRespond($listener, 'Wer hat den Raum gebucht?'));
    }

    public function testBotNameOnlyCountsWhenPassedAsExplicitAddressing(): void {
        [$listener, $ollama] = $this->listener('0');
        $ollama->expects(self::never())->method('chat');
        self::assertFalse($this->shouldRespond($listener, 'Eva kannst du das bitte pruefen'));
        self::assertTrue($this->shouldRespond($listener, 'Eva kannst du das bitte pruefen', true));
    }

    public function testClassifyAllCannotDisableTheNameRequirement(): void {
        [$listener, $ollama] = $this->listener('1');
        $ollama->expects(self::never())->method('chat');
        self::assertFalse($this->shouldRespond($listener, 'Die Besprechung war wirklich lang.'));
    }

    public function testClassificationFailureSkipsOnlyNonExplicitMessages(): void {
        // A failed classification call is logged and treated as "do not reply"
        // for non-explicit messages, never as a hard failure of the bot.
        [$listener, $ollama] = $this->listener('0');
        $ollama->method('chat')->willReturn(['error' => 'ollama busy']);
        self::assertFalse($this->shouldRespond($listener, 'Wer hat den Raum gebucht?'));
        // Explicit mentions never rely on the classifier.
        self::assertTrue($this->shouldRespond($listener, '@Eva bitte antworten', true));
    }
}
