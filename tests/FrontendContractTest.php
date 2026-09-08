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
        // The document summary must come from full-index aggregates (computed
        // with the same search filter), never from the loaded page (Issue #74).
        self::assertStringContainsString("'total' => \$aggregates['count']", $controller);
        self::assertStringContainsString("'totalChunks' => \$aggregates['chunks']", $controller);
        self::assertStringContainsString("'totalSize' => \$aggregates['size']", $controller);
        $mapper = (string)file_get_contents(__DIR__ . '/../lib/Db/DocumentMapper.php');
        self::assertStringContainsString('countForUser(string $userId, ?string $search = null)', $mapper);
        self::assertStringContainsString('aggregateForUser(string $userId, ?string $search = null, array $filters = [])', $mapper);
        self::assertStringContainsString("like('path'", $mapper);
        self::assertStringContainsString("addOrderBy('id', 'DESC')", $mapper);
        // Issue #88: filters and sorting on the documents list.
        self::assertStringContainsString('private function applyFilters(IQueryBuilder $qb, string $userId, ?string $search, array $filters): void', $mapper);
        self::assertStringContainsString('private function resolveSort(?string $sort, string $dir): array', $mapper);
        self::assertStringContainsString('private function escapeLike(string $value): string', $mapper);
        self::assertStringContainsString('->expr()->eq(\'mime\'', $mapper);
        self::assertStringContainsString("escapeLike(\$folder) . '/%'", $mapper);
        self::assertStringContainsString("filters['type'] = \$type", $controller);
        self::assertStringContainsString("filters['folder'] = \$folder", $controller);
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
        // The chat context menu must be rendered as direct NcActionButton
        // children of the navigation item: NcActions only detects menu entries
        // whose component name starts with "NcAction", so wrapping them in a
        // custom component would silently hide the three-dot menu (Issue #87).		self::assertStringContainsString(':path="mdiPencilOutline"', $app);
		self::assertStringContainsString(':path="mdiTrashCanOutline"', $app);
		self::assertStringContainsString('mdiPinOffOutline : mdiPinOutline', $app);
		self::assertStringContainsString('mdiArchiveArrowUpOutline', $app);
		self::assertStringContainsString(':path="mdiFolderPlusOutline"', $app);
		self::assertStringNotContainsString('<svg width="20" height="20"', $app);
		self::assertStringNotContainsString('allow-collapse', $app);
		self::assertStringNotContainsString('chatsOpen', $app);
		self::assertStringContainsString('pinnedChats', $app);
		self::assertStringContainsString('archivedChats', $app);
		self::assertStringContainsString('navItems', $app);
		self::assertStringContainsString('folderGroups', $app);
		self::assertStringContainsString('plainChats', $app);
		self::assertStringContainsString('NcModal v-if="folderPickerOpen"', $app);
		self::assertStringContainsString('assignTarget(f.name)', $app);
	self::assertStringContainsString('createAndAssign', $app);
		// Per-chat folder scope ("Chat with this folder", Issue #88).
		self::assertStringContainsString('pickScope(item.chat)', $app);
		self::assertStringContainsString('scopePath', $app);
		self::assertStringContainsString("updateChatMeta(item.chat.id, { scopePath: '' })", $app);
		self::assertStringContainsString('mdiFolderSearchOutline', $app);
		// Folders are expandable/collapsible sections (Issue #87 follow-up).
		self::assertStringContainsString('collapsedFolders', $app);
		self::assertStringContainsString('toggleFolder(item.folderName)', $app);
		self::assertStringContainsString('chevron-collapsed', $app);
		self::assertStringContainsString('eva_ai_collapsed_folders', $app);
		self::assertStringContainsString(':force-menu="true"', $app);
		self::assertStringContainsString(':close-after-click="true"', $app);
		self::assertStringContainsString('@click.stop="renameChat(item.chat.id)"', $app);
		self::assertStringContainsString('@click.stop="deleteChat(item.chat.id)"', $app);
		self::assertStringContainsString('@click.stop="updateChatMeta(item.chat.id, { pinned:', $app);
		self::assertStringContainsString('@click.stop="updateChatMeta(item.chat.id, { archived:', $app);
        self::assertStringContainsString("return requestApi('GET', '/chats')", $app);
        self::assertStringContainsString("return requestApi('GET', '/folders')", $app);		self::assertStringContainsString("requestApi('POST', '/chats/' + encodeURIComponent(id) + '/meta', meta)", $app);
		// The app starts on a modern dashboard home view with a stats API.
		self::assertStringContainsString('HomeView', $app);
		self::assertStringContainsString("view === 'home'", $app);
		self::assertStringContainsString('mdiViewDashboardOutline', $app);
		$home = (string)file_get_contents(__DIR__ . '/../src/views/HomeView.vue');
		self::assertStringContainsString("api('GET', '/stats')", $home);
		self::assertStringContainsString('Recent chats', $home);
		self::assertStringContainsString('stat-grid', $home);
		self::assertStringContainsString('ollamaOnline', $home);
		self::assertStringContainsString('$emit(\'open-chat\', c.id)', $home);
		self::assertStringContainsString('public function stats(): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
		self::assertStringContainsString("'url' => '/api/stats'", (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
		// Dynamic dashboard greeting + instant chat start from the hero.
		self::assertStringContainsString("api('GET', '/greeting')", $home);
		self::assertStringContainsString('aiGreeting', $home);
		self::assertStringContainsString('staticGreeting', $home);
		self::assertStringContainsString('hero-prompt', $home);
		self::assertStringContainsString("emit('new-chat', text)", $home);
		self::assertStringContainsString("public function greeting(): DataResponse", (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
		self::assertStringContainsString("'url' => '/api/greeting'", (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
		// Chat retention: per-user setting, store cleanup + background job.
		self::assertStringContainsString('chat_retention_days', (string)file_get_contents(__DIR__ . '/../lib/Service/AppConfig.php'));
		self::assertStringContainsString("public function deleteOlderThan(string \$user, int \$days): int", (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
		self::assertStringContainsString('class ChatCleanupJob', (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/ChatCleanupJob.php'));
		self::assertStringContainsString('ChatCleanupJob', (string)file_get_contents(__DIR__ . '/../appinfo/info.xml'));
		$settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
		self::assertStringContainsString('Automatically delete old chats', $settings);
		self::assertStringContainsString('Never delete automatically', $settings);
		self::assertStringContainsString('After 30 days', $settings);
		// The Start navigation item sits at the very top of the sidebar list,
		// above the Chats heading (not only in the footer).
		self::assertStringContainsString("<template #list>\n\t\t\t\t<NcAppNavigationItem\n\t\t\t\t\tclass=\"start-nav-item\"", $app);
		self::assertStringContainsString('pendingPrompt', $app);
		self::assertStringContainsString('@prompt-consumed="pendingPrompt = \'\'"', $app);
		$chat = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
		self::assertStringContainsString('autoSend: { type: Boolean, default: false }', $chat);
		self::assertStringContainsString('form.dispatchEvent(new Event(\'submit\'', $chat);
		self::assertStringContainsString("emit('prompt-consumed')", $chat);
		// Issue #79: incremental re-indexing via file hooks. All four node
		// events must queue a debounced background reindex per user, and the
		// Indexer must expose the single-file path.
		$appPhp = (string)file_get_contents(__DIR__ . '/../lib/AppInfo/Application.php');
		self::assertStringContainsString('FileChangeListener', $appPhp);
		self::assertStringContainsString('NodeCreatedEvent::class', $appPhp);
		self::assertStringContainsString('NodeWrittenEvent::class', $appPhp);
		self::assertStringContainsString('NodeDeletedEvent::class', $appPhp);
		self::assertStringContainsString('NodeRenamedEvent::class', $appPhp);
		self::assertStringContainsString('public function reindexFile(string $userId, int $fileId): array', (string)file_get_contents(__DIR__ . '/../lib/Service/Indexer.php'));
		self::assertStringContainsString('scheduleAfter', (string)file_get_contents(__DIR__ . '/../lib/Listener/FileChangeListener.php'));
		self::assertStringContainsString('markFile', (string)file_get_contents(__DIR__ . '/../lib/Listener/FileChangeListener.php'));
		self::assertStringContainsString('class ReindexFileJob', (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/ReindexFileJob.php'));
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
        // The web chat renders every confirmation through the shared,
        // schema-driven form builder (not a create_share-only code path).
        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $confirmForms = (string)file_get_contents(__DIR__ . '/../src/lib/confirmForms.js');
        self::assertStringContainsString("import { buildConfirmForm } from './confirmForms'", $vanilla);
        self::assertStringContainsString('const conf = buildConfirmForm(m.confirmation)', $vanilla);		self::assertStringContainsString('create_share:', $confirmForms);
		self::assertStringContainsString('delete_calendar_event:', $confirmForms);
		self::assertStringContainsString('delete_file:', $confirmForms);
		// The chat stream carries the chat id so the server can resolve the
		// per-chat folder scope, and scoped chats show a pill in the header
		// (Issue #88).
		self::assertStringContainsString('history, chatId }', $vanilla);
		self::assertStringContainsString('Scoped to {path}', $vanilla);
		self::assertStringContainsString('chat.scopePath', $vanilla);
        self::assertStringContainsString('buildShareForm', (string)file_get_contents(__DIR__ . '/../src/standalone-chat.js'));
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
        self::assertStringContainsString('public function setMeta(string $user, string $id, array $meta): bool', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
        self::assertStringContainsString('public function createFolder(string $user, string $name): array', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
        self::assertStringContainsString('public function renameFolder(string $user, string $from, string $to): bool', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
        self::assertStringContainsString('public function deleteFolder(string $user, string $name): bool', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
        self::assertStringContainsString('public function listFolders(string $user): array', (string)file_get_contents(__DIR__ . '/../lib/Service/ChatStore.php'));
        self::assertStringContainsString('api#chatMeta', (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
        self::assertStringContainsString('api#folders', (string)file_get_contents(__DIR__ . '/../appinfo/routes.php'));
        self::assertStringContainsString('public function chatMeta(string $id): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('public function folders(): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('public function createFolder(): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('public function renameFolder(): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));
        self::assertStringContainsString('public function deleteFolder(): DataResponse', (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php'));

        $documents = (string)file_get_contents(__DIR__ . '/../src/views/DocumentsView.vue');
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $settings);
        self::assertStringContainsString('max-width: var(--eva-content-width, 1180px);', $documents);

        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        $chat = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
        self::assertStringContainsString('mdiDownload', $vanilla);
        self::assertStringContainsString("className = 'export'", $vanilla);
        self::assertStringContainsString('Export chat as Markdown', $vanilla);
        self::assertStringContainsString("Util::addTranslations('eva_ai')", (string)file_get_contents(__DIR__ . '/../lib/Controller/PageController.php'));
        self::assertStringContainsString('function tr(text, vars)', (string)file_get_contents(__DIR__ . '/../src/standalone-chat.js'));
        self::assertStringContainsString('.head .export', $chat);
        self::assertStringContainsString('max-width: min(88%, 1200px);', $chat);

        $notifier = (string)file_get_contents(__DIR__ . '/../lib/Notification/Notifier.php');
        self::assertStringContainsString('$this->urlGenerator->imagePath(\'eva_ai\', \'app.svg\')', $notifier);
        self::assertStringContainsString('Eva · RAG', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/TextToTextChatProvider.php'));
        self::assertStringContainsString('Eva · Tools', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/TextToTextChatWithToolsProvider.php'));
        self::assertStringContainsString('Eva · Agent', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php'));
        self::assertStringContainsString('Eva · Local', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/EvaSummaryProvider.php'));
    }

    public function testFairIndexSchedulingContract(): void {
        // Issue #142: global concurrency limit + FIFO queue. The scheduler
        // must bound running passes, expose queue state cheaply, and the
        // indexer must hand a queued user back instead of competing.
        $scheduler = (string)file_get_contents(__DIR__ . '/../lib/Service/IndexScheduler.php');
        self::assertStringContainsString('acquireSlot', $scheduler);
        self::assertStringContainsString("'queued'", $scheduler);
        self::assertStringContainsString('queuedUsers', $scheduler);
        self::assertStringContainsString('recoverStale', $scheduler);
        self::assertStringContainsString('index_max_concurrent', $scheduler);
        $indexer = (string)file_get_contents(__DIR__ . '/../lib/Service/Indexer.php');
        self::assertStringContainsString('$this->scheduler->acquireSlot($userId)', $indexer);
        self::assertStringContainsString('queue_position', $indexer);
        self::assertStringContainsString('$this->scheduler->releaseSlot($userId)', $indexer);
        $job = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexJob.php');
        self::assertStringContainsString('queuedUsers(10)', $job);
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringContainsString("\$status['scheduler'] = \$this->indexScheduler->snapshot(\$user)", $controller);
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
        // Message bubbles must stay flat (no shadows); the per-chat
        // customization dialog is the only element that may use a shadow.
        self::assertStringNotContainsString('.rm { box-shadow:', $source);
        self::assertStringNotContainsString('.rb { box-shadow:', $source);
        self::assertStringContainsString('.customize-box {', $source);
        self::assertStringContainsString('box-shadow:', $source);
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
        $standalone = (string)file_get_contents(__DIR__ . '/../src/standalone-chat.js');
        self::assertStringContainsString('if (!r.ok)', $vanilla);
        self::assertStringContainsString('if (!r.ok)', $standalone);
        self::assertStringContainsString("'HTTP ' + r.status", $vanilla);
        self::assertStringContainsString("'HTTP ' + r.status", $standalone);
    }


    public function testChatMessagesArePersistedInQuestionThenAnswerOrder(): void {
        $source = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        self::assertStringContainsString(
            "saveMessage('user', msg)\n\t\t\t\t\t\t.then((savedUser) => savedUser ? saveMessage('assistant', last.text, last.followups) : false)",
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

    public function testCompleteWebToolCallsRunDirectlyWithoutUnconditionalConfirmation(): void {
        // Complete, explicit requests on the interactive WEB surface execute
        // immediately; the confirmation dialog is reserved for missing data.
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString('REQUIRED_ARGS', $executor);
        self::assertStringContainsString('private function missingRequiredArgs', $executor);
        self::assertStringContainsString('getSurface() === ToolPolicy::SURFACE_WEB', $executor);
        self::assertStringContainsString("'missing' => \$missing", $executor);

        // The stream forwards which required fields are missing so the dialog
        // can pre-highlight them (reason: missing) and surface the created
        // share link even when no dialog was needed.
        $rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        self::assertStringContainsString("'missing' => \$res['missing'] ?? [],", $rag);
        self::assertStringContainsString("'url' => !empty(\$res['ok'])", $rag);

        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        self::assertStringContainsString('m.confirmation.missing', $vanilla);
        self::assertStringContainsString('last.linkUrl = ev.url', $vanilla);
        self::assertStringContainsString('Some required details are missing', $vanilla);
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
        $standalone = (string)file_get_contents(__DIR__ . '/../src/standalone-chat.js');
        self::assertStringContainsString("'type' => 'confirmation'", $rag);
        self::assertStringContainsString('SURFACE_TASKPROCESSING_CONFIRMED', (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php'));
        self::assertStringContainsString('confirmation_required', $rag);
        self::assertStringContainsString("ev.type === 'confirmation'", $vanilla);
        self::assertStringContainsString("ev.type === 'confirmation'", $standalone);
    }

    public function testAppHandlesDashboardChatDeepLinks(): void {
        // The dashboard widget links into the app via ?chat=new / ?chat=<id>;
        // App.vue must read the param, create a fresh chat for "new" and keep
        // the open chat id in the URL when navigating.
        $app = (string)file_get_contents(__DIR__ . '/../src/App.vue');
        self::assertStringContainsString("params.get('chat')", $app);
        self::assertStringContainsString("initialChatParam === 'new'", $app);
        self::assertStringContainsString("currentChat.value = initialChatParam", $app);
        self::assertStringContainsString("url.searchParams.set('chat', currentChat.value)", $app);
    }

    public function testSettingsNoLongerOfferActionHistory(): void {
        // The action-history feature was removed from the settings UI.
        $settings = (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue');
        self::assertStringNotContainsString('Action history', $settings);
        self::assertStringNotContainsString('loadAudit', $settings);
        self::assertStringNotContainsString('clearAudit', $settings);
    }

    public function testCustomInstructionsDialogInTheChatHeader(): void {
        // Per-chat custom instructions (Issue #90): a "Customize" header
        // action opens a dialog with persona presets and free-text
        // instructions, persisted via the chat meta API.
        $vanilla = (string)file_get_contents(__DIR__ . '/../src/lib/vanilla.js');
        self::assertStringContainsString('openCustomizeDialog', $vanilla);
        self::assertStringContainsString("'/chats/' + chatId + '/meta'", $vanilla);
        self::assertStringContainsString('personaSelect', $vanilla);
        self::assertStringContainsString('customizePill', $vanilla);
        // The old compass button must not come back.
        self::assertStringNotContainsString('instrBtn', $vanilla);
        self::assertStringNotContainsString("chatInstructions", $vanilla);
        $chatView = (string)file_get_contents(__DIR__ . '/../src/views/ChatView.vue');
        self::assertStringNotContainsString("class='instr'", $chatView);
        self::assertStringContainsString('.customize-overlay', $chatView);
        self::assertStringContainsString('.customize-box', $chatView);
    }

    public function testActionAuditApiAndServiceWereRemoved(): void {
        // The audit feature (Issue #150) was removed completely: no endpoints,
        // no service, no routes, no executor hooks.
        self::assertFileDoesNotExist(__DIR__ . '/../lib/Service/ActionAudit.php');
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        self::assertStringNotContainsString('ActionAudit', $controller);
        self::assertStringNotContainsString('function audit()', $controller);
        $routes = (string)file_get_contents(__DIR__ . '/../appinfo/routes.php');
        self::assertStringNotContainsString('api#audit', $routes);
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringNotContainsString('auditResult', $executor);
    }

    public function testAdminDashboardSurfaceAndAdminOnlyRoutes(): void {
        // Issue #82: an admin dashboard exists with per-user overview and
        // management, and every endpoint is admin-gated via #[AdminRequired].
        $adminView = (string)file_get_contents(__DIR__ . '/../src/views/AdminView.vue');
        self::assertStringContainsString('admin/overview', $adminView);
        self::assertStringContainsString('/reindex', $adminView);
        self::assertStringContainsString('/reset', $adminView);
        self::assertStringContainsString('/enrollment', $adminView);
        self::assertStringContainsString('user.documents', $adminView);
        self::assertStringContainsString('user.chunks', $adminView);
        self::assertStringContainsString('user.lastIndexedAt', $adminView);
        self::assertStringContainsString('user.enrolled', $adminView);

        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/AdminController.php');
        self::assertStringContainsString('#[AdminRequired]', $controller);
        self::assertStringContainsString('public function overview()', $controller);
        self::assertStringContainsString('public function reindex(string $userId)', $controller);
        self::assertStringContainsString('public function reset(string $userId)', $controller);
        self::assertStringContainsString('public function setEnrollment(string $userId)', $controller);
        self::assertStringContainsString('aggregatePerUser()', $controller);

        // The admin view must never show personal file content, only counts.
        self::assertStringNotContainsString('user.files', $adminView);
        self::assertStringNotContainsString('user.path', $adminView);

        $routes = (string)file_get_contents(__DIR__ . '/../appinfo/routes.php');
        self::assertStringContainsString("admin#overview", $routes);
        self::assertStringContainsString("admin#reindex", $routes);
        self::assertStringContainsString("admin#reset", $routes);
        self::assertStringContainsString("admin#setEnrollment", $routes);

        $mapper = (string)file_get_contents(__DIR__ . '/../lib/Db/DocumentMapper.php');
        self::assertStringContainsString('public function aggregatePerUser()', $mapper);

        $infoXml = (string)file_get_contents(__DIR__ . '/../appinfo/info.xml');
        self::assertStringContainsString('Settings\\Admin', $infoXml);
        self::assertStringContainsString('Settings\\AdminSection', $infoXml);
    }
}
