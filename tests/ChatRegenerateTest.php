<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\ApiController;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCP\IRequest;
use OCP\AppFramework\Http\IOutput;
use PHPUnit\Framework\TestCase;

final class ChatRegenerateTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud interfaces unavailable');
        }
    }

    private function controller(?string $user, array $params, ?array $chat): array {
        $reflection = new \ReflectionClass(ApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $store = $this->createMock(ChatStore::class);
        $rag = $this->createMock(RagService::class);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $store->method('getChat')->with('alice', 'chat')->willReturn($chat);
        foreach (['userId' => $user, 'request' => $request, 'chatStore' => $store, 'ragService' => $rag, 'bodyParams' => $params] as $name => $value) {
            $reflection->getProperty($name)->setValue($controller, $value);
        }
        return [$controller, $store, $rag];
    }

    public function testErrorResponsesDoNotMutateChatsOrCallModel(): void {
        $chat = ['messages' => [['role' => 'user', 'text' => 'Hello'], ['role' => 'assistant', 'text' => 'Hi']]];
        foreach ([
            [null, [], null, 401],
            ['alice', [], null, 404],
            ['alice', ['messageIndex' => 99], $chat, 400],
            ['alice', ['messageIndex' => 1], $chat, 400],
            ['alice', ['messageIndex' => 0, 'message' => '  '], $chat, 400],
            ['alice', ['messageIndex' => []], $chat, 400],
            ['alice', ['messageIndex' => 0, 'message' => []], $chat, 400],
        ] as [$user, $params, $stored, $status]) {
            [$controller, $store, $rag] = $this->controller($user, $params, $stored);
            if ($user === null) {
                $store->expects(self::never())->method('beginRegenerate');
            } else {
                $store->method('beginRegenerate')->willReturn(
                    $stored === null ? ['ok' => false, 'error' => 'not_found'] : ['ok' => false, 'error' => 'invalid']
                );
            }
            // Regenerate validation must never truncate, replace or call the model.
            $store->expects(self::never())->method('truncateAfter');
            $store->expects(self::never())->method('replaceMessage');
            $rag->expects(self::never())->method('askStream');
            $response = $controller->chatRegenerate('chat');
            self::assertSame($status, $response->getStatus());
            self::assertSame('application/x-ndjson', (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response)['Content-Type']);
        }
    }

    public function testConflictIsStreamedAsInlineErrorWithoutCallingTheModel(): void {
        $chat = ['messages' => [['role' => 'user', 'text' => 'Hello'], ['role' => 'assistant', 'text' => 'Hi']], 'rev' => 3];
        [$controller, $store, $rag] = $this->controller('alice', ['messageIndex' => 0, 'rev' => 3], $chat);
        // Issue #182: a pending regeneration from another base revision is a
        // concurrent-edit conflict; the stored history stays untouched.
        $store->method('beginRegenerate')->with('alice', 'chat', 0, null, 3)
            ->willReturn(['ok' => false, 'error' => 'conflict']);
        $store->expects(self::never())->method('truncateAfter');
        $store->expects(self::never())->method('replaceMessage');
        $rag->expects(self::never())->method('askStream');
        $response = $controller->chatRegenerate('chat');
        self::assertSame(200, $response->getStatus());
        $output = $this->createMock(IOutput::class);
        $lines = [];
        $output->method('setOutput')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        $response->callback($output);
        self::assertSame('error', $lines[0]['type']);
        self::assertStringContainsString('another tab', $lines[0]['message']);
    }

    public function testRegenerateStreamsLeadingTokenThenAnswerWithStoredContextAndEditedPrompt(): void {
        $chat = [
            'messages' => [['role' => 'user', 'text' => 'First'], ['role' => 'assistant', 'text' => 'Answer'], ['role' => 'user', 'text' => 'Old'], ['role' => 'assistant', 'text' => 'Obsolete']],
            'scopePath' => '/Work', 'instructions' => 'Be brief', 'persona' => 'editor',
            'rev' => 4,
        ];
        [$controller, $store, $rag] = $this->controller('alice', ['messageIndex' => 2, 'message' => ' Edited ', 'rev' => 4], $chat);
        // Issue #182: nothing is truncated up front; the client gets a
        // revision token and the truncation is committed only when the
        // persisted answer carries that token.
        $store->method('beginRegenerate')->with('alice', 'chat', 2, 'Edited', 4)
            ->willReturn(['ok' => true, 'rev' => 5, 'messageIndex' => 2, 'targetText' => 'Edited']);
        $store->expects(self::never())->method('truncateAfter');
        $store->expects(self::never())->method('replaceMessage');
        $store->expects(self::once())->method('getChat')->with('alice', 'chat')->willReturn($chat);
        $line = "{\"type\":\"done\",\"answer\":\"New\"}\n";
        $rag->expects(self::once())->method('askStream')->with('alice', 'Edited', [
            ['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'Answer'],
        ], '/Work', 'Be brief', 'editor')->willReturnCallback(static function () use ($line): \Generator { yield $line; });
        $response = $controller->chatRegenerate('chat');
        self::assertSame(200, $response->getStatus());
        self::assertSame('no', (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response)['X-Accel-Buffering']);
        $output = $this->createMock(IOutput::class);
        $lines = [];
        $output->method('setOutput')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        $response->callback($output);
        self::assertSame('regenerate', $lines[0]['type']);
        self::assertSame(5, $lines[0]['rev']);
        self::assertSame('done', $lines[1]['type']);
        self::assertSame('New', $lines[1]['answer']);
    }
}