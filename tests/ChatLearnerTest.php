<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ChatLearner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatLearner::class)]
class ChatLearnerTest extends TestCase {
    public function testRegisteredLearningSettingStopsLearningBeforeReadingUserFiles(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available');
        }
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->expects(self::once())->method('setUserId')->with('alice');
        $config->expects(self::once())->method('get')->with('learning_enabled')->willReturn('0');
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');
        $learner = new ChatLearner($root, $config, $this->createMock(\Psr\Log\LoggerInterface::class));

        $learner->learnFromChat('alice', [
            ['role' => 'user', 'text' => 'I prefer local models for private projects.'],
        ]);
    }

    public function testExtractsGermanPersonalFacts(): void {
        $learner = (new \ReflectionClass(ChatLearner::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ChatLearner::class, 'extractFacts');

        $facts = $method->invoke($learner, [
            ['role' => 'user', 'text' => 'Ich bevorzuge lokale Modelle für vertrauliche Projekte.'],
            ['role' => 'assistant', 'text' => 'Verstanden.'],
        ]);

        self::assertSame(['Ich bevorzuge lokale Modelle für vertrauliche Projekte.'], $facts);
    }

    public function testDoesNotLearnAssistantTextOrQuestions(): void {
        $learner = (new \ReflectionClass(ChatLearner::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ChatLearner::class, 'extractFacts');

        $facts = $method->invoke($learner, [
            ['role' => 'assistant', 'text' => 'Ich bevorzuge sichere Konfigurationen.'],
            ['role' => 'user', 'text' => 'Ich möchte eine sichere Konfiguration?'],
        ]);

        self::assertSame([], $facts);
    }
}
