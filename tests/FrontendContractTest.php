<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

final class FrontendContractTest extends TestCase {
    public function testDocumentChunkViewKeepsLoadingErrorsAndRetriesVisible(): void {
        $source = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('data?.data?.chunks', $source);
        self::assertStringContainsString("status: 'error'", $source);
        self::assertStringContainsString('loadChunks(d.id, d.chunks, true)', $source);
        self::assertStringContainsString('const reportedExpected = data?.document?.chunks', $source);
        self::assertStringContainsString('aria-expanded', $source);
        self::assertStringContainsString('@keydown.enter.prevent', $source);
        self::assertStringContainsString('@keydown.space.prevent', $source);
    }

    public function testSettingsPersistExclusionsAndDoNotOverwriteFormDuringPolling(): void {
        $settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
        self::assertStringContainsString('async function persistExcludeList(list, previous)', $settings);
        self::assertStringContainsString('const savedSuccessfully = await save()', $settings);
        self::assertStringContainsString('async function loadStatus(syncForm = false)', $settings);
        self::assertStringContainsString('if (syncForm) fill()', $settings);
        self::assertStringContainsString('await loadStatus(true)', $settings);

        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString('private function validateOllamaUrl(string $url): ?string', $controller);
        self::assertStringNotContainsString('Only the local Ollama service may be configured by a non-administrator.', $controller);
    }

    public function testChatUsesFluidWideScreenLayout(): void {
        $source = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
        self::assertStringContainsString('max-width: none;', $source);
        self::assertStringContainsString('@media (min-width: 1400px)', $source);
        self::assertStringContainsString('width: min(100%, 1540px);', $source);
        self::assertStringNotContainsString('max-width: 1180px;', $source);
        self::assertStringNotContainsString('box-shadow:', $source);
    }
}
