<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AgentStore;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\TaskProcessing\AgentInteractionProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AgentConfirmationTest extends TestCase {
    public function testSummaryFailureCannotLeaveExecutedActionsPending(): void {
        if (!EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
        foreach (['error', 'exception', 'empty'] as $failure) {
            $reflection = new \ReflectionClass(AgentInteractionProvider::class);
            $provider = $reflection->newInstanceWithoutConstructor();
            $executor = $this->createMock(ActionExecutor::class);
            $executor->expects(self::once())->method('runConfirmed')->with('alice', 'create_file', ['path' => 'test.txt'])
                ->willReturn(['ok' => false, 'error' => 'Permission denied']);
            $saved = false;
            $store = $this->createMock(AgentStore::class);
            $store->expects(self::exactly(2))->method('save')->willReturnCallback(
                static function ($user, $token, $history, $pending) use (&$saved): void {
                    self::assertSame([], $pending);
                    self::assertStringContainsString('Permission denied', $history[count($history) - 1]['content']);
                    $saved = true;
                }
            );
            $ollama = $this->createMock(Ollama::class);
            $ollama->method('chat')->willReturnCallback(static function () use (&$saved, $failure): array {
                self::assertTrue($saved, 'Clear pending actions before asking for the summary');
                if ($failure === 'exception') {
                    throw new \RuntimeException('Network failure');
                }
                return $failure === 'error' ? ['error' => 'Timeout'] : ['answer' => ''];
            });
            foreach (['executor' => $executor, 'store' => $store, 'ollama' => $ollama, 'logger' => $this->createMock(LoggerInterface::class)] as $property => $value) {
                $reflection->getProperty($property)->setValue($provider, $value);
            }
            $result = $reflection->getMethod('runConfirmed')->invoke($provider, 'alice', [], 'test-token', [], [
                ['name' => 'create_file', 'args' => ['path' => 'test.txt']],
            ]);
            self::assertSame('', $result['actions']);
            self::assertStringContainsString('Some confirmed actions failed', $result['output']);
        }
    }
}
