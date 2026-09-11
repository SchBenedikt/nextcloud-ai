<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\IndexScheduler;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The indexing throughput controls live in the admin scope. This guards the two
 * regressions the admin page actually shipped with: the keys were missing from
 * ADMIN_SETTINGS (so the save endpoint ignored them) and the generic
 * "remaining admin key is a boolean" fallback rejected their numeric values.
 */
final class AdminSettingsConfigTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function config(): AppConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn(string $app, string $key, string $default): string => $default
        );
        $config->method('getUserValue')->willReturnCallback(
            static fn(string $user, string $app, string $key, string $default): string => $default
        );
        return new AppConfig($config);
    }

    /**
     * @return list<array{0:string,1:int,2:int}>
     */
    private function throughputKeys(): array {
        return [
            ['index_max_concurrent', 1, 16],
            ['index_job_max_seconds', 10, 600],
        ];
    }

    public function testThroughputKeysAreAdminSettings(): void {
        foreach ($this->throughputKeys() as [$key, $min, $max]) {
            self::assertContains($key, AppConfig::ADMIN_SETTINGS);
            self::assertTrue($this->config()->isAdminSetting($key));
            self::assertArrayHasKey($key, AppConfig::LIMITS);
            self::assertSame([$min, $max], AppConfig::LIMITS[$key]);
        }
    }

    public function testThroughputValuesAreRangeValidated(): void {
        $config = $this->config();

        foreach ($this->throughputKeys() as [$key, $min, $max]) {
            // The numeric value must not be rejected as a non-boolean, which is
            // exactly what the old admin fallback did.
            self::assertNull($config->validateValue($key, (string)$min), $key);
            self::assertNull($config->validateValue($key, (string)$max), $key);
            self::assertNotNull($config->validateValue($key, (string)($min - 1)), $key);
            self::assertNotNull($config->validateValue($key, (string)($max + 1)), $key);
            self::assertNotNull($config->validateValue($key, 'not-a-number'), $key);
        }
    }

    public function testDefaultsMatchTheDocumentedScheduler(): void {
        $config = $this->config();
        self::assertSame('2', $config->get('index_max_concurrent'));
        self::assertSame('50', $config->get('index_job_max_seconds'));

        $scheduler = new IndexScheduler(
            $config,
            $this->createMock(\OCP\Lock\ILockingProvider::class),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
        $overview = $scheduler->overview();
        self::assertSame(2, $overview['limit']);
        self::assertSame(0, $overview['running']);
    }
}
