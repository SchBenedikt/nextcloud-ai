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
use OCA\EvaAi\Service\TalkTranscriptService;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bot must answer only when it is addressed.
 *
 * This is the behaviour the user cares about most about the Talk bot: it may
 * follow a conversation, but it must not talk over people. The check is driven
 * through the real entry point (a Talk event into handle()), not through the
 * private helper, so a regression anywhere in the chain - pre-filter,
 * classification, or the answer path - shows up here.
 */
final class TalkBotAddressingTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
        if (!class_exists(BotInvokeEvent::class)) {
            $this->markTestSkipped('The Talk (spreed) app is not installed');
        }
    }

    private function event(string $content, int $roomId = 7): BotInvokeEvent
    {
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

    private function answers(BotInvokeEvent $event): array
    {
        return array_map(
            static fn(array $a): string => (string)($a['message'] ?? ''),
            $event->getAnswers()
        );
    }

    /** @return array{answer:string,sources:array,model:string,error:null} */
    private function answer(): array
    {
        return ['answer' => 'Antwort von EVA', 'sources' => [], 'model' => 'test', 'error' => null];
    }

    /**
     * @param callable():array $chatResponse what the model answers, per call
     * @return array{0:TalkBotListener,1:Ollama,2:RagService}
     */
    private function harness(callable $chatResponse, string $classifyAll = '0'): array
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key) use ($classifyAll): string {
            return match ($key) {
                'talk_bot_trigger' => 'Eva',
                'talk_classify_all' => $classifyAll,
                default => '',
            };
        });

        $ollama = $this->createMock(Ollama::class);
        $ollama->method('chat')->willReturnCallback($chatResponse);

        $roomState = $this->createMock(TalkRoomState::class);
        $roomState->method('isEnabled')->willReturn(true);

        $reader = $this->createMock(TalkContextReader::class);
        $reader->method('buildHistoryMessages')->willReturn([]);

        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('recall')->willReturn([]);

        // `ask` is deliberately left unstubbed here: each test either expects a
        // call (and stubs it) or expects none, and PHPUnit cannot configure the
        // same mocked method twice.
        $rag = $this->createMock(RagService::class);

        $listener = new TalkBotListener(
            $ollama,
            $reader,
            $this->createMock(ActionExecutor::class),
            $config,
            $rag,
            $roomState,
            $transcripts,
            $this->createMock(LoggerInterface::class),
        );
        return [$listener, $ollama, $rag];
    }

    /** The invariant itself: a message that is not for the bot gets no answer. */
    public function testAMessageThatIsNotAddressedStaysUnanswered(): void {
        [$listener, $ollama] = $this->harness(static fn(): array => ['answer' => 'ja', 'model' => 'test']);
        $ollama->expects(self::never())->method('chat');

        $event = $this->event('Ich habe gestern den Kaffee alle gemacht.');
        $listener->handle($event);

        self::assertSame([], $this->answers($event));
    }

    /** Small talk between other people must not be answered either. */
    public function testChatterBetweenOthersIsNotAnswered(): void {
        [$listener, $ollama, $rag] = $this->harness(static fn(): array => ['answer' => 'nein', 'model' => 'test']);
        $rag->expects(self::never())->method('ask');

        $event = $this->event('Sagst du Bob noch Bescheid?');
        $listener->handle($event);

        self::assertSame([], $this->answers($event));
    }

    /** A question the model does not consider directed at it stays unanswered. */
    public function testAClassifiedNoKeepsTheBotSilent(): void {
        [$listener, , $rag] = $this->harness(static fn(): array => ['answer' => 'No, that is for Bob.', 'model' => 'test']);
        $rag->expects(self::never())->method('ask');

        $event = $this->event('Kannst du Bob das Dokument schicken?');
        $listener->handle($event);

        self::assertSame([], $this->answers($event));
    }

    /** Being addressed always answers, without asking the model first. */
    public function testAnExplicitMentionAlwaysAnswers(): void {
        [$listener, $ollama, $rag] = $this->harness(static fn(): array => ['answer' => 'should not be used', 'model' => 'test']);
        $ollama->expects(self::never())->method('chat');
        $rag->expects(self::once())->method('ask')->willReturn($this->answer());

        $event = $this->event('@Eva wie ist das Wetter?');
        $listener->handle($event);

        self::assertCount(1, $this->answers($event));
        self::assertStringContainsString('Antwort von EVA', $this->answers($event)[0]);
    }

    /** The configured name is required; questions without it stay silent. */
    public function testTheConfiguredNameIsRequired(): void {
        [$listener, $ollama, $rag] = $this->harness(static fn(): array => ['answer' => 'yes', 'model' => 'test']);
        $ollama->expects(self::never())->method('chat');
        $rag->expects(self::never())->method('ask');

        $event = $this->event('Kannst du mir das erklären?');
        $listener->handle($event);

        self::assertSame([], $this->answers($event));
    }

    /** With classify-all every message is classified, but only a yes answers. */
    public function testClassifyAllStillRequiresAYes(): void {
        [$listener, , $rag] = $this->harness(static fn(): array => ['answer' => 'no', 'model' => 'test'], classifyAll: '1');
        $rag->expects(self::never())->method('ask');

        $event = $this->event('Der Kaffee ist alle.');
        $listener->handle($event);

        self::assertSame([], $this->answers($event));
    }

    /** A disabled room stays silent for everything except /start. */
    public function testADisabledRoomIgnoresEverythingExceptStart(): void {
        [$listener, , $rag] = $this->harness(static fn(): array => ['answer' => 'yes', 'model' => 'test']);
        $rag->expects(self::never())->method('ask');
        $roomState = $this->createMock(TalkRoomState::class);
        $roomState->method('isEnabled')->willReturn(false);
        $roomState->expects(self::once())->method('setEnabled')->with(7, true);

        $reflection = new \ReflectionClass($listener);
        $reflection->getProperty('roomState')->setValue($listener, $roomState);

        $silly = $this->event('@Eva erzähl mir was');
        $listener->handle($silly);
        self::assertSame([], $this->answers($silly));

        $start = $this->event('@Eva /start');
        $listener->handle($start);
        self::assertNotSame([], $this->answers($start));
    }
}
