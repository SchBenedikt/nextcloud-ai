<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\Indexer;
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
        private Indexer $indexer,
        private Ollama $ollama,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private IJobList $jobList,
        private ILockingProvider $lockingProvider,
        private ChatStore $chatStore,
        private FileContextChatService $fileContextChat,
        private IAppManager $appManager,
        private KnowledgeInitializer $knowledgeInitializer
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
            'ollama_url', 'embedding_model', 'chat_model', 'top_k', 'chunk_size',
            'chunk_overlap', 'max_file_size', 'max_files_per_run', 'scope_path', 'context_size', 'temperature',
            'actions_enabled',
            'exec_write_types', 'exec_write_max_chars', 'exec_delete_mode',
            'notify_on_complete',
            'mail_index_enabled',
            'mail_index_max',
            'talk_history_size',
            'talk_bot_trigger',
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
        foreach ($pending as $key => $value) {
                if (in_array($key, ['top_k', 'chunk_size', 'chunk_overlap', 'max_file_size', 'max_files_per_run', 'context_size', 'exec_write_max_chars', 'mail_index_max', 'talk_history_size'], true)) {
                    $value = (string)$value;
                }
                if ($key === 'exec_delete_mode') {
                    $value = in_array($value, ['off', 'own', 'all'], true) ? $value : 'own';
                }
                if ($key === 'notify_on_complete' || $key === 'mail_index_enabled' || $key === 'index_enrolled') {
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

    private function validateOllamaUrl(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
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
        $lockPath = 'eva_ai/index/' . hash('sha256', $user);
        try {
            // Serialize the initial claim as well. IConfig's precondition is
            // atomic only after the first missing value has been created.
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index for ' . $user);
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
        $docs = $this->documentMapper->findByUser($user, $search, $limit, $offset);
        $totalChunks = 0;
        $totalSize = 0;
        $out = array_map(static function ($d) use (&$totalChunks, &$totalSize) {
            $totalChunks += (int)$d->getChunkCount();
            $totalSize += (int)$d->getSize();
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
        return new DataResponse([
            'documents' => $out,
            'total' => $this->documentMapper->countForUser($user, $search),
            'totalChunks' => $totalChunks,
            'totalSize' => $totalSize,
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
        $rows = $this->chunkMapper->findByDocument($id);
        return new DataResponse([
            'document' => [
                'id' => (int)$doc->getId(),
                'path' => $doc->getPath(),
                'chunks' => (int)$doc->getChunkCount(),
            ],
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
        return new DataResponse($this->chatStore->list($user));
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
            if ($this->chatStore->get($user, $id) === null) {
                return new NotFoundResponse();
            }
            $role = (string)($this->requestParam('role') ?? '');
            $text = trim((string)($this->requestParam('text') ?? ''));
            if ($role === '' || $text === '') {
                return new DataResponse(['error' => 'role and text are required'], 400);
            }
            $this->chatStore->append($user, $id, $role, $text);
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

    #[NoAdminRequired]
    public function models(): DataResponse {
        $names = array_map(static fn($m) => $m['name'] ?? '', $this->ollama->listModels());
        return new DataResponse(['models' => $names, 'embedding' => $this->config->get('embedding_model')]);
    }

    #[NoAdminRequired]
    public function check(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        return new DataResponse($this->ollama->testAll());
    }
}