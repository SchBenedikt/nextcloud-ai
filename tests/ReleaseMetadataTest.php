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

    /**
     * Nextcloud only runs an app's update steps when the version in info.xml is
     * greater than the installed one. A migration whose version exceeds the app
     * version therefore never executes, silently leaving the schema (and the
     * feature that needs it) broken. Guard that invariant here.
     */
    public function testAppVersionCoversEveryMigration(): void {
        $root = __DIR__ . '/..';
        $info = simplexml_load_file($root . '/appinfo/info.xml');
        $appVersion = (string)$info->version;

        $migrations = glob($root . '/lib/Migration/Version*.php') ?: [];
        self::assertNotEmpty($migrations, 'expected at least one migration to validate');

        // Migration names encode the target app version as
        // Version<MAJOR><MINOR:02><PATCH:03>Date<timestamp>, e.g. 1.4.9
        // -> Version104009Date... Compare on the encoded integer so the check
        // stays independent of how the digits are split back apart.
        $parts = explode('.', $appVersion);
        self::assertCount(3, $parts, 'info.xml <version> must be MAJOR.MINOR.PATCH, got ' . $appVersion);
        $encodedAppVersion = (int)sprintf('%d%02d%03d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);

        foreach ($migrations as $file) {
            self::assertSame(
                1,
                preg_match('/^Version(\d+)Date/', basename($file), $m),
                'Migration must be named Version<digits>Date<timestamp>: ' . basename($file),
            );
            self::assertLessThanOrEqual(
                $encodedAppVersion,
                (int)$m[1],
                sprintf(
                    'Migration %s targets a version above %s; bump <version> in appinfo/info.xml so Nextcloud runs it.',
                    basename($file),
                    $appVersion,
                ),
            );
        }
    }
}
