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

    public function testDocumentsLoadIncrementallyUntilTheFilteredTotal(): void {
        $documents = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('const pageSize = 100', $documents);
        self::assertStringContainsString('offset = append ? docs.value.length : 0', $documents);
        self::assertStringContainsString('async function loadMore()', $documents);
        self::assertStringContainsString('loadMoreError', $documents);
        self::assertStringContainsString('hasMore.value = incoming.length === pageSize', $documents);
        self::assertStringContainsString('loadMore, loadStatus', $documents);

        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString("'total' => \$this->documentMapper->countForUser(\$user, \$search)", $controller);
        $mapper = (string)file_get_contents(__DIR__ . '/../lib/Db/DocumentMapper.php');
        self::assertStringContainsString('countForUser(string $userId, ?string $search = null)', $mapper);
        self::assertStringContainsString("like('path'", $mapper);
        self::assertStringContainsString("addOrderBy('id', 'DESC')", $mapper);
    }

    public function testAppSharesContentWidthAndProvidesSearchableChatActions(): void {
        $app = (string)file_get_contents(__DIR__ . '/../src/App.vue');
        self::assertStringContainsString('--eva-content-width: 1180px;', $app);
        self::assertStringContainsString('v-model="chatFilter"', $app);
        self::assertStringContainsString('class="chat-search-toggle"', $app);
        self::assertStringContainsString('class="chat-search-expanded"', $app);
        self::assertStringContainsString('searchOpen', $app);
        self::assertStringContainsString('openSearch', $app);
        self::assertStringContainsString('searchToggle', $app);
        self::assertStringContainsString('filteredChats', $app);
        self::assertStringContainsString('class="chat-item-actions"', $app);
        self::assertStringContainsString('aria-label="Rename chat"', $app);
        self::assertStringContainsString('aria-label="Delete chat"', $app);
        self::assertStringContainsString("return api('GET', '/chats')", $app);
        self::assertStringContainsString('outline: 2px solid var(--color-primary-element, #00679c);', $app);

        $settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
        $documents = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $settings);
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $documents);
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
        self::assertStringNotContainsString('max-width: none;', $source);
        self::assertStringContainsString('@media (min-width: 1400px)', $source);
        self::assertStringContainsString('font-size: 18px;', $source);
        self::assertStringContainsString('width: min(100%, var(--eva-content-width, 1180px));', $source);
        self::assertStringNotContainsString('width: min(100%, 1540px);', $source);
        self::assertStringNotContainsString('box-shadow:', $source);
    }
}
