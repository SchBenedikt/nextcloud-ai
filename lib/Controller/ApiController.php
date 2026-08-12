<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\StreamTraversableResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\BackgroundJob\IJobList;
use OCP\App\IAppManager;
use OCP\IRequest;

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
        private ChatStore $chatStore,
        private FileContextChatService $fileContextChat,
        private IAppManager $appManager
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
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
        return new DataResponse($this->ragService->buildStatus($user));
    }

    #[NoAdminRequired]
    public function settings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        return new DataResponse($this->config->all());
    }

    #[NoAdminRequired]
    public function saveSettings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
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
        ];
        foreach ($allowed as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                if (in_array($key, ['top_k', 'chunk_size', 'chunk_overlap', 'max_file_size', 'max_files_per_run', 'context_size', 'exec_write_max_chars', 'mail_index_max', 'talk_history_size'], true)) {
                    $value = (string)max(1, (int)$value);
                }
                if ($key === 'exec_delete_mode') {
                    $value = in_array($value, ['off', 'own', 'all'], true) ? $value : 'own';
                }
                if ($key === 'notify_on_complete' || $key === 'mail_index_enabled') {
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
        }
        return new DataResponse($this->config->all());
    }

    #[NoAdminRequired]
    public function resetIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->jobList->remove(\OCA\EvaAi\BackgroundJob\IndexJob::class);
        $deleted = $this->indexer->reset($user);
        return new DataResponse([
            'result' => $deleted,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    #[NoAdminRequired]
    public function startIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        // Index this user's scope without changing instance-wide configuration.
        if ($this->config->get('chat_model') === '') {
            $models = $this->ollama->listModels();
            $completion = array_values(array_filter($models, static fn($m) => in_array('completion', $m['capabilities'] ?? [], true)));
            if (!empty($completion)) {
                $this->config->set('chat_model', $completion[0]['name']);
            }
        }
        $this->jobList->add(\OCA\EvaAi\BackgroundJob\IndexJob::class);
        $result = $this->indexer->run($user);
        return new DataResponse([
            'result' => $result,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    #[NoAdminRequired]
    public function documents(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $search = (string)($this->request->getParam('search') ?? '');
        $limit = max(1, min(500, (int)($this->request->getParam('limit') ?? 100)));
        $offset = max(0, (int)($this->request->getParam('offset') ?? 0));
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
            'total' => $this->documentMapper->countForUser($user),
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
        $id = (int)($this->request->getParam('id') ?? 0);
        $doc = $id > 0 ? $this->documentMapper->findById($id) : null;
        if ($doc === null || $doc->getUserId() !== $user) {
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
        $message = trim((string)($this->request->getParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->request->getParam('history') ?? [];
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
        $fileIds = $this->request->getParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        $message = trim((string)($this->request->getParam('message') ?? ''));
        if ($message === '') {
            return new DataResponse(['error' => 'Empty message'], 400);
        }
        $history = $this->request->getParam('history') ?? [];
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
        $fileIds = $this->request->getParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        if ($fileIds === []) {
            return new DataResponse(['indexed' => [], 'missing' => [], 'files' => []]);
        }
        $docs = $this->documentMapper->findByUserAndFileIds($user, $fileIds);
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
            $gen = $this->ragService->askStream($user, $message, $history);
            foreach ($gen as $line) {
                try {
                    $ev = json_decode((string)$line, true);
                    if (is_array($ev) && ($ev['type'] ?? '') === 'done') {
                        $answer = (string)($ev['answer'] ?? '');
                        if ($answer !== '' && $this->config->get('notify_on_complete') === '1') {
                            $this->sendAnswerNotification($user, $answer);
                        }
                    }
                    yield $line;
                } catch (\Throwable $e) {
                    yield json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n";
                }
            }
        })();

        return new StreamTraversableResponse($generator, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
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
            $chat = $this->chatStore->create($user, (string)($this->request->getParam('title') ?? ''));
            return new DataResponse($chat);
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
            $role = (string)($this->request->getParam('role') ?? '');
            $text = trim((string)($this->request->getParam('text') ?? ''));
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
            $title = trim((string)($this->request->getParam('title') ?? ''));
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