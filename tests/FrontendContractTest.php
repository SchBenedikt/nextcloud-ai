<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

final class FrontendContractTest extends TestCase {
    public function testDocumentChunkViewKeepsLoadingErrorsAndRetriesVisible(): void {
        $source = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString("if (!Array.isArray(data?.chunks))", $source);
        self::assertStringContainsString("status: 'error'", $source);
        self::assertStringContainsString('loadChunks(d.id, d.chunks, true)', $source);
        self::assertStringContainsString("expected: data.total", $source);
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
        self::assertStringContainsString('--eva-content-width: clamp(1180px, 78vw, 1680px);', $app);
        self::assertStringContainsString('v-model="chatFilter"', $app);
        self::assertStringContainsString('NcAppNavigationSearch', $app);
        self::assertStringContainsString(':placeholder="$t(\'Search chats\')"', $app);
        self::assertStringContainsString('<div class="new-chat-container">', $app);
        self::assertStringContainsString('display: block;', $app);
        self::assertStringContainsString('width: 100%;', $app);
        self::assertStringContainsString('max-width: 100%;', $app);
        self::assertStringContainsString("import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'", $app);
        self::assertStringContainsString('NcCounterBubble', $app);
        self::assertStringContainsString('NcButton', $app);
        self::assertStringContainsString('variant="primary"', $app);
        self::assertStringNotContainsString('variant="tertiary"', $app);
        self::assertStringContainsString(':wide="true"', $app);
        self::assertStringContainsString('size="normal"', $app);
        self::assertStringNotContainsString('alignment="start"', $app);
        self::assertStringNotContainsString('position: sticky;', $app);
        self::assertStringNotContainsString('top: 0;', $app);
        self::assertStringContainsString('background: transparent;', $app);
        self::assertStringContainsString('margin-top: calc(-1 * var(--default-grid-baseline, 4px));', $app);
        self::assertStringContainsString('padding: 0 var(--app-navigation-padding, 8px) var(--default-grid-baseline, 4px);', $app);
        self::assertStringContainsString('box-sizing: border-box;', $app);
        self::assertStringContainsString('display: block;', $app);
        self::assertStringContainsString('width: 100%;', $app);
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
        self::assertStringContainsString(':aria-label="$t(\'Rename chat\')"', $app);
        self::assertStringContainsString(':aria-label="$t(\'Delete chat\')"', $app);
        self::assertStringContainsString("requestApi('GET', '/chats'", $app);
        $settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
        self::assertStringContainsString("api('DELETE', 'chats')", $settings);
        self::assertStringContainsString("new CustomEvent('eva-ai:chats-cleared')", $settings);
        self::assertStringContainsString("'eva-ai:chats-cleared'", $app);
        self::assertStringContainsString('api#deleteAllChats', (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
        self::assertStringContainsString('public function deleteAllChats', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString("public function models(): DataResponse", (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('public function listModels(?string $baseUrl = null): array', (string)file_get_contents(__DIR__ . '/../lib/Service/Ollama.php'));
        self::assertStringContainsString('availableModels', $settings);
        self::assertStringContainsString('embeddingModels', $settings);
        self::assertStringContainsString('chatModels', $settings);
        self::assertStringContainsString('m.confirmation.name === \'create_share\'', $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js'));
        self::assertStringContainsString('buildShareForm', (string)file_get_contents(__DIR__ . '/../js/chat.js'));
        self::assertStringContainsString("return new DataResponse(['error' => 'Not logged in'], 401)", (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString('KnowledgeInitializer $knowledgeInitializer', $controller);
        self::assertStringContainsString('ensureInitialized($user)', $controller);
        self::assertStringContainsString('knowledge_initialized', (string)file_get_contents(__DIR__ . '/../lib/Service/KnowledgeInitializer.php'));
        self::assertStringContainsString('private function requestParam', $controller);
        self::assertStringContainsString('private function requestBody', $controller);
        self::assertStringContainsString('$this->request->getParam(', $controller);
        self::assertStringNotContainsString('private function param', $controller);
        self::assertStringNotContainsString('function jsonBody', $controller);
        self::assertStringContainsString('deleteAll(string $user)', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));

        $documents = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $settings);
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $documents);

        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $chat = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
        self::assertStringContainsString('mdiDownload', $vanilla);
        self::assertStringContainsString("className = 'export'", $vanilla);
        self::assertStringContainsString('Export chat as Markdown', $vanilla);
        self::assertStringContainsString("Util::addTranslations('eva_ai')", (string)file_get_contents(__DIR__ . '/../lib/Controller/PageController.php'));
        self::assertStringContainsString('function tr(text, vars)', (string)file_get_contents(__DIR__ . '/../js/chat.js'));
        self::assertStringContainsString('.head .export', $chat);
        self::assertStringContainsString('max-width: min(88%, 1200px);', $chat);

        $notifier = (string)file_get_contents(__DIR__ . '/../lib/Notification/Notifier.php');
        self::assertStringContainsString('$this->urlGenerator->imagePath(\'eva_ai\', \'app.svg\')', $notifier);
        self::assertStringContainsString('Eva · RAG', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/TextToTextChatProvider.php'));
        self::assertStringContainsString('Eva · Tools', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/TextToTextChatWithToolsProvider.php'));
        self::assertStringContainsString('Eva · Agent', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php'));
        self::assertStringContainsString('Eva · Local', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/EvaSummaryProvider.php'));
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

    public function testNon2xxResponsesAreVisibleInsteadOfSilentNulls(): void {
        $app = (string)file_get_contents(__DIR__ . '/../src/App.vue');
        $fileContext = (string)file_get_contents(__DIR__ . '/../src/views/FileContextChatView.vue');
        $api = (string)file_get_contents(__DIR__ . '/../src/lib/api.js');
        self::assertStringContainsString("import { api as requestApi, errMsg } from './lib/api'", $app);
        self::assertStringContainsString("import { api, errMsg } from '../lib/api'", $fileContext);
        self::assertStringContainsString('apiError', $app);
        self::assertStringContainsString('apiError', $fileContext);
        self::assertStringNotContainsString('.catch(() => null)', $app);
        self::assertStringNotContainsString('.catch(() => null)', $fileContext);
        self::assertStringContainsString('slice(0, 240)', $api);
        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $standalone = (string)file_get_contents(__DIR__ . '/../js/chat.js');
        self::assertStringContainsString('if (!r.ok)', $vanilla);
        self::assertStringContainsString('if (!r.ok)', $standalone);
        self::assertStringContainsString("'HTTP ' + r.status", $vanilla);
        self::assertStringContainsString("'HTTP ' + r.status", $standalone);
    }


    public function testChatMessagesArePersistedInQuestionThenAnswerOrder(): void {
        $source = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        self::assertStringContainsString(
            "saveMessage('user', msg)\n\t\t\t\t\t\t.then((savedUser) => savedUser ? saveMessage('assistant', last.text) : false)",
            $source
        );
        self::assertStringNotContainsString("Promise.all([saveMessage('user', msg)", $source);
    }

    public function testConnectionCheckShortCircuitsAndReportsHttpErrors(): void {
        $ollama = (string)file_get_contents(__DIR__ . '/../lib/Service/Ollama.php');
        self::assertStringContainsString("'error' => 'Ollama returned HTTP ' . \$status", $ollama);
        self::assertStringContainsString('if (!$server[\'ok\'])', $ollama);
        self::assertStringContainsString('Skipped because the Ollama server is not reachable.', $ollama);
        self::assertStringContainsString('$this->testEmbedding($emb, 30)', $ollama);
        self::assertStringContainsString('$this->testChat($chat, 60)', $ollama);
    }

    public function testSecurityAndLoggerFixesRemainInPlace(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString("|| isset(\$parts['user'])", $controller);
        self::assertStringContainsString("|| isset(\$parts['query'])", $controller);
        self::assertStringNotContainsString("isset(\$parts['user'], \$parts['pass'], \$parts['query'], \$parts['fragment'])", $controller);

        $rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        self::assertStringContainsString('use Psr\\Log\\LoggerInterface;', $rag);
        self::assertStringContainsString('private LoggerInterface $logger', $rag);
    }

    public function testToolConfirmationIsEnforcedAcrossWebAndTalk(): void {
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString('bool $confirmed = false', $executor);
        self::assertStringContainsString("'confirmation_required' => true", $executor);
        self::assertStringContainsString('public function runConfirmed', $executor);

        $policy = (string)file_get_contents(__DIR__ . '/../lib/Service/ToolPolicy.php');
        self::assertStringContainsString("'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],", $policy);
        self::assertStringContainsString('SURFACE_TASKPROCESSING_CONFIRMED', $policy);
        self::assertStringContainsString('SURFACE_TALK', $policy);
        self::assertStringContainsString('SURFACE_TASKPROCESSING_CONFIRMED', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php'));

        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString('public function confirmTool', $controller);
        self::assertStringContainsString('runConfirmed($user, $name, $args)', $controller);
        self::assertStringContainsString('api#confirmTool', (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));

        $rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $standalone = (string)file_get_contents(__DIR__ . '/../js/chat.js');
        self::assertStringContainsString("'type' => 'confirmation'", $rag);
        self::assertStringContainsString('SURFACE_TASKPROCESSING_CONFIRMED', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php'));
        self::assertStringContainsString('confirmation_required', $rag);
        self::assertStringContainsString("ev.type === 'confirmation'", $vanilla);
        self::assertStringContainsString("ev.type === 'confirmation'", $standalone);
    }

}
