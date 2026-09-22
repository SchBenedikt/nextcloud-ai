<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatRequest;
use PHPUnit\Framework\TestCase;

final class ChatRequestTest extends TestCase {
    public function testNamedContextAndFlagsAreRetained(): void {
        $request = new ChatRequest(
            userId: 'alice',
            message: 'Find the report',
            history: [['role' => 'user', 'content' => 'Earlier']],
            scopePath: '/Documents',
            instructions: 'Be concise',
            persona: 'Researcher',
            extraContext: 'Room recall',
            allowActions: false,
            autonomousActions: true,
        );

        self::assertSame('alice', $request->userId);
        self::assertSame('/Documents', $request->scopePath);
        self::assertFalse($request->allowActions);
        self::assertTrue($request->autonomousActions);
        self::assertNull($request->shouldStop);
    }

    public function testCallbacksAreNormalizedToClosures(): void {
        $request = new ChatRequest(
            userId: 'alice',
            message: 'Continue',
            shouldStop: 'is_string',
            onProgress: static function (): void {},
        );

        self::assertInstanceOf(\Closure::class, $request->shouldStop);
        self::assertInstanceOf(\Closure::class, $request->onProgress);
    }
}
