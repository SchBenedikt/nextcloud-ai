<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\L10N\IFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The tool rules in the system prompt have to match the tool policy.
 *
 * A tool the model is told about but that the policy refuses is worse than no
 * tool at all: the model announces it, the call fails, and the user is told
 * about a capability that does not exist. The two Talk halves are conditional
 * for different reasons - reading needs Talk to be installed, posting needs the
 * user's opt-in - so each combination is checked here.
 *
 * The prompt is built by a private method, so it is driven through reflection
 * against a RagService assembled from mocks.
 */
final class TalkPromptContractTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function service(bool $talkAvailable, int $writeEnabled): RagService {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturn('');
        $config->method('getInt')->willReturnCallback(
            static fn(string $key, ?int $default = null): int => $key === 'talk_write_enabled' ? $writeEnabled : (int)($default ?? 0)
        );
        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isAvailable')->willReturn($talkAvailable);
        $rootFolder = $this->createMock(IRootFolder::class);
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->willReturn(false);
        $rootFolder->method('getUserFolder')->willReturn($home);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('getUserLanguage')->willReturn('de');

        return new RagService(
            $config,
            $this->createMock(Ollama::class),
            $this->createMock(\OCA\EvaAi\Service\Searcher::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(ChunkMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ActionExecutor::class),
            $rootFolder,
            $l10nFactory,
            $transcripts,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @return array<int,array{role:string,content:string}> */
    private function messages(RagService $service, bool $actions): array {
        $method = new \ReflectionMethod(RagService::class, 'buildMessages');
        return $method->invoke($service, 'alice', 'Hallo', [], '', 0, $actions);
    }

    private function system(RagService $service, bool $actions = true): string {
        return (string)($this->messages($service, $actions)[0]['content'] ?? '');
    }

    public function testReadingIsDescribedWhenTalkIsInstalled(): void {
        $system = $this->system($this->service(true, 0));
        self::assertStringContainsString('read_talk_chat', $system);
        self::assertStringContainsString('list_talk_rooms', $system);
        // Posting is still opt-in, so the model must not be told about it.
        self::assertStringNotContainsString('send_talk_message', $system);
    }

    public function testPostingIsDescribedOnlyOnceTheUserEnabledIt(): void {
        $system = $this->system($this->service(true, 1));
        self::assertStringContainsString('send_talk_message', $system);
        // The one thing that must be unmistakable: the message goes out as the
        // user, so it is only used on an explicit request.
        self::assertStringContainsString("under the user's own name", $system);
        self::assertStringContainsString('explicitly asks', $system);
    }

    public function testNoTalkRulesWithoutTalk(): void {
        $system = $this->system($this->service(false, 1));
        self::assertStringNotContainsString('read_talk_chat', $system);
        self::assertStringNotContainsString('send_talk_message', $system);
        self::assertStringNotContainsString('list_talk_rooms', $system);
    }

    public function testNoTalkRulesWhenActionsAreDisabled(): void {
        $system = $this->system($this->service(true, 1), false);
        self::assertStringNotContainsString('read_talk_chat', $system);
        self::assertStringNotContainsString('send_talk_message', $system);
    }
}
