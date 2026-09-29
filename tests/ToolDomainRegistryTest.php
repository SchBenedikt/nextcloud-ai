<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ToolDomainRegistry;
use OCA\EvaAi\Service\DomainToolExecutor;
use PHPUnit\Framework\TestCase;

final class ToolDomainRegistryTest extends TestCase {
    public function testDispatchesRegisteredDomainHandler(): void {
        $registry = new ToolDomainRegistry();
        $registry->register('list_tasks', static fn(string $user, array $args): array => [
            'ok' => true,
            'result' => ['user' => $user, 'limit' => $args['limit'] ?? 10],
        ]);

        self::assertTrue($registry->has('list_tasks'));
        self::assertSame(['ok' => true, 'result' => ['user' => 'alice', 'limit' => 3]], $registry->execute('list_tasks', 'alice', ['limit' => 3]));
        self::assertNull($registry->execute('unknown', 'alice', []));
    }

    public function testRegistersEveryToolExposedByAnExecutor(): void {
        $executor = new class implements DomainToolExecutor {
            public function tools(): array {
                return ['read_one', 'read_many'];
            }

            public function execute(string $tool, string $userId, array $args): array {
                return ['ok' => true, 'result' => [$tool, $userId, $args]];
            }
        };
        $registry = new ToolDomainRegistry();
        $registry->registerExecutor($executor);

        self::assertTrue($registry->has('read_one'));
        self::assertTrue($registry->has('read_many'));
        self::assertSame(['ok' => true, 'result' => ['read_many', 'alice', ['limit' => 5]]], $registry->execute('read_many', 'alice', ['limit' => 5]));
    }
}
