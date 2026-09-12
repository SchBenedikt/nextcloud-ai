<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\TalkChatService;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Posting into Nextcloud Talk on the user's behalf, and reading a room on
 * request.
 *
 * Three things have to hold for this to be safe, and each is checked here:
 * a room reference from a prompt only resolves to one of the asking user's own
 * rooms, posting is impossible until the user has switched it on, and a message
 * that is empty or too long never reaches Talk at all.
 */
final class TalkChatServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function service(bool $available, int $writeEnabled = 0): TalkChatService {
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isAvailable')->willReturn($available);
        return new TalkChatService($transcripts, $this->config($writeEnabled), $this->createMock(LoggerInterface::class));
    }

    private function config(int $writeEnabled): AppConfig {
        $config = $this->createMock(AppConfig::class);
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => $key === 'talk_write_enabled' ? $writeEnabled : (int)($default ?? 0)
        );
        return $config;
    }

    /** @return list<array{id:int,token:string,name:string,type:string,lastActivity:string}> */
    private function rooms(): array {
        return [
            ['id' => 12, 'token' => 'ab12cd34', 'name' => 'Projekt Alpha', 'type' => 'group', 'lastActivity' => '2026-09-11 09:00'],
            ['id' => 7, 'token' => 'zz99yy88', 'name' => 'Anna Müller', 'type' => 'one-to-one', 'lastActivity' => '2026-09-11 08:00'],
            ['id' => 30, 'token' => 'qq11ww22', 'name' => 'Projekt Alpha Alt', 'type' => 'group', 'lastActivity' => '2026-07-01 10:00'],
        ];
    }

    private function index(): array {
        return TalkChatService::indexRooms($this->rooms());
    }

    // ---- Resolving what the user meant -------------------------------------

    public function testRoomReferenceMatchesByNameTokenAndId(): void {
        $index = $this->index();
        self::assertSame([12], array_column(TalkChatService::matchRoom($index, 'Projekt Alpha'), 'id'));
        self::assertSame([12], array_column(TalkChatService::matchRoom($index, 'ab12cd34'), 'id'));
        self::assertSame([7], array_column(TalkChatService::matchRoom($index, '7'), 'id'));
    }

    public function testRoomReferenceIsCaseInsensitiveAndIgnoresHashAndDashes(): void {
        $index = $this->index();
        self::assertSame([12], array_column(TalkChatService::matchRoom($index, 'projekt alpha'), 'id'));
        self::assertSame([12], array_column(TalkChatService::matchRoom($index, '#projekt-alpha'), 'id'));
    }

    public function testAmbiguousPartialNameReturnsEveryCandidateInsteadOfPickingOne(): void {
        // "Projekt" is the prefix of two rooms: a partial match must offer both
        // candidates so the caller can report the ambiguity, never pick one.
        $index = $this->index();
        $matches = TalkChatService::matchRoom($index, 'Projekt');
        self::assertSame([12, 30], array_values(array_unique(array_column($matches, 'id'))), 'substring match should offer all candidates');
    }

    public function testAnExactNameWinsOverALongerOne(): void {
        $index = $this->index();
        self::assertSame([12], array_column(TalkChatService::matchRoom($index, 'Projekt Alpha'), 'id'));
    }

    public function testUnknownRoomReferenceMatchesNothing(): void {
        self::assertSame([], TalkChatService::matchRoom($this->index(), 'Geheimraum'));
        self::assertSame([], TalkChatService::matchRoom($this->index(), '   '));
    }

    // ---- Reading ------------------------------------------------------------

    public function testReadIsRefusedWithoutTalk(): void {
        $result = $this->service(false)->read('alice', 'Projekt Alpha');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Talk', (string)$result['error']);
    }

    public function testReadOfAnEmptyReferenceResolvesToNoRoom(): void {
        $result = $this->service(true)->read('alice', '   ');
        self::assertFalse($result['ok']);
        // No room list, because there is no Talk here: the reference must fail
        // closed rather than reaching for a room by id.
        self::assertStringContainsString('not a member of any', (string)$result['error']);
    }

    // ---- Posting ------------------------------------------------------------

    public function testPostingIsRefusedUntilTheUserOptsIn(): void {
        $service = $this->service(true, 0);
        self::assertFalse($service->writeEnabled());
        $result = $service->send('alice', 'Projekt Alpha', 'Hallo Team');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('switched off', (string)$result['error']);
        // The message must not even be validated against Talk: the refusal is
        // the opt-in, not the room.
        self::assertArrayNotHasKey('messageId', $result);
    }

    public function testPostingIsRefusedWithoutTalkEvenWhenEnabled(): void {
        $service = $this->service(false, 1);
        self::assertTrue($service->writeEnabled());
        $result = $service->send('alice', 'Projekt Alpha', 'Hallo Team');
        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('messageId', $result);
    }

    public function testEmptyMessageIsRejected(): void {
        $result = $this->service(true, 1)->send('alice', 'Projekt Alpha', "   \n ");
        self::assertFalse($result['ok']);
        self::assertStringContainsString('must not be empty', (string)$result['error']);
    }

    public function testOverlongMessageIsRejectedBeforeTouchingTalk(): void {
        $result = $this->service(true, 1)->send('alice', 'Projekt Alpha', str_repeat('a', TalkChatService::MAX_MESSAGE_CHARS + 1));
        self::assertFalse($result['ok']);
        self::assertStringContainsString('too long', (string)$result['error']);
        self::assertArrayNotHasKey('messageId', $result);
    }

    // ---- The policy boundary ------------------------------------------------

    public function testTheWriteToolIsHiddenFromEverySurfaceUntilTheUserOptsIn(): void {
        $policy = new ToolPolicy($this->config(0));
        $policy->setSurface(ToolPolicy::SURFACE_WEB);
        $check = $policy->check('send_talk_message');
        self::assertFalse($check['allowed']);
        self::assertArrayNotHasKey('send_talk_message', $policy->toolsForSurface());
    }

    public function testTheWriteToolNeedsConfirmationAndIsOfferedOnTheWebSurface(): void {
        $policy = new ToolPolicy($this->config(1));
        $policy->setSurface(ToolPolicy::SURFACE_WEB);
        $check = $policy->check('send_talk_message');
        self::assertTrue($check['allowed']);
        self::assertTrue($check['requiresConfirmation']);

        // The Talk surface is where EVA answers as the bot; posting there would
        // put words in the asking user's mouth in the room they are in.
        $policy->setSurface(ToolPolicy::SURFACE_TALK);
        self::assertFalse($policy->check('send_talk_message')['allowed']);
    }

    public function testReadingIsAvailableInTalkItself(): void {
        $policy = new ToolPolicy($this->config(1));
        $policy->setSurface(ToolPolicy::SURFACE_TALK);
        self::assertTrue($policy->check('read_talk_chat')['allowed']);
        self::assertTrue($policy->check('list_talk_rooms')['allowed']);
    }

    public function testTheTalkToolsAreHiddenWhenTalkIsNotInstalled(): void {
        $talk = $this->createMock(TalkChatService::class);
        $talk->method('isAvailable')->willReturn(false);
        $policy = new ToolPolicy($this->config(1), $talk);
        $policy->setSurface(ToolPolicy::SURFACE_WEB);
        self::assertFalse($policy->check('read_talk_chat')['allowed']);
        self::assertFalse($policy->check('send_talk_message')['allowed']);
        self::assertArrayNotHasKey('read_talk_chat', $policy->toolsForSurface());
    }

    // ---- The contract the model sees ---------------------------------------

    public function testTheThreeTalkToolsAreOfferedToTheModelWithTheirArguments(): void {
        $policy = new ToolPolicy($this->config(1));
        $policy->setSurface(ToolPolicy::SURFACE_WEB);
        $executor = (new \ReflectionClass(ActionExecutor::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ActionExecutor::class, 'toolPolicy'))->setValue($executor, $policy);

        $tools = [];
        foreach ($executor->tools() as $tool) {
            $tools[(string)($tool['function']['name'] ?? '')] = $tool['function'];
        }
        foreach (['list_talk_rooms', 'read_talk_chat', 'send_talk_message'] as $name) {
            self::assertArrayHasKey($name, $tools, 'tool not offered: ' . $name);
            self::assertNotSame('', trim((string)$tools[$name]['description']));
        }
        self::assertSame(['room'], $tools['read_talk_chat']['parameters']['required']);
        self::assertSame(['room', 'message'], $tools['send_talk_message']['parameters']['required']);
        // The model must not be told it can post without saying who writes:
        // the description has to make clear the message goes out as the user.
        self::assertStringContainsString('as the user', (string)$tools['send_talk_message']['description']);
    }
}
