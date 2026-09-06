<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase {
    public function testModernAndLegacyCatalogsAgreeAndPreservePlaceholders(): void {
        $root = dirname(__DIR__);
        $english = json_decode(file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['en', 'de'] as $language) {
            $catalog = json_decode(file_get_contents($root . '/l10n/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(array_keys($english['translations']), array_keys($catalog['translations']));
            $legacy = file_get_contents($root . '/l10n/' . $language . '.js');
            self::assertSame(1, preg_match('/register\(\s*["\']eva_ai["\']\s*,\s*(\{.*\})\s*,/s', $legacy, $match));
            self::assertSame($catalog['translations'], json_decode($match[1], true, 512, JSON_THROW_ON_ERROR));
            foreach ($catalog['translations'] as $source => $translation) {
                preg_match_all('/\{(\w+)\}/', $source, $expected);
                preg_match_all('/\{(\w+)\}/', $translation, $actual);
                sort($expected[0]);
                sort($actual[0]);
                self::assertSame($expected[0], $actual[0], $language . ': ' . $source);
            }
        }
    }
    public function testFrontendLiteralTranslationKeysHaveCatalogEntries(): void {
        $root = dirname(__DIR__);
        $catalog = json_decode(file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR)['translations'];
        $files = [$root . '/js/chat.js'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['js', 'vue'], true)) {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, "\$t('")) {
                if (preg_match_all('/\$t\x27((?:\\.|[^\x27\\\\])*)\x27/', $content, $m)) {
                    foreach ($m[1] as $key) {
                        $key = str_replace(["'" => "'", '\\' => '\\'], $key);
                        if ('eva_ai' === $key) {
                            continue;
                        }
                        self::assertArrayHasKey($key, $catalog, $file . ': ' . $key);
                    }
                }
            }
            if (preg_match_all('/\b[tT]r\x27((?:\\.|[^\x27\\\\])*)\x27/', $content, $m)) {
                foreach ($m[1] as $key) {
                    $key = str_replace(["'" => "'", '\\' => '\\'], $key);
                    if ($key === 'eva_ai') {
                        continue;
                    }
                    self::assertArrayHasKey($key, $catalog, $file . ': ' . $key);
                }
            }
        }
    }
}
