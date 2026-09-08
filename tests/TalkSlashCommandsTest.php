<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Listener\TalkBotListener;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\TalkRoomState;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Talk bot slash commands and per-room enable/disable (Issue #85):
 * commands must be handled deterministically (no LLM classification) and
 * the per-room state must gate every response except /start itself.
 */
final class TalkSlashCommandsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function event(string $content, int $roomId = 7): BotInvokeEvent {
        return new BotInvokeEvent(
            Bot::URL_APP_PREFIX . 'eva_ai',
            [
                'type' => 'Create',
                'object' => ['content' => $content],
                'actor' => ['id' => 'users/alice', 'name' => 'Alice'],
                'target' => ['id' => $roomId],
            ]
        );
    }

    /**
     * @return array{0:TalkBotListener,1:Ollama,2:TalkRoomState}
     */
    private function harness(bool $roomEnabled = true): array {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'talk_bot_trigger' => 'Eva',
                'talk_classify_all' => '0',
                default => '',
            };
        });
        $config->method('getInt')->willReturn(50);
        $ollama = $this->createMock(Ollama::class);
        $roomState = $this->createMock(TalkRoomState::class);
        $roomState->method('isEnabled')->willReturn($roomEnabled);
        $contextReader = $this->createMock(TalkContextReader::class);
        $contextReader->method('buildHistoryMessages')->willReturn([
            ['role' => 'user', 'content' => 'Erster Beitrag über das Projekt Alpha.'],
            ['role' => 'user', 'content' => 'Zweiter Beitrag: nächste Woche Review.'],
        ]);
        $listener = new TalkBotListener(
            $ollama,
            $contextReader,
            $this->createMock(ActionExecutor::class),
            $config,
            $this->createMock(RagService::class),
            $roomState,
            $this->createMock(LoggerInterface::class)
        );
        return [$listener, $ollama, $roomState];
    }

    private function answers(BotInvokeEvent $event): array {
        return array_map(
            static fn(array $a): string => (string)($a['message'] ?? ''),
            $event->getAnswers()
        );
    }

    public function testHelpCommandAnswersWithoutAnyLlmCall(): void {
        [$listener, $ollama] = $this->harness();
        $ollama->expects(self::never())->method('chat');

        $event = $this->event('@Eva /help');
        $listener->handle($event);
        $answers = $this->answers($event);
        self::assertNotEmpty($answers);
        self::assertStringContainsString('/summarize', $answers[0]);
        self::assertStringContainsString('/stop', $answers[0]);
    }

    public function testStopDisablesTheRoomAndStartReenablesIt(): void {
        [$listener, $ollama, $roomState] = $this->harness();
        $ollama->expects(self::never())->method('chat');

        $calls = [];
        $roomState->method('setEnabled')->willReturnCallback(
            static function (int $roomId, bool $enabled) use (&$calls): void {
                $calls[] = [$roomId, $enabled];
            }
        );

        $stop = $this->event('@Eva /stop');
        $listener->handle($stop);
        self::assertStringContainsString('pausiert', $this->answers($stop)[0]);

        $start = $this->event('@Eva /start');
        $listener->handle($start);
        self::assertStringContainsString('aktiv', $this->answers($start)[0]);

        self::assertSame([[7, false], [7, true]], $calls);
    }

    public function testDisabledRoomStaysSilentExceptForStart(): void {
        [$listener, $ollama, $roomState] = $this->harness(roomEnabled: false);
        $ollama->expects(self::never())->method('chat');
        $roomState->expects(self::once())->method('setEnabled')->with(7, true);

        // A normal question in a disabled room: no answer at all.
        $quiet = $this->event('@Eva Wie ist das Wetter?');
        $listener->handle($quiet);
        self::assertSame([], $this->answers($quiet));

        // /start is always allowed so the bot can be turned back on.
        $start = $this->event('@Eva /start');
        $listener->handle($start);
        self::assertNotEmpty($this->answers($start));
    }

    public function testSummarizeUsesTheChatModelWithoutClassification(): void {
        [$listener, $ollama] = $this->harness();
        $ollama->expects(self::once())->method('chat')->willReturn([
            'answer' => 'Kurze Zusammenfassung des Raums.',
            'model' => 'test',
        ]);

        $event = $this->event('@Eva /summarize');
        $listener->handle($event);
        self::assertStringContainsString('Kurze Zusammenfassung', $this->answers($event)[0]);
    }

    public function testUnknownSlashMessageFallsBackToNormalHandling(): void {
        // An unknown command is not a command: parseSlashCommand returns empty
        // and the message continues through the regular path. With a mocked
        // RagService::ask returning an error the fallback tries ollama->chat;
        // no command answer (/help) may ever appear.
        [$listener, $ollama] = $this->harness();
        $this->setUpRagError($listener);
        $ollama->method('chat')->willReturn(['answer' => 'Fallback-Antwort', 'model' => 'test']);

        $event = $this->event('@Eva /unknown');
        $listener->handle($event);
        self::assertStringNotContainsString('/help', implode(' ', $this->answers($event)));
    }

    private function setUpRagError(TalkBotListener $listener): void {
        // Replace the mocked RagService with one that reports an error so the
        // regular flow takes the fallback path instead of crashing.
        $reflection = new \ReflectionClass(TalkBotListener::class);
        $property = $reflection->getProperty('ragService');
        $rag = $this->createMock(RagService::class);
        $rag->method('ask')->willReturn(['answer' => '', 'error' => 'offline', 'model' => 'x']);
        $property->setValue($listener, $rag);
    }

    public function testParseSlashCommandStripsMentionAndValidates(): void {
        [$listener] = $this->harness();
        $reflection = new \ReflectionClass(TalkBotListener::class);
        $method = $reflection->getMethod('parseSlashCommand');

        $help = $method->invoke($listener, '@Eva /help');
        self::assertSame('help', $help['name']);

        $summarize = $method->invoke($listener, '@eva /summarize bitte');
        self::assertSame('summarize', $summarize['name']);
        self::assertSame('bitte', $summarize['arg']);

        $plain = $method->invoke($listener, 'Was ist los?');
        self::assertSame('', $plain['name']);

        $invalid = $method->invoke($listener, '@Eva /delete-everything');
        self::assertSame('', $invalid['name']);
    }
}