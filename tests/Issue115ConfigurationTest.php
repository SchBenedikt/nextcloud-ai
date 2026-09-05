<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use PHPUnit\Framework\TestCase;

final class Issue115ConfigurationTest extends TestCase {
    public function testWriteTypesAcceptAndNormalizeExtensions(): void {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();

        self::assertNull($config->validateValue('exec_write_types', ' MD, .txt,md '));
        self::assertSame('md,txt', $config->normalizeValue('exec_write_types', ' MD, .txt,md '));
        self::assertSame('', $config->normalizeValue('exec_write_types', ' '));
        self::assertSame('*', $config->normalizeValue('exec_write_types', '*'));
    }

    public function testWriteTypesRejectMalformedValues(): void {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();

        self::assertNotNull($config->validateValue('exec_write_types', ['md']));
        self::assertNotNull($config->validateValue('exec_write_types', 'md,../../secret'));
        self::assertNotNull($config->validateValue('exec_write_types', '*,md'));
        self::assertNotNull($config->validateValue('exec_write_types', str_repeat('a,', 33) . 'md'));
    }
}
