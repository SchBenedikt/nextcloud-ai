<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\BackgroundJob\IndexRequestJob;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\StreamTraversableResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\BackgroundJob\IJobList;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;

class ApiController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private RagService $ragService,
        private ActionExecutor $executor,
        private Indexer $indexer,
        private Ollama $ollama,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private IJobList $jobList,
        private ILockingProvider $lockingProvider,
        private ChatStore $chatStore,
        private FileContextChatService $fileContextChat,
        private IAppManager $appManager,
        private KnowledgeInitializer $knowledgeInitializer,
        private LockGuard $lockGuard,
        private \OCA\EvaAi\Service\UserDataService $userDataService,
        private \OCA\EvaAi\Service\ChatLearner $chatLearner
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
    }

    /**
     * Read JSON bodies once without shadowing framework/controller parameter APIs.
     * IRequest exposes query/form parameters directly, while JSON bodies need
     * this small fallback on the supported Nextcloud versions.
     */
    private ?array $bodyParams = null;

    private function requestBody(): array {
        if ($this->bodyParams !== null) {
            return $this->bodyParams;
        }
        $raw = (string)file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $this->bodyParams = is_array($decoded) ? $decoded : [];
        return $this->bodyParams;
    }

    private function requestParam(string $key, mixed $default = null): mixed {
        $value = $this->request->getParam($key, null);
        if ($value !== null) {
            return $value;
        }
        $body = $this->requestBody();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    private function requireUser(): ?string {
        return $this->userId ?: null;
    }

    #[NoAdminRequired]
    public function status(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        return new DataResponse($this->ragService->buildStatus($user));
    }

    #[NoAdminRequired]
    public function settings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        return new DataResponse($this->config->all());
    }

    #[NoAdminRequired]
    public function saveSettings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Settings are locked while indexing is running.'], 409);
        }
        $allowed = [
            'ollama_url', 'embedding_model', 'chat_model', 'chat_model_fallback',
            'embedding_model_fallback', 'summary_model', 'top_k', 'chunk_size',
            'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path', 'context_size', 'temperature',
            'actions_enabled',
            'exec_write_types', 'exec_write_max_chars', 'exec_delete_mode',
            'notify_on_complete',
            'mail_index_enabled',
            'mail_index_max',
            'weather_tool_enabled',
            'talk_history_size',
            'talk_bot_trigger',
            'talk_classify_all',
            'exclude_paths',
            'index_enrolled',
        ];
        $validationErrors = [];
        $pending = [];
        foreach ($allowed as $key) {
            $value = $this->requestParam($key);
            if ($value === null) {
                continue;
            }
            $pending[$key] = $value;
            $limitError = $this->config->validateValue($key, $value);
            if ($limitError !== null) {
                $validationErrors[$key] = $key . ' ' . $limitError . '.';
            }
            if ($key === 'index_enrolled' && $this->config->validateValue($key, $value) !== null) {
                $validationErrors[$key] = $key . ' must be a boolean value.';
            }
            if ($key === 'ollama_url') {
                $urlError = $this->validateOllamaUrl((string)$value);
                if ($urlError !== null) {
                    $validationErrors[$key] = $urlError;
                }
            }
            if (in_array($key, ['scope_path', 'exclude_paths'], true)
                && preg_match('~(^|[\\\\/])\\.\\.?([\\\\/]|$)~', (string)$value)) {
                $validationErrors[$key] = $key . ' may not contain relative path traversal.';
            }
        }
        if ($validationErrors !== []) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($validationErrors),
            ], 400);
        }
        $roleErrors = $this->validateModelRoles($pending);
        if ($roleErrors !== []) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($roleErrors),
            ], 400);
        }
        foreach ($pending as $key => $value) {
                if (in_array($key, ['top_k', 'chunk_size', 'chunk_overlap', 'max_file_size', 'max_files_per_run', 'context_size', 'exec_write_max_chars', 'mail_index_max', 'talk_history_size'], true)) {
                    $value = (string)$value;
                }
                if ($key === 'exec_delete_mode') {
                    $value = in_array($value, ['off', 'own', 'all'], true) ? $value : 'own';
                }
                if ($key === 'exec_write_types') {
                    $value = $this->config->normalizeValue($key, $value);
                }
                if ($key === 'notify_on_complete' || $key === 'mail_index_enabled' || $key === 'index_enrolled' || $key === 'weather_tool_enabled' || $key === 'talk_classify_all') {
                    $value = in_array((string)$value, ['1', 'true', 'on'], true) ? '1' : '0';
                }
                if ($key === 'temperature') {
                    $value = (string)max(0.0, min(2.0, (float)$value));
                }
                if ($key === 'talk_bot_trigger') {
                    $value = trim((string)$value);
                    if ($value === '') {
                        $value = 'EVA'; // Default falls leer
                    }
                }
                $this->config->set($key, (string)$value);
        }
        return new DataResponse($this->config->all());
    }

    /**
     * Reject role-mismatched model selections before they are stored (Issue
     * #148): when the endpoint reports capabilities for an installed model,
     * an embedding model must actually produce vectors and a chat model must
     * accept chat/completion. Selections for models that are not installed
     * yet (or an offline endpoint) are allowed - the pull may still be
     * pending, and indexing/chat will surface the real error.
     */
    private function validateModelRoles(array $pending): array {
        $modelKeys = ['embedding_model', 'chat_model', 'summary_model'];
        if (!array_intersect(array_keys($pending), $modelKeys + ['ollama_url'])) {
            return [];
        }
        $errors = [];
        $previousUrl = null;
        $urlPending = isset($pending['ollama_url']) && is_scalar($pending['ollama_url']);
        if ($urlPending) {
            // Capability checks must run against the endpoint the user is
            // about to save, not the previously stored one.
            $previousUrl = $this->config->get('ollama_url');
            $this->config->set('ollama_url', trim((string)$pending['ollama_url']));
        }
        try {
            $caps = $this->ollama->capabilities();
            if (!$caps['available'] || $caps['models'] === []) {
                // Cannot verify capabilities (offline or still starting): do
                // not block saving, the connection check explains the state.
                return [];
            }
            $byLower = [];
            foreach ($caps['models'] as $name => $info) {
                $lower = strtolower($name);
                $byLower[$lower] = $info['roles'] ?? [];
                if (str_ends_with($lower, ':latest')) {
                    $byLower[substr($lower, 0, -7)] = $info['roles'] ?? [];
                }
            }
            $expect = [
                'embedding_model' => 'embedding',
                'chat_model' => 'chat',
                'summary_model' => 'chat',
            ];
            foreach ($expect as $key => $role) {
                if (!isset($pending[$key]) || !is_scalar($pending[$key])) {
                    continue;
                }
                $model = trim((string)$pending[$key]);
                if ($model === '') {
                    continue;
                }
                $roles = $byLower[strtolower($model)] ?? null;
                if ($roles === null) {
                    continue; // Not installed yet: allow, pull may be pending.
                }
                if (!in_array($role, $roles, true)) {
                    $errors[] = $key . ' model "' . $model . '" does not support ' . $role
                        . ' (provider reports: ' . implode(', ', $roles) . ').';
                }
            }
        } finally {
            if ($urlPending && $previousUrl !== null) {
                $this->config->set('ollama_url', $previousUrl);
            }
        }
        return $errors;
    }

    private function validateOllamaUrl(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))) {
            return 'Ollama server URL must be a plain http(s) URL without credentials, path, query or fragment.';
        }
        return null;
    }

    #[NoAdminRequired]
    public function resetIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Stop indexing before deleting the index.'], 409);
        }
        $deleted = $this->indexer->reset($user);
        return new DataResponse([
            'result' => $deleted,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    #[NoAdminRequired]
    public function startIndex(): DataResponse {
        return $this->queueIndex('all');
    }

    #[NoAdminRequired]
    public function startMailIndex(): DataResponse {
        return $this->queueIndex('mail');
    }

    #[NoAdminRequired]
    public function stopIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') !== '1') {
            return new DataResponse(['stopped' => true, 'status' => $this->ragService->buildStatus($user)]);
        }
        // Keep the run claim and heartbeat until the worker confirms that it
        // has stopped. Clearing them here makes the UI report a false
        // completion and allows a second worker to race with the first one.
        // The worker observes this durable cancellation flag at its next
        // boundary and owns the terminal-state transition in its finally block.
        $this->config->set('index_cancel_requested', '1');
        return new DataResponse([
            'stopped' => true,
            'stopping' => true,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    private function queueIndex(string $mode): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            if ($this->config->get('index_cancel_requested') === '1') {
                // A restart requested while the previous worker is stopping
                // is queued behind it. The queued job claims a fresh run only
                // after the old worker has released its lock and terminal state.
                try {
                    $this->jobList->add(IndexRequestJob::class, [
                        'userId' => $user,
                        'mode' => $mode,
                        'waitForCancellation' => true,
                    ]);
                    return new DataResponse([
                        'queued' => true,
                        'waitingForStop' => true,
                        'mode' => $mode,
                        'status' => $this->ragService->buildStatus($user),
                    ]);
                } catch (\Throwable $e) {
                    return new DataResponse(['error' => 'The follow-up index job could not be queued.'], 500);
                }
            }
            return new DataResponse([
                'queued' => false,
                'alreadyRunning' => true,
                'message' => 'Indexing is already running for this user.',
                'status' => $this->ragService->buildStatus($user),
            ]);
        }
        $runId = bin2hex(random_bytes(16));
        // Bounded key: the full sha256 would exceed the varchar(64) key column
        // of Nextcloud's file_locks table and break acquire/release.
        $lockPath = LockGuard::indexLockPath($user);
        try {
            // Serialize the initial claim as well. IConfig's precondition is
            // atomic only after the first missing value has been created. The
            // guard reclaims an expired row a crashed worker left behind;
            // without it such a row blocks every future start until a cron
            // maintenance job happens to clean file_locks.
            $this->lockGuard->acquireIndexLock($user, $lockPath);
        } catch (\Throwable $e) {
            return new DataResponse([
                'queued' => false,
                'error' => 'Indexing is currently locked by another worker. Please retry shortly.',
                'status' => $this->ragService->buildStatus($user),
            ], 409);
        }
        if (!$this->config->tryClaimIndex($user)) {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            return new DataResponse([
                'queued' => false,
                'error' => 'Indexing could not be claimed because another worker is active. Please retry shortly.',
                'status' => $this->ragService->buildStatus($user),
            ], 409);
        }
        try {
            $this->config->setUserId($user);
            $this->config->set('index_started', (string)time());
            $this->config->set('index_heartbeat', (string)time());
            $this->config->set('index_finished', '0');
            $this->config->set('last_index_error', '');
            $this->config->set('index_mode', $mode);
            $this->config->set('index_cancel_requested', '0');
            $this->config->set('index_run_id', $runId);
            $this->jobList->add(IndexRequestJob::class, [
                'userId' => $user,
                'mode' => $mode,
                'runId' => $runId,
            ]);
            // A successful explicit start enrolls this user even if the
            // current pass eventually finds zero indexable documents.
            $this->config->setIndexEnrolled($user, true);
            return new DataResponse([
                'queued' => true,
                'mode' => $mode,
                'status' => $this->ragService->buildStatus($user),
            ]);
        } catch (\Throwable $e) {
            $this->config->setUserId($user);
            if ($this->config->get('index_run_id') === $runId) {
                $this->config->set('index_running', '0');
                $this->config->set('index_mode', 'idle');
                $this->config->set('index_run_id', '');
                $this->config->set('index_heartbeat', '');
            }
            return new DataResponse(['error' => 'The background index job could not be queued.'], 500);
        } finally {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function recoverStaleIndex(): void {
        if ($this->config->get('index_running') !== '1') {
            return;
        }
        $heartbeat = (int)$this->config->get('index_heartbeat');
        $started = $heartbeat > 0 ? $heartbeat : (int)$this->config->get('index_started');
        $age = $started > 0 ? time() - $started : PHP_INT_MAX;
        $cancelRequested = $this->config->get('index_cancel_requested') === '1';
        if ($age > 900 || ($cancelRequested && $age > 300)) {
            $this->config->set('index_running', '0');
            $this->config->set('index_mode', 'idle');
            $this->config->set('index_cancel_requested', '0');
            $this->config->set('index_run_id', '');
            $this->config->set('index_heartbeat', '');
        }
    }

    #[NoAdminRequired]
    public function documents(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $search = (string)($this->requestParam('search') ?? '');
        $limit = max(1, min(500, (int)($this->requestParam('limit') ?? 100)));
        $offset = max(0, (int)($this->requestParam('offset') ?? 0));
        // Document filters and sorting (Issue #88). Every value is validated
        // and bounded server-side; unknown sort keys fall back to the default.
        $filters = [];
        $type = trim((string)($this->requestParam('type') ?? ''));
        if ($type !== '') {
            // MIME group (text) or full MIME type (application/pdf), safe charset.
            if (preg_match('/^[a-z0-9.+-]+(?:\/[a-z0-9.+-]+)?$/i', $type)) {
                $filters['type'] = $type;
            }
        }
        $folder = trim((string)($this->requestParam('folder') ?? ''));
        if ($folder !== '') {
            // Relative folder path without traversal or wildcards.
            $folder = trim($folder, '/');
            if (preg_match('#^(?:[^/\\]{1,120}/)*[^/\\]{1,120}$#', $folder) && !str_contains($folder, '..')) {
                $filters['folder'] = $folder;
            }
        }
        $dateFrom = (int)($this->requestParam('dateFrom') ?? 0);
        if ($dateFrom > 0) {
            $filters['dateFrom'] = $dateFrom;
        }
        $dateTo = (int)($this->requestParam('dateTo') ?? 0);
        if ($dateTo > 0) {
            $filters['dateTo'] = $dateTo;
        }
        $sizeMin = (int)($this->requestParam('sizeMin') ?? 0);
        if ($sizeMin > 0) {
            $filters['sizeMin'] = $sizeMin;
        }
        $sizeMax = (int)($this->requestParam('sizeMax') ?? 0);
        if ($sizeMax > 0) {
            $filters['sizeMax'] = $sizeMax;
        }
        $sort = (string)($this->requestParam('sort') ?? '');
        $dir = strtolower((string)($this->requestParam('dir') ?? 'desc'));
        $docs = $this->documentMapper->findByUser($user, $search, $limit, $offset, $filters, $sort !== '' ? $sort : null, $dir);
        $out = array_map(static function ($d) {
            return [
                'id' => (int)$d->getId(),
                'path' => $d->getPath(),
                'name' => $d->getName(),
                'mime' => $d->getMime(),
                'size' => (int)$d->getSize(),
                'chunks' => (int)$d->getChunkCount(),
                'indexedAt' => $d->getIndexedAt(),
            ];
        }, $docs);
        // Totals describe the whole filtered index, independent of the page
        // that was requested (Issue #74).
        $aggregates = $this->documentMapper->aggregateForUser($user, $search, $filters);
        return new DataResponse([
            'documents' => $out,
            'total' => $aggregates['count'],
            'totalChunks' => $aggregates['chunks'],
            'totalSize' => $aggregates['size'],
        ]);
    }

    #[NoAdminRequired]
    public function documentChunks(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $id = (int)($this->requestParam('id') ?? 0);
        $doc = $id > 0 ? $this->documentMapper->findById($id) : null;
        if ($doc === null || $doc->getUserId() !== $user
            || !$this->fileContextChat->fileAccessible($user, (int)$doc->getFileId())) {
            return new DataResponse(['error' => 'Document not found'], 404);
        }
        // Bounded pagination (Issues #91/#140): a huge document must not be
        // transferred all at once. The client streams pages of LIMIT chunks
        // until it reached document.chunks.
        $limit = max(1, min(500, (int)($this->requestParam('limit') ?? 200)));
        $offset = max(0, (int)($this->requestParam('offset') ?? 0));
        $rows = $this->chunkMapper->findByDocument($id, $limit, $offset);
        $totalChunks = (int)$doc->getChunkCount();
        $nextOffset = $offset + count($rows);
        return new DataResponse([
            'document' => [
                'id' => (int)$doc->getId(),
                'path' => $doc->getPath(),
                'chunks' => $totalChunks,
            ],
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => $nextOffset < $totalChunks,
            'nextOffset' => $nextOffset,
            'chunks' => array_map(static fn($c) => [
                'index' => (int)$c['chunk_index'],
                'content' => (string)$c['content'],
            ], $rows),
        ]);
    }

    #[NoAdminRequired]
    public function chat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $message = trim((string)($this->requestParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->requestParam('history') ?? [];
        if (is_string($history)) {
            $history = json_decode($history, true) ?? [];
        }
        if (!is_array($history)) {
            $history = [];
        }
        return new DataResponse($this->ragService->ask($user, $message, $history));
    }

    /**
     * Kontext-Chat ueber explizit ausgewaehlte Dateien ("Mit AI oeffnen"
     * bzw. "Mit diesen Dateien chatten"). Antwort wird ausschliesslich
     * aus den Chunks der uebergebenen fileIds erzeugt.
     */
    #[NoAdminRequired]
    public function fileContextChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $fileIds = $this->requestParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        $message = trim((string)($this->requestParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->requestParam('history') ?? [];
        if (is_string($history)) {
            $history = json_decode($history, true) ?? [];
        }
        if (!is_array($history)) {
            $history = [];
        }
        return new DataResponse($this->fileContextChat->chat($user, $fileIds, $message, $history));
    }

    /**
     * Liefert die indexierten Dokument-IDs zu einer Liste von File-IDs
     * (fuer die UI, damit vor dem Chat geprueft werden kann, ob die
     * Auswahl bereits indexiert ist).
     */
    #[NoAdminRequired]
    public function knowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        try {
            $rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
            $home = $rootFolder->getUserFolder($user);
            $content = '';
            if ($home->nodeExists('KNOWLEDGE.md')) {
                $node = $home->get('KNOWLEDGE.md');
                if ($node instanceof \OCP\Files\File) {
                    $content = (string)$node->getContent();
                }
            }
            return new DataResponse(['content' => $content, 'length' => mb_strlen($content)]);
        } catch (\Throwable $e) {
            return new DataResponse(['content' => '', 'length' => 0]);
        }
    }

    #[NoAdminRequired]
    public function saveKnowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $content = (string)($this->requestParam('content', ''));
        if (mb_strlen($content) > 60000) {
            return new DataResponse(['error' => 'Content exceeds 60,000 characters.'], 400);
        }
        try {
            $rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
            $home = $rootFolder->getUserFolder($user);
            $path = 'KNOWLEDGE.md';
            if ($home->nodeExists($path)) {
                $home->get($path)->putContent($content);
            } else {
                $home->newFile($path, $content);
            }
            return new DataResponse(['ok' => true, 'length' => mb_strlen($content)]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not save knowledge file: ' . $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function fileContextStatus(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $fileIds = $this->requestParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        if ($fileIds === []) {
            return new DataResponse(['indexed' => [], 'missing' => [], 'files' => []]);
        }
        $docs = $this->fileContextChat->accessibleDocuments(
            $user,
            $this->documentMapper->findByUserAndFileIds($user, $fileIds)
        );
        $indexed = [];
        $files = [];
        foreach ($docs as $d) {
            $fid = (int)$d->getFileId();
            $indexed[] = $fid;
            $files[] = [
                'fileId' => $fid,
                'name' => $d->getName(),
                'path' => $d->getPath(),
            ];
        }
        return new DataResponse([
            'indexed' => $indexed,
            'missing' => array_values(array_diff($fileIds, $indexed)),
            'files' => $files,
        ]);
    }

    /**
     * Execute one model-proposed mutating action after an explicit click in
     * the authenticated web chat. The tool policy is reset to WEB here so a
     * previous Talk/worker request cannot influence this request's surface.
     */
    #[NoAdminRequired]
    public function confirmTool(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)($this->requestParam('name') ?? ''));
        $args = $this->requestParam('arguments', $this->requestParam('args', []));
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }
        if ($name === '' || !is_array($args)) {
            return new DataResponse(['error' => 'A tool name and argument object are required.'], 400);
        }

        $this->executor->setSurface(\OCA\EvaAi\Service\ToolPolicy::SURFACE_WEB);
        $result = $this->executor->runConfirmed($user, $name, $args);
        return new DataResponse($result, !empty($result['ok']) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function streamChat(): StreamTraversableResponse {
        $user = $this->requireUser();
        $body = json_decode((string)file_get_contents('php://input'), true);
        $message = trim((string)($body['message'] ?? ''));
        $history = isset($body['history']) && is_array($body['history']) ? $body['history'] : [];

        $generator = (function () use ($user, $message, $history): \Generator {
            // Aber die PHP-Output-Buffering-Schicht (php.ini output_buffering)
            // würde jede erzeugte Zeile bis zum Ende puffern -> keine Live-Streams.
            // Deshalb entfernen wir hier alle Puffer und flush'eriessen wirklich.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            if ($user === null) {
                yield json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
                return;
            }
            if ($this->clientDisconnected()) {
                return;
            }
            $gen = null;
            try {
                $gen = $this->ragService->askStream($user, $message, $history);
                foreach ($gen as $line) {
                    if ($this->clientDisconnected()) {
                        return;
                    }
                    try {
                    $ev = json_decode((string)$line, true);
                    if (is_array($ev) && ($ev['type'] ?? '') === 'done') {
                        $answer = (string)($ev['answer'] ?? '');
                        if ($answer !== '' && $this->config->get('notify_on_complete') === '1') {
                            $this->sendAnswerNotification($user, $answer);
                        }
                    }
                        yield $line;
                        if ($this->clientDisconnected()) {
                            return;
                        }
                    } catch (\Throwable $e) {
                        if (!$this->clientDisconnected()) {
                            yield json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n";
                        }
                    }
                }
            } finally {
                // Dropping the generator reference closes nested stream
                // resources on every supported PHP version.
                $gen = null;
            }
        })();

        return new StreamTraversableResponse($generator, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }


    private function clientDisconnected(): bool {
        return function_exists('connection_aborted') && connection_aborted() > 0;
    }

    private function sendAnswerNotification(string $user, string $text): void {
        try {
            $manager = \OCP\Server::get(\OCP\Notification\IManager::class);
            if (!$this->appManager->isInstalled('notifications')) {
                return;
            }
            $url = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('eva_ai.page.app');
            $notification = $manager->createNotification();
            $notification->setApp('eva_ai')
                ->setUser($user)
                ->setObject('chat', 'answer')
                ->setSubject('answer_ready', ['text' => mb_strimwidth($text, 0, 400, '…')])
                ->setLink($url)
                ->setDateTime(new \DateTime());
            $manager->notify($notification);
        } catch (\Throwable $e) {
            try {
                1;
            } catch (\Throwable $ignored) {
            }
        }
    }

    #[NoAdminRequired]
    public function chats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        // Optional text search across chat titles and message content.
        $search = trim((string)($this->requestParam('search') ?? ''));
        // Archived chats are always included: the sidebar splits them into
        // its own section and would otherwise never see them again (Issue #87).
        // The dashboard widget reads the store directly and keeps hiding them.
        return new DataResponse($this->chatStore->list($user, $search !== '' ? $search : null, true));
    }

    #[NoAdminRequired]
    public function createChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->create($user, (string)($this->requestParam('title') ?? ''));
            return new DataResponse($chat);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    #[NoAdminRequired]
    public function deleteAllChats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new DataResponse(['ok' => true, 'deleted' => $this->chatStore->deleteAll($user)]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    #[NoAdminRequired]
    public function chatDetail(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $chat = $this->chatStore->get($user, $id);
        if ($chat === null) {
            return new NotFoundResponse();
        }
        return new DataResponse($chat);
    }

    #[NoAdminRequired]
    public function chatDelete(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if (!$this->chatStore->delete($user, $id)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    #[NoAdminRequired]
    public function chatAppend(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->get($user, $id);
            if ($chat === null) {
                return new NotFoundResponse();
            }
            $role = (string)($this->requestParam('role') ?? '');
            $text = trim((string)($this->requestParam('text') ?? ''));
            if ($role === '' || $text === '') {
                return new DataResponse(['error' => 'role and text are required'], 400);
            }
            // Optional follow-up suggestions (assistant messages only).
            $followupsRaw = $this->requestParam('followups');
            $followups = [];
            if (is_array($followupsRaw)) {
                $followups = array_slice(array_map('strval', $followupsRaw), 0, 3);
            } elseif (is_string($followupsRaw) && $followupsRaw !== '') {
                $decoded = json_decode($followupsRaw, true);
                if (is_array($decoded)) {
                    $followups = array_slice(array_map('strval', $decoded), 0, 3);
                }
            }
            $this->chatStore->append($user, $id, $role, $text, $followups);

            // After an assistant message is saved, learn from the full chat.
            if ($role === 'assistant') {
                try {
                    $fullChat = $this->chatStore->getChat($user, $id);
                    if ($fullChat !== null && count($fullChat['messages']) >= 4) {
                        $this->chatLearner->learnFromChat($user, $fullChat['messages']);
                    }
                } catch (\Throwable $e) {
                    // Learning failure must never break chat persistence.
                }
            }

            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    #[NoAdminRequired]
    public function chatTitle(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if ($this->chatStore->get($user, $id) === null) {
                return new NotFoundResponse();
            }
            $title = trim((string)($this->requestParam('title') ?? ''));
            if ($title === '') {
                return new DataResponse(['error' => 'title required'], 400);
            }
            $this->chatStore->setTitle($user, $id, $title);
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    /**
     * Update organisational chat metadata (Issue #87): pin/unpin, assign a
     * folder, archive/unarchive.
     *
     * POST /api/chats/{id}/meta
     * Body: { pinned?: bool, folder?: string, archived?: bool }
     */
    #[NoAdminRequired]
    public function chatMeta(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $meta = [];
        $body = $this->requestBody();
        foreach (['pinned', 'archived'] as $flag) {
            if (array_key_exists($flag, $body)) {
                $meta[$flag] = !empty($body[$flag]);
            }
        }
        if (array_key_exists('folder', $body)) {
            $meta['folder'] = trim((string)$body['folder']);
        }
        if ($meta === []) {
            return new DataResponse(['error' => 'No metadata given'], 400);
        }
        try {
            if (!$this->chatStore->setMeta($user, $id, $meta)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to persist chat data'], 500);
        }
    }

    #[NoAdminRequired]
    public function folders(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new DataResponse($this->chatStore->listFolders($user));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to read folders'], 500);
        }
    }

    #[NoAdminRequired]
    public function createFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)$this->requestParam('name') ?? '');
        if ($name === '') {
            return new DataResponse(['error' => 'Folder name required'], 400);
        }
        try {
            return new DataResponse($this->chatStore->createFolder($user, $name));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to create folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function renameFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $from = trim((string)$this->requestParam('from') ?? '');
        $to = trim((string)$this->requestParam('to') ?? '');
        if ($from === '' || $to === '') {
            return new DataResponse(['error' => 'from and to are required'], 400);
        }
        try {
            if (!$this->chatStore->renameFolder($user, $from, $to)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to rename folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function deleteFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $name = trim((string)$this->requestParam('name') ?? '');
        if ($name === '') {
            return new DataResponse(['error' => 'Folder name required'], 400);
        }
        try {
            if (!$this->chatStore->deleteFolder($user, $name)) {
                return new NotFoundResponse();
            }
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Unable to delete folder'], 500);
        }
    }

    /**
     * Truncate a chat after a given message index and re-run the assistant.
     * Used for regenerate (truncate after user msg, re-ask) and edit
     * (truncate after edited user msg, re-ask).
     *
     * POST /api/chats/{id}/regenerate
     * Body: { messageIndex: int, message?: string }
     *
     * If messageIndex points to a user message and `message` is provided,
     * the user message text is replaced before re-running.
     */
    #[NoAdminRequired]
    public function chatRegenerate(string $id): StreamTraversableResponse {
        $user = $this->requireUser();
        if ($user === null) {
            $body = json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 'application/x-ndjson');
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $messageIndex = (int)($body['messageIndex'] ?? -1);
        $newText = isset($body['message']) ? trim((string)$body['message']) : null;

        $chat = $this->chatStore->getChat($user, $id);
        if ($chat === null) {
            $body = json_encode(['type' => 'error', 'message' => 'Chat not found']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 'application/x-ndjson');
        }

        $messages = $chat['messages'];
        if ($messageIndex < 0 || $messageIndex >= count($messages)) {
            $body = json_encode(['type' => 'error', 'message' => 'Invalid message index']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 'application/x-ndjson');
        }

        // Truncate after the target message index (keep messages 0..messageIndex).
        $this->chatStore->truncateAfter($user, $id, $messageIndex + 1);

        // If editing a user message, replace it.
        if ($newText !== null && ($messages[$messageIndex]['role'] ?? '') === 'user') {
            $this->chatStore->replaceMessage($user, $id, $messageIndex, $newText);
            $targetMessage = $newText;
        } else {
            $targetMessage = $messages[$messageIndex]['text'] ?? '';
        }

        // Build history from remaining messages.
        $history = [];
        for ($i = 0; $i < $messageIndex; $i++) {
            $m = $messages[$i];
            $history[] = ['role' => $m['role'] ?? 'user', 'content' => $m['text'] ?? ''];
        }

        $gen = $this->ragService->askStream($user, $targetMessage, $history);
        return new StreamTraversableResponse($gen, 'application/x-ndjson');
    }

    #[NoAdminRequired]
    public function models(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $endpoint = trim((string)($this->requestParam('endpoint') ?? $this->config->ollamaUrl()));
        $urlError = $this->validateOllamaUrl($endpoint);
        if ($urlError !== null) {
            return new DataResponse(['error' => $urlError], 400);
        }
        $models = $this->ollama->listModels($endpoint);
        $names = array_values(array_filter(array_map(static fn($m) => (string)($m['name'] ?? ''), $models)));
        $roles = [];
        foreach ($models as $entry) {
            $name = (string)($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $declared = array_values(array_filter(array_map('strval', $entry['capabilities'] ?? [])));
            $entryRoles = $this->ollama->rolesForModelEntry($entry);
            $roles[$name] = ['roles' => $entryRoles, 'declared' => $declared];
        }
        return new DataResponse([
            'models' => $names,
            'roles' => $roles,
            'embedding' => $this->config->get('embedding_model'),
            'chat' => $this->config->get('chat_model'),
            'details' => $models,
        ]);
    }

    #[NoAdminRequired]
    public function check(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        return new DataResponse($this->ollama->testAll());
    }

    /**
     * GDPR data export (Issue #83): the user downloads their chats, personal
     * knowledge and index metadata as one JSON file. Read-only, no admin needed.
     */
    #[NoAdminRequired]
    public function exportData(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $payload = $this->userDataService->export($user);
        $response = new DataResponse($payload);
        $response->addHeader('Content-Disposition', 'attachment; filename="eva_ai_export_' . $user . '.json"');
        return $response;
    }

}