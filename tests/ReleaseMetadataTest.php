<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase {
    public function testFrontendAndAppVersionsAgree(): void {
        $root = __DIR__ . '/..';
        $info = simplexml_load_file($root . '/appinfo/info.xml');
        $package = json_decode(file_get_contents($root . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($root . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((string)$info->version, $package['version']);
        self::assertSame($package['version'], $lock['version']);
        self::assertSame($package['version'], $lock['packages']['']['version']);
    }
}
