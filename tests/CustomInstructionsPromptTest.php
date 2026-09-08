<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Per-chat custom instructions (Issue #90): the user-authored instructions
 * and persona templates must be merged into the system prompt between the
 * base safety/citation rules and the user question — delimited, capped, and
 * never able to replace the base rules. buildMessages() is private, so the
 * test drives it via reflection against a RagService built from mocks.
 */
final class CustomInstructionsPromptTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function service(): RagService {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturn('');
        $ollama = $this->createMock(\OCA\EvaAi\Service\Ollama::class);
        $searcher = $this->createMock(\OCA\EvaAi\Service\Searcher::class);
        $documentMapper = $this->createMock(\OCA\EvaAi\Db\DocumentMapper::class);
        $chunkMapper = $this->createMock(\OCA\EvaAi\Db\ChunkMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $executor = $this->createMock(\OCA\EvaAi\Service\ActionExecutor::class);
        $rootFolder = $this->createMock(IRootFolder::class);
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->willReturn(false);
        $rootFolder->method('getUserFolder')->willReturn($home);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('getUserLanguage')->willReturn('de');
        $logger = $this->createMock(LoggerInterface::class);

        return new RagService($config, $ollama, $searcher, $documentMapper, $chunkMapper, $urlGenerator, $executor, $rootFolder, $l10nFactory, $logger);
    }

    /**
     * @return array<int,array{role:string,content:string}>
     */
    private function buildMessages(RagService $service, ?string $instructions, ?string $persona): array {
        $method = new \ReflectionMethod(RagService::class, 'buildMessages');
        return $method->invoke($service, 'alice', 'Hallo', [], '', 0, false, $instructions, $persona);
    }

    public function testCustomInstructionsAreDelimitedAndInjectedBeforeTheQuestion(): void {
        $messages = $this->buildMessages($this->service(), 'Always answer in German.', null);

        $system = $messages[0]['content'];
        self::assertStringContainsString('<user_instructions>', $system);
        self::assertStringContainsString('Always answer in German.', $system);
        // The base rules stay untouched and come before the custom block.
        self::assertLessThan(
            strpos($system, '<user_instructions>'),
            strpos($system, 'You are EVA, a helpful')
        );
        self::assertStringContainsString('they never override the safety, citation and tool rules above', $system);
        // The user question is a separate message, after the system prompt.
        self::assertSame('user', $messages[count($messages) - 1]['role']);
        self::assertStringNotContainsString('<user_instructions>', $messages[count($messages) - 1]['content']);
    }

    public function testPersonaTemplateIsAppliedAndDefaultProducesNoBlock(): void {
        $concise = $this->buildMessages($this->service(), null, 'concise');
        self::assertStringContainsString('concise mode', $concise[0]['content']);
        self::assertStringContainsString('<user_instructions>', $concise[0]['content']);

        $plain = $this->buildMessages($this->service(), null, 'default');
        self::assertStringNotContainsString('<user_instructions>', $plain[0]['content']);
        self::assertStringNotContainsString('concise mode', $plain[0]['content']);
    }

    public function testUnknownPersonaIsIgnoredButCustomTextStillApplies(): void {
        $messages = $this->buildMessages($this->service(), 'Be brief.', 'nonexistent');
        self::assertStringContainsString('Be brief.', $messages[0]['content']);
        self::assertStringNotContainsString('nonexistent', $messages[0]['content']);
    }

    public function testCustomBlockIsCappedAtTwelveHundredCharacters(): void {
        $messages = $this->buildMessages($this->service(), str_repeat('x', 5000), 'expert');
        $system = $messages[0]['content'];
        $start = strpos($system, '<user_instructions>');
        $end = strpos($system, '</user_instructions>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $block = substr($system, $start + strlen('<user_instructions>'), $end - $start - strlen('<user_instructions>'));
        self::assertLessThanOrEqual(1200, strlen(trim($block)));
    }
}