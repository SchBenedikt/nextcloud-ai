<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Issue #118: the configuration reference must not drift from the code. This
 * test asserts that every AppConfig default and limit key is documented in
 * docs/CONFIGURATION.md together with its default and accepted range.
 */
final class ConfigurationDocumentationTest extends TestCase {
    private const DOC = __DIR__ . '/../docs/CONFIGURATION.md';

    private function defaults(): array {
        $reflection = new ReflectionClass(AppConfig::class);
        return $reflection->getConstant('DEFAULTS');
    }

    private function userSettings(): array {
        $reflection = new ReflectionClass(AppConfig::class);
        return $reflection->getConstant('USER_SETTINGS');
    }

    public function testEveryAppConfigKeyIsDocumented(): void {
        $doc = (string)file_get_contents(self::DOC);
        $defaults = $this->defaults();
        $settings = $this->userSettings();
        foreach (array_keys($defaults) as $key) {
            self::assertTrue(
                str_contains($doc, '`' . $key . '`'),
                "Key '$key' is missing from docs/CONFIGURATION.md"
            );
        }
        foreach ($settings as $key) {
            self::assertTrue(
                str_contains($doc, '`' . $key . '`'),
                "User setting '$key' is missing from docs/CONFIGURATION.md"
            );
        }
    }

    public function testEveryDefaultValueIsDocumented(): void {
        $doc = (string)file_get_contents(self::DOC);
        foreach ($this->defaults() as $key => $value) {
            // Short scalar defaults (numbers, booleans, small strings) must
            // appear literally so the doc cannot silently drift.
            if ($value === '' || strlen((string)$value) > 40) {
                continue;
            }
            self::assertTrue(
                str_contains($doc, '`' . $value . '`')
                || str_contains($doc, (string)$value),
                "Default '{$value}' of '$key' is missing from docs/CONFIGURATION.md"
            );
        }
    }

    public function testEveryLimitIsDocumented(): void {
        $doc = (string)file_get_contents(self::DOC);
        $limits = AppConfig::LIMITS;
        foreach ($limits as $key => [$min, $max]) {
            self::assertTrue(
                str_contains($doc, '`' . $min . '`') && str_contains($doc, '`' . $max . '`'),
                "Limit range {$min}–{$max} of '$key' is missing from docs/CONFIGURATION.md"
            );
        }
    }
}
