<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ChatLearner;
use PHPUnit\Framework\TestCase;

/** @covers \OCA\EvaAi\Service\ChatLearner */
class ChatLearnerTest extends TestCase {
    public function testUsesTheRegisteredLearningSetting(): void {
        $source = (string)file_get_contents(__DIR__ . '/../lib/Service/ChatLearner.php');
        self::assertStringContainsString("get('learning_enabled')", $source);
        self::assertStringNotContainsString('chat_learning_enabled', $source);
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
