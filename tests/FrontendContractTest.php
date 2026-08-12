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
        self::assertStringContainsString('NcAppNavigationSearch', $app);
        self::assertStringContainsString('placeholder="Search chats"', $app);
        self::assertStringContainsString('<div class="new-chat-container">', $app);
        self::assertStringContainsString("import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'", $app);
        self::assertStringContainsString('NcCounterBubble', $app);
        self::assertStringContainsString('NcButton', $app);
        self::assertStringContainsString('variant="primary"', $app);
        self::assertStringNotContainsString('variant="tertiary"', $app);
        self::assertStringContainsString(':wide="true"', $app);
        self::assertStringContainsString('size="normal"', $app);
        self::assertStringContainsString('alignment="start"', $app);
        self::assertStringNotContainsString('position: sticky;', $app);
        self::assertStringNotContainsString('top: 0;', $app);
        self::assertStringContainsString('background: transparent;', $app);
        self::assertStringContainsString('margin-top: calc(-1 * var(--default-grid-baseline, 4px));', $app);
        self::assertStringContainsString('padding: 0 0 var(--default-grid-baseline, 4px);', $app);
        self::assertStringContainsString(':path="mdiPencilOutline"', $app);
        self::assertStringContainsString(':path="mdiTrashCanOutline"', $app);
        self::assertStringNotContainsString('<svg width="20" height="20"', $app);
        self::assertStringNotContainsString('allow-collapse', $app);
        self::assertStringNotContainsString('chatsOpen', $app);
        self::assertStringContainsString('filteredChats', $app);
        self::assertStringContainsString(':force-menu="true"', $app);
        self::assertStringContainsString(':close-after-click="true"', $app);
        self::assertStringContainsString('@click.stop="renameChat(c.id)"', $app);
        self::assertStringContainsString('@click.stop="deleteChat(c.id)"', $app);
        self::assertStringContainsString('aria-label="Rename chat"', $app);
        self::assertStringContainsString('aria-label="Delete chat"', $app);
        self::assertStringContainsString("return api('GET', '/chats')", $app);
        $settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
        self::assertStringContainsString("api('DELETE', 'chats')", $settings);
        self::assertStringContainsString("new CustomEvent('eva-ai:chats-cleared')", $settings);
        self::assertStringContainsString("'eva-ai:chats-cleared'", $app);
        self::assertStringContainsString('api#deleteAllChats', (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
        self::assertStringContainsString('public function deleteAllChats', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('deleteAll(string $user)', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));

        $documents = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $settings);
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $documents);

        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $chat = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
        self::assertStringContainsString('mdiDownload', $vanilla);
        self::assertStringContainsString("className = 'export'", $vanilla);
        self::assertStringContainsString('Export chat as Markdown', $vanilla);
        self::assertStringContainsString('.head .export', $chat);
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
