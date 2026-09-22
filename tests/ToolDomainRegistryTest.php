<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ToolDomainRegistry;
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
}
