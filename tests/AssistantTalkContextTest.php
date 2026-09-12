<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCA\EvaAi\TaskProcessing\AgentInteractionProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Assistant can be asked to answer from a Talk conversation.
 *
 * The room ids arrive as task input rather than from Talk, so they are a claim,
 * not a fact: the provider has to confirm the user is in the room before one
 * message of it reaches the model. The recent window alone is also not enough to
 * answer a question about something said weeks ago, so an indexed history
 * contributes the older passages that match.
 */
final class AssistantTalkContextTest extends TestCase {
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * @return array{0:AgentInteractionProvider,1:TalkTranscriptService}
     */
    private function provider(bool $isMember, array $recall = []): array
    {
        $reflection = new \ReflectionClass(AgentInteractionProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $transcripts = $this->createMock(TalkTranscriptService::class);
        $transcripts->method('isMember')->willReturn($isMember);
        $transcripts->method('recall')->willReturn($recall);
        $reflection->getProperty('talkTranscripts')->setValue($provider, $transcripts);

        $reader = $this->createMock(TalkContextReader::class);
        $reader->method('buildHistoryMessages')->willReturn([
            ['role' => 'user', 'content' => 'Wir sollten das Budget erhöhen.'],
        ]);
        $reflection->getProperty('talkContextReader')->setValue($provider, $reader);
        $reflection->getProperty('logger')->setValue($provider, $this->createMock(LoggerInterface::class));

        return [$provider, $transcripts];
    }

    /** @param list<string|int> $rooms */
    private function context(AgentInteractionProvider $provider, array $rooms, string $prompt = 'Was war das Budget?'): array
    {
        return (new \ReflectionClass(AgentInteractionProvider::class))
            ->getMethod('buildTalkHistoryContext')
            ->invoke($provider, $rooms, 'alice', $prompt);
    }

    public function testARoomTheUserIsNotInContributesNothing(): void {
        [$provider] = $this->provider(isMember: false);
        self::assertSame([], $this->context($provider, [7]));
    }

    public function testAMemberGetsTheRecentConversation(): void {
        [$provider] = $this->provider(isMember: true);
        $context = $this->context($provider, [7]);

        self::assertCount(1, $context);
        self::assertStringContainsString('Room #7', $context[0]['content']);
        self::assertStringContainsString('Budget erhöhen', $context[0]['content']);
    }

    /** Answers about older parts of the chat need the indexed passages too. */
    public function testIndexedOlderMessagesAreAddedForTheQuestion(): void {
        [$provider] = $this->provider(isMember: true, recall: ['[2026-08-01 09:00] bob: Das Budget liegt bei 50k.']);
        $context = $this->context($provider, [7]);

        self::assertCount(2, $context);
        self::assertStringContainsString('Older messages from the same Talk conversation', $context[1]['content']);
        self::assertStringContainsString('50k', $context[1]['content']);
    }

    /** The room limit still applies, whatever the caller sends. */
    public function testTheNumberOfRoomsIsBounded(): void {
        [$provider] = $this->provider(isMember: true);
        $context = $this->context($provider, [1, 2, 3, 4, 5, 6, 7, 8], '');

        self::assertCount(3, $context);
    }
}
