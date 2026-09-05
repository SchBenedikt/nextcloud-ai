<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\RagService;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #71: RagService::filterAccessible() called
 * $this->logger->info(...) in the access-revocation purge path, but no
 * logger was ever injected into RagService. That threw an undefined
 * property \Error which was silently swallowed by the surrounding
 * try/catch, so the audit log entry was never written.
 *
 * This does not require a running Nextcloud instance: reflecting on the
 * constructor signature does not need the referenced OCP classes to be
 * loadable, only declared.
 */
final class RagServiceLoggerRegressionTest extends TestCase {
    public function testConstructorRequiresAPsrLogger(): void {
        $reflection = new \ReflectionClass(RagService::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor, 'RagService must declare a constructor');

        $loggerParam = null;
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === 'logger') {
                $loggerParam = $parameter;
                break;
            }
        }

        self::assertNotNull($loggerParam, 'RagService constructor must accept a $logger parameter');
        $type = $loggerParam->getType();
        self::assertNotNull($type, '$logger parameter must be typed');
        self::assertSame(\Psr\Log\LoggerInterface::class, (string)$type, '$logger must be typed as Psr\\Log\\LoggerInterface so Nextcloud\'s DI container can autowire it');

        // Constructor-promoted properties do not appear as separate class
        // properties, so this also proves $this->logger is now a real,
        // defined property instead of an undefined one.
        self::assertTrue($reflection->hasProperty('logger'), 'RagService must have a $logger property backing $this->logger');
    }

    public function testPurgePathLogsThroughTheInjectedLogger(): void {
        $source = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        $start = strpos($source, 'private function filterAccessible');
        self::assertNotFalse($start, 'filterAccessible() must exist');
        $method = substr($source, $start);

        self::assertStringContainsString('$this->logger->info(', $method, 'the purge path must still log via $this->logger');
    }
}
