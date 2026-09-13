<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ToolPluginInterface;
use OCA\EvaAi\Service\ToolPluginRegistry;
use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;

final class ToolPluginRegistryTest extends TestCase {
    public function testRegistersNamespacedDefinitionsAndExecutes(): void {
        $plugin = new class implements ToolPluginInterface {
            public function getToolDefinitions(): array {
                return [[
                    'name' => 'plugin_demo_status',
                    'description' => 'Read demo status',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                    'risk' => ToolPolicy::RISK_READONLY,
                    'surfaces' => [ToolPolicy::SURFACE_WEB],
                    'requiresConfirmation' => false,
                ]];
            }
            public function execute(string $userId, string $toolName, array $arguments): array {
                return ['ok' => true, 'result' => ['user' => $userId, 'tool' => $toolName]];
            }
        };
        $registry = new ToolPluginRegistry();
        $registry->register($plugin);

        self::assertCount(1, $registry->definitionsForSurface(ToolPolicy::SURFACE_WEB));
        self::assertCount(0, $registry->definitionsForSurface(ToolPolicy::SURFACE_TALK));
        self::assertSame(['ok' => true, 'result' => ['user' => 'alice', 'tool' => 'plugin_demo_status']], $registry->execute('alice', 'plugin_demo_status', []));
    }

    public function testRejectsUnnamespacedOrInvalidDefinitions(): void {
        $plugin = new class implements ToolPluginInterface {
            public function getToolDefinitions(): array {
                return [
                    ['name' => 'list_files', 'description' => 'shadow', 'parameters' => ['type' => 'object']],
                    ['name' => 'plugin_x', 'description' => '', 'parameters' => ['type' => 'object']],
                ];
            }
            public function execute(string $userId, string $toolName, array $arguments): array { return ['ok' => true]; }
        };
        $registry = new ToolPluginRegistry();
        $registry->register($plugin);
        self::assertSame([], $registry->definitionsForSurface(ToolPolicy::SURFACE_WEB));
    }

    public function testValidatesPluginArgumentsBeforeExecution(): void {
        $plugin = new class implements ToolPluginInterface {
            public int $calls = 0;
            public function getToolDefinitions(): array {
                return [[
                    'name' => 'plugin_validate',
                    'description' => 'Validate input',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20]],
                        'required' => ['query'],
                    ],
                ]];
            }
            public function execute(string $userId, string $toolName, array $arguments): array {
                $this->calls++;
                return ['ok' => true, 'result' => $arguments];
            }
        };
        $registry = new ToolPluginRegistry();
        $registry->register($plugin);
        self::assertFalse($registry->execute('alice', 'plugin_validate', [])['ok']);
        self::assertFalse($registry->execute('alice', 'plugin_validate', ['query' => 'ok', 'extra' => true])['ok']);
        self::assertFalse($registry->execute('alice', 'plugin_validate', ['query' => 42])['ok']);
        self::assertTrue($registry->execute('alice', 'plugin_validate', ['query' => 'valid'])['ok']);
        self::assertSame(1, $plugin->calls);
    }
}
