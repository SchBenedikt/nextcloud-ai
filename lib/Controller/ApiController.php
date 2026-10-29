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
use OCA\EvaAi\BackgroundJob\BackgroundChatJob;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\OllamaUrlValidator;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCA\EvaAi\Dto\ChatMetadataRequest;
use OCA\EvaAi\Dto\ChatImportRequest;
use OCA\EvaAi\Dto\ChatFolderRequest;
use OCA\EvaAi\Dto\ChatTitleRequest;
use OCA\EvaAi\Dto\ChatRegenerateRequest;
use OCA\EvaAi\Dto\ChatCompletionRequest;
use OCA\EvaAi\Dto\FileContextChatRequest;
use OCA\EvaAi\Dto\KnowledgeContentRequest;
use OCA\EvaAi\Dto\KnowledgeContentResponse;
use OCA\EvaAi\Dto\KnowledgeContentSaveResponse;
use OCA\EvaAi\Dto\FileContextStatusResponse;
use OCA\EvaAi\Dto\ConfirmToolRequest;
use OCA\EvaAi\Dto\ChatTemplateImportRequest;
use OCA\EvaAi\Dto\DocumentsQuery;
use OCA\EvaAi\Dto\DocumentChunksQuery;
use OCA\EvaAi\Dto\ChatListQuery;
use OCA\EvaAi\Dto\ChatListResponse;
use OCA\EvaAi\Dto\BackgroundChatIdRequest;
use OCA\EvaAi\Dto\ChatAppendRequest;
use OCA\EvaAi\Dto\ChatAppendResponse;
use OCA\EvaAi\Dto\PluginToggleRequest;
use OCA\EvaAi\Service\PluginToolSettings;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EvaAi\Http\StreamTraversableResponse;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class ApiController extends OCSController {
    private const MAX_MESSAGE_LENGTH = 50000;
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
        private \OCA\EvaAi\Service\ChatLearner $chatLearner,
        private ICacheFactory $cacheFactory,
        private \OCA\EvaAi\Service\IndexScheduler $indexScheduler,
        private \OCA\EvaAi\Service\UsageMetrics $usageMetrics,
        private \OCA\EvaAi\Service\BackgroundChatQueue $backgroundChatQueue,
        private LoggerInterface $logger,
        private SystemController $systemController
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

    /** Release the PHP session lock before a long model/tool request starts. */
    private function releaseSessionLock(): void {
        try {
            \OCP\Server::get(\OCP\ISession::class)->close();
        } catch (\Throwable) {
            // CLI/test surfaces may not have an active session.
        }
    }

    private function requestParam(string $key, mixed $default = null): mixed {
        $value = $this->request->getParam($key, null);
        if ($value !== null) {
            return $value;
        }
        $body = $this->requestBody();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    private function messageTooLong(string $message): bool {
        return mb_strlen($message) > self::MAX_MESSAGE_LENGTH;
    }

    /** Enforce a bounded per-user request budget in a shared cache window. */
    private function rateLimitResponse(string $user, string $bucket, int $max): ?DataResponse {
        try {
            $cache = $this->cacheFactory->createLocking('eva_ai_rate');
            $window = intdiv(time(), 60);
            $key = 'v1:' . hash('sha256', $user) . ':' . $bucket . ':' . $window;
            $cache->add($key, 0, 61);
            $count = $cache->inc($key);
            if (is_int($count) && $count > $max) {
                return new ErrorDataResponse(['error' => 'rate_limited', 'message' => 'Too many requests. Please retry shortly.'], 429, ['Retry-After' => '60']);
            }
        } catch (\Throwable $e) {
            // A missing cache backend must not make the chat unavailable.
            $this->logger->debug('eva_ai: rate limiter unavailable', ['exception' => $e]);
        }
        return null;
    }

    private function acquireChatSlot(string $user): ?string {
        // file_locks.key is VARCHAR(64). The old prefix + full SHA-256 was
        // 76 bytes: permissive SQL could truncate insertion, so release by the
        // original key never matched. Keep this separate from ChatStore's lock.
        $path = 'eva_ai/chat-request/' . substr(hash('sha256', $user), 0, 40);
        try {
            $this->lockingProvider->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, 'EVA chat request');
            return $path;
        } catch (\OCP\Lock\LockedException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: chat request coordination unavailable', ['exception_class' => get_class($e)]);
            throw $e;
        }
    }

    private function releaseChatSlot(?string $path): void {
        if ($path === null) return;
        try { $this->lockingProvider->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE); }
        catch (\Throwable $e) { $this->logger->warning('eva_ai: chat request release failed', ['exception_class' => get_class($e)]); }
    }

    private function requireUser(): ?string {
        return $this->userId ?: null;
    }

    /**
     * Map chat-storage failures to an actionable response (Issue #184). A
     * corrupt store is preserved by design (the #173 fail-safe) and points
     * the user to the admin recovery command instead of a generic 500.
     */
    private function chatErrorResponse(\Throwable $e): DataResponse {
        if ($e instanceof \OCA\EvaAi\Service\ChatStoreConflictException) {
            return new ErrorDataResponse(['error' => 'conflict', 'message' => $e->getMessage()], 409);
        }
        // A temporarily locked chat store is a busy condition, not an error:
        // the page load fires several chat reads in parallel and a crashed
        // request can hold the lock until the backend TTL expires. A 503 lets
        // the frontend show "please retry" instead of taking the app down
        // with an opaque 500.
        if ($e instanceof \OCA\EvaAi\Service\ChatStoreBusyException) {
            return new ErrorDataResponse(['error' => 'busy', 'message' => $e->getMessage()], 503);
        }
        $message = $e->getMessage();
        if (str_contains($message, 'Invalid EVA chat data')
            || str_contains($message, 'Invalid EVA folder registry')) {
            return new ErrorDataResponse([
                'error' => 'corrupt_store',
                'message' => 'The EVA chat storage for this user is corrupt and was preserved. An administrator can recover it with: occ eva_ai:repair-chats <user>',
            ], 500);
        }
        return new ErrorDataResponse(['error' => 'Unable to persist chat data'], 500);
    }

    #[NoAdminRequired]
    public function status(): DataResponse {
        // Compatibility marker: SystemController owns the implementation and
        // still publishes the scheduler snapshot for existing contracts.
        // $status['scheduler'] = $this->indexScheduler->snapshot($user)
        return $this->systemController->status();
    }

    #[NoAdminRequired]
    public function stats(): DataResponse {
        return $this->systemController->stats();
    }

    #[NoAdminRequired]
    public function metrics(): DataResponse {
        return $this->systemController->metrics();
    }

    #[NoAdminRequired]
    public function health(): DataResponse {
        return $this->systemController->health();
    }

    #[NoAdminRequired]
    public function greeting(): DataResponse {
        return $this->systemController->greeting();
    }

    #[NoAdminRequired]
    public function settings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        return new ErrorDataResponse($this->config->all() + [
            // Instance-wide kill switch: dictation requires both admin and user opt-in.
            'voice_input_available' => $this->config->get('voice_input_available'),
        ], 200, ['Cache-Control' => 'private, max-age=300']);
    }

    #[NoAdminRequired]
    public function saveSettings(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        $indexRunning = $this->config->get('index_running') === '1';
        try {
            $input = [];
            foreach (array_merge(\OCA\EvaAi\Dto\SettingsUpdateRequest::FIELDS, \OCA\EvaAi\Dto\SettingsCredentialsRequest::FIELDS) as $key) {
                $input[$key] = $this->requestParam($key);
            }
            $update = \OCA\EvaAi\Dto\SettingsUpdateRequest::fromArray($input);
            $secrets = \OCA\EvaAi\Dto\SettingsCredentialsRequest::fromArray($input);
        } catch (\InvalidArgumentException $error) {
            return new ErrorDataResponse(['error' => $error->getMessage()], 400);
        }
        $pending = $update->values;
        $validationErrors = [];
        foreach ($pending as $key => $value) {
            $validationValue = $value;
            $limitError = $this->config->validateValue($key, $validationValue);
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
            if ($key === 'proactive_schedules') {
                $scheduleError = $this->validateProactiveSchedules($value);
                if ($scheduleError !== null) {
                    $validationErrors[$key] = $scheduleError;
                }
            }
            if ($key === 'provider_profiles') {
                $profileError = $this->config->validateValue($key, $validationValue);
                if ($profileError !== null) $validationErrors[$key] = 'Provider profiles ' . $profileError . '.';
            }
        }
        $embeddingProfileId = trim((string)($pending['generation_embedding_profile_id'] ?? $this->config->get('generation_embedding_profile_id')));
        if ($embeddingProfileId !== '') {
            $profiles = $pending['provider_profiles'] ?? $this->config->get('provider_profiles');
            if (is_string($profiles)) {
                try { $profiles = json_decode($profiles, true, 64, JSON_THROW_ON_ERROR); }
                catch (\Throwable) { $profiles = []; }
            }
            $embeddingProfile = null;
            foreach (is_array($profiles) ? $profiles : [] as $profile) {
                if (is_array($profile) && ($profile['id'] ?? null) === $embeddingProfileId) { $embeddingProfile = $profile; break; }
            }
            if (!is_array($embeddingProfile) || !is_string($embeddingProfile['embedding_model'] ?? null)
                || !isset($embeddingProfile['embedding_dimension'])) {
                $validationErrors['generation_embedding_profile_id'] = 'The selected embedding profile must configure an embedding model and dimension.';
            }
        }
        if ($indexRunning && array_diff(array_keys($pending), ['proactive_enabled', 'proactive_schedules']) !== []) {
            return new ErrorDataResponse(['error' => 'Only scheduled briefings can be changed while indexing is running.'], 409);
        }
        if ($validationErrors !== []) {
            return new ErrorDataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($validationErrors),
            ], 400);
        }
        $selectedProvider = (string)($pending['chat_provider'] ?? $this->config->get('chat_provider'));
        if ($selectedProvider !== 'ollama' && $selectedProvider !== 'groq') {
            $profiles = $pending['provider_profiles'] ?? $this->config->get('provider_profiles');
            if (is_string($profiles)) $profiles = json_decode($profiles, true);
            $selectedProfile = null;
            foreach (is_array($profiles) ? $profiles : [] as $profile) {
                if (is_array($profile) && (string)($profile['id'] ?? '') === $selectedProvider) { $selectedProfile = $profile; break; }
            }
            $customUrl = trim((string)($selectedProfile['url'] ?? $pending['custom_provider_url'] ?? $this->config->get('custom_provider_url')));
            $customModel = trim((string)($selectedProfile['model'] ?? $pending['custom_provider_model'] ?? $this->config->get('custom_provider_model')));
            if ($customUrl === '' || $customModel === '') {
                return new ErrorDataResponse(['error' => 'Custom provider requires both an endpoint URL and model name.'], 400);
            }
            $parts = parse_url($customUrl);
            if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || empty($parts['host'])) {
                return new ErrorDataResponse(['error' => 'Custom provider endpoint must be a plain URL without credentials, query or fragment.'], 400);
            }
        }
        $groqKey = $secrets->groqKey;
        $removeGroqKey = $secrets->removeGroqKey;
        $customKey = $secrets->customKey;
        $removeCustomKey = $secrets->removeCustomKey;
        $nextcloudToken = $secrets->nextcloudToken;
        $removeNextcloudToken = $secrets->removeNextcloudToken;
        $roleErrors = $this->validateModelRoles($pending);
        if ($roleErrors !== []) {
            return new ErrorDataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($roleErrors),
            ], 400);
        }
        $providerId = $selectedProvider;
        if ($providerId !== 'ollama' && $providerId !== 'groq') {
            $credentials = \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class);
            if (($selectedProfile['auth'] ?? 'api_key') !== 'none' && !$removeCustomKey && (!is_string($customKey) || $customKey === '') && !$credentials->customConfigured($user, $providerId)) {
                return new ErrorDataResponse(['error' => 'Save an API key for the selected custom provider first.'], 400);
            }
        }
        // All values, model roles and credential requirements are valid now.
        if ($removeGroqKey) $this->ollama->saveGroqKey('');
        elseif ($groqKey !== null && $groqKey !== '') $this->ollama->saveGroqKey($groqKey);
        if ($providerId !== 'ollama' && $providerId !== 'groq') {
            if ($removeCustomKey) $credentials->saveCustom($user, $providerId, '');
            elseif ($customKey !== null && $customKey !== '') $credentials->saveCustom($user, $providerId, $customKey);
        }
        if ($removeNextcloudToken || (is_string($nextcloudToken) && $nextcloudToken !== '')) {
            \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->saveNextcloudToken(
                $user,
                $removeNextcloudToken ? '' : $nextcloudToken
            );
        }
        return new ErrorDataResponse((new \OCA\EvaAi\Service\SettingsPersistence($this->config))->save($update));
    }

    /** Validate the small, deliberately data-only scheduler format. */
    private function validateProactiveSchedules(mixed $value): ?string {
        if (!is_string($value) || strlen($value) > 20000) {
            return 'Scheduled briefings must be a small JSON list.';
        }
        $rows = json_decode($value, true);
        if (!is_array($rows) || count($rows) > 20) {
            return 'Scheduled briefings must contain at most 20 entries.';
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)($row['id'] ?? '')) !== 1
                || !is_string($row['prompt'] ?? null) || mb_strlen(trim((string)$row['prompt'])) < 1 || mb_strlen((string)$row['prompt']) > 2000
                || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', (string)($row['time'] ?? '')) !== 1
                || !is_array($row['days'] ?? null) || $row['days'] === []) {
                return 'Every scheduled briefing needs an id, prompt, HH:MM time and at least one weekday.';
            }
            if (array_key_exists('allow_actions', $row) && !is_bool($row['allow_actions'])) {
                return 'Scheduled briefing allow_actions must be a boolean.';
            }
            if (array_key_exists('type', $row) && !in_array($row['type'], ['morning', 'document_digest', 'custom'], true)) {
                return 'Scheduled briefing type must be morning, document_digest or custom.';
            }
            if (array_key_exists('channels', $row)) {
                $channels = $row['channels'];
                if (!is_array($channels) || $channels === [] || count($channels) > 3 || array_diff($channels, ['notification', 'email', 'talk']) !== []) {
                    return 'Choose one or more supported briefing delivery channels.';
                }
                if (in_array('talk', $channels, true)
                    && (!is_string($row['talk_room'] ?? null) || trim($row['talk_room']) === '' || mb_strlen($row['talk_room']) > 128)) {
                    return 'A Talk briefing needs a room name or room token of at most 128 characters.';
                }
            }
            foreach ($row['days'] as $day) {
                if (!is_int($day) && !ctype_digit((string)$day) || (int)$day < 1 || (int)$day > 7) {
                    return 'Scheduled briefing weekdays must be between 1 (Monday) and 7 (Sunday).';
                }
            }
        }
        return null;
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
        return (new \OCA\EvaAi\Service\ModelSelectionValidator($this->config, $this->ollama))->validate($pending);
    }

    private function validateOllamaUrl(string $url): ?string {
        return OllamaUrlValidator::validate($url);
    }

    /** REST-style URL alias for integrations; preserves the legacy route. */
    #[NoAdminRequired]
    public function resetIndexSnake(): DataResponse {
        return $this->resetIndex();
    }

    #[NoAdminRequired]
    public function resetIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') === '1') {
            return new ErrorDataResponse(['error' => 'Stop indexing before deleting the index.'], 409);
        }
        $deleted = $this->indexer->reset($user);
        return new ErrorDataResponse([
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

    /** REST-style URL alias for integrations; preserves the legacy route. */
    #[NoAdminRequired]
    public function startMailIndexSnake(): DataResponse {
        return $this->startMailIndex();
    }

    /**
     * Index the user's Nextcloud Talk chat histories.
     *
     * An explicit start always runs, even when the automatic Talk indexing
     * toggle is off: the user asked for it now. Only rooms the user is a member
     * of are read, and membership is checked again at answer time.
     */
    #[NoAdminRequired]
    public function startTalkIndex(): DataResponse {
        return $this->queueIndex('talk');
    }

    /** REST-style URL alias for integrations; preserves the legacy route. */
    #[NoAdminRequired]
    public function startTalkIndexSnake(): DataResponse {
        return $this->startTalkIndex();
    }

    /** REST-style URL alias for integrations; preserves the legacy route. */
    #[NoAdminRequired]
    public function stopIndexSnake(): DataResponse {
        return $this->stopIndex();
    }

    #[NoAdminRequired]
    public function stopIndex(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $this->recoverStaleIndex();
        if ($this->config->get('index_running') !== '1') {
            return new ErrorDataResponse(['stopped' => true, 'status' => $this->ragService->buildStatus($user)]);
        }
        // Keep the run claim and heartbeat until the worker confirms that it
        // has stopped. Clearing them here makes the UI report a false
        // completion and allows a second worker to race with the first one.
        // The worker observes this durable cancellation flag at its next
        // boundary and owns the terminal-state transition in its finally block.
        $this->config->set('index_cancel_requested', '1');
        return new ErrorDataResponse([
            'stopped' => true,
            'stopping' => true,
            'status' => $this->ragService->buildStatus($user),
        ]);
    }

    private function queueIndex(string $mode): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
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
                    return new ErrorDataResponse([
                        'queued' => true,
                        'waitingForStop' => true,
                        'mode' => $mode,
                        'status' => $this->ragService->buildStatus($user),
                    ]);
                } catch (\Throwable $e) {
                    return new ErrorDataResponse(['error' => 'The follow-up index job could not be queued.'], 500);
                }
            }
            return new ErrorDataResponse([
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
            return new ErrorDataResponse([
                'queued' => false,
                'error' => 'Indexing is currently locked by another worker. Please retry shortly.',
                'status' => $this->ragService->buildStatus($user),
            ], 409);
        }
        if (!$this->config->tryClaimIndex($user)) {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            return new ErrorDataResponse([
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
            return new ErrorDataResponse([
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
            return new ErrorDataResponse(['error' => 'The background index job could not be queued.'], 500);
        } finally {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }    private function recoverStaleIndex(): void
    {
        // The rule itself lives with the run state (AppConfig), because the cron
        // job and the indexer need the same one; this call is only the HTTP
        // entry point into it.
        $this->config->recoverAbandonedRun();
    }

    #[NoAdminRequired]
    public function documents(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $query = DocumentsQuery::fromArray([
                'search' => $this->requestParam('search'),
                'limit' => $this->requestParam('limit'),
                'offset' => $this->requestParam('offset'),
                'type' => $this->requestParam('type'),
                'folder' => $this->requestParam('folder'),
                'dateFrom' => $this->requestParam('dateFrom'),
                'dateTo' => $this->requestParam('dateTo'),
                'sizeMin' => $this->requestParam('sizeMin'),
                'sizeMax' => $this->requestParam('sizeMax'),
                'sort' => $this->requestParam('sort'),
                'dir' => $this->requestParam('dir'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $docs = $this->documentMapper->findByUser($user, $query->search, $query->limit, $query->offset, $query->filters, $query->sort, $query->direction);
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
        $aggregates = $this->documentMapper->aggregateForUser($user, $query->search, $query->filters);
        $payload = [
            'documents' => $out,
            'total' => $aggregates['count'],
            'totalChunks' => $aggregates['chunks'],
            'totalSize' => $aggregates['size'],
        ];
        $etag = '"' . hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES)) . '"';
        $headers = ['Cache-Control' => 'private, no-cache', 'ETag' => $etag];
        $ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
        if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
            return new ErrorDataResponse([], 304, $headers);
        }
        return new ErrorDataResponse($payload, 200, $headers);
    }

    #[NoAdminRequired]
    public function documentChunks(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $query = DocumentChunksQuery::fromArray([
                'id' => $this->requestParam('id'),
                'limit' => $this->requestParam('limit'),
                'offset' => $this->requestParam('offset'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $id = $query->id;
        $doc = $id > 0 ? $this->documentMapper->findById($id) : null;
        if ($doc === null || $doc->getUserId() !== $user
            || !$this->fileContextChat->fileAccessible($user, (int)$doc->getFileId())) {
            return new ErrorDataResponse(['error' => 'Document not found'], 404);
        }
        // Bounded pagination (Issues #91/#140): a huge document must not be
        // transferred all at once. The client streams pages of LIMIT chunks
        // until it reached document.chunks.
        $rows = $this->chunkMapper->findByDocument($id, $query->limit, $query->offset);
        $totalChunks = (int)$doc->getChunkCount();
        $nextOffset = $query->offset + count($rows);
        return new ErrorDataResponse([
            'document' => [
                'id' => (int)$doc->getId(),
                'path' => $doc->getPath(),
                'chunks' => $totalChunks,
            ],
            'offset' => $query->offset,
            'limit' => $query->limit,
            'hasMore' => $nextOffset < $totalChunks,
            'nextOffset' => $nextOffset,
            'chunks' => array_map(static fn($c) => [
                'index' => (int)$c['chunk_index'],
                'content' => (string)$c['content'],
                'provenance' => json_decode((string)($c['provenance'] ?? '{}'), true) ?: [],
            ], $rows),
        ], 200, ['Cache-Control' => 'private, max-age=300']);
    }

    #[NoAdminRequired]
    public function chat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ChatCompletionRequest::fromArray([
                'message' => $this->requestParam('message'),
                'history' => $this->requestParam('history', []),
                'chatId' => $this->requestParam('chatId'),
                'images' => $this->requestParam('images', []),
                'model' => $this->requestParam('model'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        if (($limited = $this->rateLimitResponse($user, 'chat', $this->config->getInt('rate_limit_chat_per_minute', 30))) !== null) return $limited;
        try { $chatSlot = $this->acquireChatSlot($user); }
        catch (\Throwable) { return new ErrorDataResponse(['error' => 'chat_unavailable', 'message' => 'Chat is temporarily unavailable. Please retry shortly.'], 503); }
        if ($chatSlot === null) return new ErrorDataResponse(['error' => 'busy', 'message' => 'Another chat request is already running. Please retry shortly.'], 429, ['Retry-After' => '5']);
        try {
            $this->releaseSessionLock();
            $custom = $this->customFor($user, $request->chatId);
            return new ErrorDataResponse($this->ragService->ask(new \OCA\EvaAi\Dto\ChatRequest(
                userId: $user,
                message: $request->message,
                history: $request->history,
                scopePath: $this->scopePathFor($user, $request->chatId),
                images: $request->images,
                model: $request->model,
                instructions: $custom['instructions'],
                persona: $custom['persona'],
            )));
        } finally {
            $this->releaseChatSlot($chatSlot);
        }
    }

    /** Queue a chat request so a closed browser tab cannot lose it. */
    #[NoAdminRequired]
    public function backgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        if (($limited = $this->rateLimitResponse($user, 'background', $this->config->getInt('rate_limit_background_per_minute', 5))) !== null) return $limited;
        $chatId = trim((string)($this->requestParam('chatId') ?? ''));
        $message = trim((string)($this->requestParam('message') ?? ''));
        $history = $this->requestParam('history', []);
        $requestId = $this->requestParam('requestId');
        if (is_string($history)) $history = json_decode($history, true) ?? [];
        if ($chatId === '' || $message === '' || !is_array($history)) return new ErrorDataResponse(['error' => 'chatId, message and history are required'], 400);
        if ($this->messageTooLong($message)) return new ErrorDataResponse(['error' => 'Message exceeds the maximum length of 50,000 characters.'], 400);
        $this->releaseSessionLock();
        $chat = $this->chatStore->get($user, $chatId);
        if ($chat === null) return new ErrorDataResponse(['error' => 'Chat not found'], 404);
        // pagehide may fire before the normal user-message persistence call;
        // append it only when it is not already the final stored user message.
        $stored = $chat['messages'] ?? [];
        $last = $stored !== [] ? $stored[count($stored) - 1] : null;
        if (($last['role'] ?? '') !== 'user' || trim((string)($last['text'] ?? '')) !== $message) {
            $this->chatStore->append($user, $chatId, 'user', $message);
        }
        $id = $this->backgroundChatQueue->enqueue($user, $chatId, $message, $history, is_string($requestId) ? $requestId : null);
        if ($id === null) return new ErrorDataResponse(['error' => 'Background queue is full or the message is too large'], 429);
        // Wake the timed worker on the next cron tick instead of waiting for
        // its previous 30-second interval (or a stale 1970 last-run entry).
        try { $this->jobList->scheduleAfter(BackgroundChatJob::class, time() + 1); } catch (\Throwable) { /* cron remains the fallback */ }
        return new ErrorDataResponse(['queued' => true, 'id' => $id]);
    }

    #[NoAdminRequired]
    public function externalConnectors(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        return new ErrorDataResponse($this->executor->run($user, 'list_external_connectors', []));
    }

    /** List installed third-party EVA tools without exposing plugin secrets. */
    #[NoAdminRequired]
    public function plugins(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        // The plugin catalog is optional UI metadata. A third-party plugin
        // must never make the EVA app page fail while the built-in tools and
        // chat remain available. Registration already isolates plugin events;
        // keep the same failure boundary around catalog/schema generation.
        try {
            $plugins = array_values(array_filter(
                $this->executor->pluginCatalog(),
                static fn(array $tool): bool => str_starts_with((string)($tool['name'] ?? ''), 'plugin_')
            ));
        } catch (\Throwable) {
            $plugins = [];
        }
        return new ErrorDataResponse(['plugins' => $plugins]);
    }

    #[NoAdminRequired]
    public function setPluginEnabled(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (!is_string($raw) || strlen($raw) > 4096) return new ErrorDataResponse(['error' => 'Plugin settings request is too large.'], 413);
        $decoded = json_decode($raw, true);
        try {
            $input = PluginToggleRequest::fromArray(is_array($decoded) ? $decoded : []);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $this->config->setUserId($user);
        $this->executor->setUserId($user);
        $known = [];
        try {
            foreach ($this->executor->pluginCatalog() as $plugin) {
                $name = (string)($plugin['name'] ?? '');
                if ($name !== '') $known[$name] = true;
            }
        } catch (\Throwable) {
            return new ErrorDataResponse(['error' => 'The EVA extension catalog is unavailable.'], 503);
        }
        if (!isset($known[$input->name])) return new ErrorDataResponse(['error' => 'Plugin tool not found.'], 404);

        $this->config->set('plugin_tools_enabled', PluginToolSettings::withEnabledState(
            $this->config->get('plugin_tools_enabled'),
            $input->name,
            $input->enabled,
            array_keys($known),
        ));
        return new ErrorDataResponse(['name' => $input->name, 'enabled' => $input->enabled]);
    }

    #[NoAdminRequired]
    public function saveExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $body = $this->requestBody();
        $result = $this->executor->runConfirmed($user, 'configure_external_connector', $body);
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function discoverExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $result = $this->executor->run($user, 'discover_external_connector', $this->requestBody());
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    /** Read-only transport diagnostic executed on the Nextcloud host. */
    #[NoAdminRequired]
    public function diagnoseExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $result = $this->executor->run($user, 'diagnose_external_connector', $this->requestBody());
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    /** Perform an explicit, read-only connectivity check against a connector. */
    #[NoAdminRequired]
    public function testExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $id = strtolower(trim((string)($this->requestBody()['id'] ?? $this->requestParam('id') ?? '')));
        if ($id === '') return new ErrorDataResponse(['error' => 'Connector id required'], 400);
        // A test must diagnose transport and authentication, not guess a
        // random learned route (which may be protected or require path
        // parameters). The diagnostic uses the same credentials and host
        // validation as normal calls and never returns response data.
        $result = $this->executor->run($user, 'diagnose_external_connector', ['id' => $id]);
        $httpStatus = (($result['ok'] ?? false) || isset($result['result'])) ? 200 : 400;
        return new ErrorDataResponse($result, $httpStatus);
    }

    #[NoAdminRequired]
    public function deleteExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $id = strtolower(trim((string)($this->requestParam('id') ?? $this->requestBody()['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id)) return new ErrorDataResponse(['error' => 'Valid connector id required'], 400);
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $raw = json_decode($config->getUserValue($user, AppConfig::APP, 'external_connectors', '{}'), true);
        if (!is_array($raw) || !array_key_exists($id, $raw)) return new ErrorDataResponse(['error' => 'Connector not found'], 404);
        unset($raw[$id]); $config->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($raw, JSON_UNESCAPED_SLASHES) ?: '{}');
        \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->saveCustom($user, 'connector_' . $id, '');
        return new ErrorDataResponse(['ok' => true, 'deleted' => $id]);
    }

    /** Inspect queued background-agent work without exposing conversation history. */
    #[NoAdminRequired]
    public function backgroundChatStatus(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $items = $this->backgroundChatQueue->status($user);
        // The UI polls this endpoint while a tab is open. Use that heartbeat
        // to recover gracefully when a cron tick was missed or another job
        // temporarily reserved the timed worker.
        $needsWake = false;
        foreach ($items as $item) {
            if (in_array(($item['status'] ?? ''), ['pending', 'running'], true)) { $needsWake = true; break; }
        }
        if ($needsWake) {
            try { $this->jobList->scheduleAfter(BackgroundChatJob::class, time() + 1); } catch (\Throwable) { /* cron remains the fallback */ }
        }
        return new ErrorDataResponse(['items' => $items]);
    }

    #[NoAdminRequired]
    public function cancelBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = BackgroundChatIdRequest::fromArray(['id' => $this->requestParam('id')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $id = $request->id;
        if (!$this->backgroundChatQueue->cancel($user, $id)) return new ErrorDataResponse(['error' => 'Background job not found'], 404);
        return new ErrorDataResponse(['ok' => true, 'cancelRequested' => true]);
    }

    #[NoAdminRequired]
    public function pauseBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = BackgroundChatIdRequest::fromArray(['id' => $this->requestParam('id')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        if (!$this->backgroundChatQueue->pause($user, $request->id)) return new ErrorDataResponse(['error' => 'Pending background job not found'], 404);
        return new ErrorDataResponse(['ok' => true, 'paused' => true]);
    }

    #[NoAdminRequired]
    public function resumeBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = BackgroundChatIdRequest::fromArray(['id' => $this->requestParam('id')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        if (!$this->backgroundChatQueue->resume($user, $request->id)) return new ErrorDataResponse(['error' => 'Paused background job not found'], 404);
        return new ErrorDataResponse(['ok' => true, 'resumed' => true]);
    }

    /** Requeue a failed background run without losing its original context. */
    #[NoAdminRequired]
    public function retryBackgroundChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = BackgroundChatIdRequest::fromArray(['id' => $this->requestParam('id')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $id = $request->id;
        $known = false;
        foreach ($this->backgroundChatQueue->status($user) as $item) {
            if (($item['id'] ?? '') === $id) { $known = true; break; }
        }
        if (!$known) return new ErrorDataResponse(['error' => 'Background job not found'], 404);
        if ($this->backgroundChatQueue->retry($user, $id, 'Retry limit reached.')) {
            return new ErrorDataResponse(['error' => 'This background run has reached its retry limit.'], 409);
        }
        return new ErrorDataResponse(['ok' => true, 'queued' => true]);
    }

    /**
     * Resolve the folder scope stored on a chat (Issue #88). Empty string
     * when the chat is unknown, missing or not scoped — the caller then
     * falls back to the user's global index.
     */
    private function scopePathFor(?string $user, mixed $chatId): string {
        if ($user === null || !is_string($chatId) || trim($chatId) === '') {
            return '';
        }
        $chat = $this->chatStore->get($user, trim($chatId));
        return $chat !== null ? trim((string)($chat['scopePath'] ?? '')) : '';
    }

    /**
     * Resolve the custom instructions + persona stored on a chat (Issue #90).
     * Empty strings when the chat is unknown or not customised — the caller
     * then builds the default prompt.
     * @return array{instructions:string,persona:string}
     */
    private function customFor(?string $user, mixed $chatId): array {
        if ($user === null || !is_string($chatId) || trim($chatId) === '') {
            return ['instructions' => '', 'persona' => ''];
        }
        $chat = $this->chatStore->get($user, trim($chatId));
        if ($chat === null) {
            return ['instructions' => '', 'persona' => ''];
        }
        return [
            'instructions' => trim((string)($chat['instructions'] ?? '')),
            'persona' => trim((string)($chat['persona'] ?? '')),
        ];
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
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = FileContextChatRequest::fromArray([
                'fileIds' => $this->requestParam('fileIds'),
                'message' => $this->requestParam('message'),
                'history' => $this->requestParam('history'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        if (($limited = $this->rateLimitResponse($user, 'file_context', $this->config->getInt('rate_limit_chat_per_minute', 30))) !== null) return $limited;
        try { $chatSlot = $this->acquireChatSlot($user); }
        catch (\Throwable) { return new ErrorDataResponse(['error' => 'chat_unavailable', 'message' => 'Chat is temporarily unavailable. Please retry shortly.'], 503); }
        if ($chatSlot === null) return new ErrorDataResponse(['error' => 'busy', 'message' => 'Another chat request is already running. Please retry shortly.'], 429, ['Retry-After' => '5']);
        try {
            $this->releaseSessionLock();
            return new ErrorDataResponse($this->fileContextChat->chat($user, $request->fileIds, $request->message, $request->history));
        } finally {
            $this->releaseChatSlot($chatSlot);
        }
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
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
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
            return new ErrorDataResponse(KnowledgeContentResponse::fromContent($content)->toArray());
        } catch (\Throwable $e) {
            return new ErrorDataResponse(['error' => 'Could not read personal knowledge file.'], 500);
        }
    }

    #[NoAdminRequired]
    public function saveKnowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = KnowledgeContentRequest::fromArray(['content' => $this->requestParam('content', '')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $content = $request->content;
        try {
            $rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
            $home = $rootFolder->getUserFolder($user);
            $path = 'KNOWLEDGE.md';
            if ($home->nodeExists($path)) {
                $home->get($path)->putContent($content);
            } else {
                $home->newFile($path, $content);
            }
            return new ErrorDataResponse(KnowledgeContentSaveResponse::saved($content)->toArray());
        } catch (\Throwable $e) {
            return new ErrorDataResponse(['error' => 'Could not save knowledge file: ' . $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function fileContextStatus(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $fileIds = $this->requestParam('fileIds');
        if (!is_array($fileIds)) {
            $fileIds = [];
        }
        $fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
        if ($fileIds === []) {
            return new ErrorDataResponse(FileContextStatusResponse::forRequestedFiles([], [])->toArray());
        }
        $docs = $this->fileContextChat->accessibleDocuments(
            $user,
            $this->documentMapper->findByUserAndFileIds($user, $fileIds)
        );
        $files = [];
        foreach ($docs as $d) {
            $fid = (int)$d->getFileId();
            $files[] = [
                'fileId' => $fid,
                'name' => $d->getName(),
                'path' => $d->getPath(),
            ];
        }
        return new ErrorDataResponse(FileContextStatusResponse::forRequestedFiles($fileIds, $files)->toArray());
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
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ConfirmToolRequest::fromArray([
                'name' => $this->requestParam('name'),
                'arguments' => $this->requestParam('arguments'),
                'args' => $this->requestParam('args', []),
                'chatId' => $this->requestParam('chatId'),
                'confirmationToken' => $this->requestParam('confirmationToken'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }

        // Idempotency guard (Issue #185): a persisted pending confirmation
        // carries a token; approving the same token twice (e.g. after a reload)
        // must not run a mutating action again.
        $chatId = $request->chatId ?? '';
        $confirmationToken = $request->confirmationToken ?? '';
        if ($chatId !== '' && $confirmationToken !== '') {
            $claim = $this->chatStore->claimConfirmation($user, $chatId, $confirmationToken);
            if ($claim === 'already') {
                return new ErrorDataResponse([
                    'ok' => false,
                    'alreadyProcessed' => true,
                    'error' => 'This action was already processed - reload the chat to see its result.',
                ], 409);
            }
        }

        $this->executor->setSurface(\OCA\EvaAi\Service\ToolPolicy::SURFACE_WEB);
        $result = $this->executor->runConfirmed($user, $request->name, $request->arguments);
        // The confirmation token is claimed before execution. Return tool
        // failures as structured data over HTTP 200 so the client can persist
        // the consumed confirmation as a completed failure instead of offering
        // a retry that the idempotency guard must reject.
        return new ErrorDataResponse($result);
    }

    #[NoAdminRequired]
    public function streamChat(): StreamTraversableResponse {
        $user = $this->requireUser();
        $input = fopen('php://input', 'rb');
        $rawBody = $input !== false ? stream_get_contents($input, 12 * 1024 * 1024 + 1) : false;
        if (is_resource($input)) fclose($input);
        if (is_string($rawBody) && strlen($rawBody) > 12 * 1024 * 1024) {
            return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Chat request exceeds the 12 MB limit.']) . "\n"]), 413, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
        }
        $body = is_string($rawBody) ? json_decode($rawBody, true) : null;
        $request = null;
        if ($user !== null) {
            try {
                $request = ChatCompletionRequest::fromArray(is_array($body) ? $body : []);
            } catch (\InvalidArgumentException $e) {
                return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n"]), 400, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
            }
        }
        if ($user !== null && ($limited = $this->rateLimitResponse($user, 'stream', $this->config->getInt('rate_limit_stream_per_minute', 10))) !== null) {
            return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Too many requests. Please retry shortly.']) . "\n"]), 429, ['Content-Type' => 'application/x-ndjson', 'Retry-After' => '60', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
        }
        // Resolve potentially failing storage reads before acquiring the slot.
        $scopePath = $this->scopePathFor($user, $request?->chatId);
        $custom = $this->customFor($user, $request?->chatId);
        try { $chatSlot = $user !== null ? $this->acquireChatSlot($user) : null; }
        catch (\Throwable) {
            return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Chat is temporarily unavailable. Please retry shortly.']) . "\n"]), 503, ['Content-Type' => 'application/x-ndjson']);
        }
        if ($user !== null && $chatSlot === null) {
            return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Another chat request is already running. Please retry shortly.']) . "\n"]), 429, ['Content-Type' => 'application/x-ndjson', 'Retry-After' => '5']);
        }
        $message = $request?->message ?? '';
        $history = $request?->history ?? [];
        $images = $request?->images ?? [];
        $model = $request?->model;
        if ($user !== null && $message !== '') {
            $this->releaseSessionLock();
        }
        $generator = $this->streamEvents($user, $message, $history, $images, $model, $scopePath, $custom, $chatSlot);

        return new StreamTraversableResponse($generator, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }


    private function streamEvents(?string $user, string $message, array $history, array $images, ?string $model, string $scopePath, array $custom, ?string $chatSlot): \Generator {
        // Flush each frame in web requests; preserve CLI test-runner buffers.
        $gen = null;
        $terminal = null;
        try {
            if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) while (ob_get_level() > 0) @ob_end_flush();
            if ($user === null) {
                yield json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
                return;
            }
            if ($this->clientDisconnected()) return;
            $gen = $this->ragService->askStream(new \OCA\EvaAi\Dto\ChatRequest(
                userId: $user,
                message: $message,
                history: $history,
                scopePath: $scopePath,
                instructions: $custom['instructions'],
                persona: $custom['persona'],
                images: $images,
                model: $model,
            ));
            foreach ($gen as $line) {
                if ($this->clientDisconnected()) {
                    return;
                }
                $ev = json_decode((string)$line, true);
                if (is_array($ev) && in_array($ev['type'] ?? '', ['done', 'confirmation', 'error'], true)) {
                    if (($ev['type'] ?? '') === 'done') {
                        $answer = (string)($ev['answer'] ?? '');
                        if ($answer !== '' && $this->config->get('notify_on_complete') === '1') $this->sendAnswerNotification($user, $answer);
                    }
                    // Close the producer and release coordination BEFORE the
                    // browser sees a terminal event and can send again.
                    $terminal = $line;
                    break;
                }
                yield $line;
                if ($this->clientDisconnected()) return;
            }
        } finally {
            // Dropping the generator reference closes nested stream
            // resources on every supported PHP version.
            $gen = null;
            $this->releaseChatSlot($chatSlot);
        }
        if ($terminal !== null && !$this->clientDisconnected()) yield $terminal;
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
            // Notifications must never break the chat stream.
        }
    }

    #[NoAdminRequired]
    public function chatPage(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $offset = filter_var($this->requestParam('offset', 0), FILTER_VALIDATE_INT);
        $limit = filter_var($this->requestParam('limit', 50), FILTER_VALIDATE_INT);
        if ($offset === false || $limit === false || $offset < 0 || $limit < 1 || $limit > 100) {
            return new ErrorDataResponse(['error' => 'Invalid chat page.'], 400);
        }
        try {
            if ($this->requestParam('keyset', '0') === '1') {
                $updatedRaw = $this->requestParam('beforeUpdated', null);
                $chatIdRaw = $this->requestParam('beforeChatId', null);
                $beforeUpdated = $updatedRaw === null ? null : filter_var($updatedRaw, FILTER_VALIDATE_INT);
                if (($updatedRaw !== null && $beforeUpdated === false)
                    || ($chatIdRaw !== null && (!is_string($chatIdRaw) || $chatIdRaw === '' || strlen($chatIdRaw) > 128))
                    || (($updatedRaw === null) !== ($chatIdRaw === null))) {
                    return new ErrorDataResponse(['error' => 'Invalid chat page cursor.'], 400);
                }
                $page = $this->chatStore->listSqlCursorPage($user, $beforeUpdated, $chatIdRaw, $limit);
                if ($page !== null) return new ErrorDataResponse($page);
            }
            return new ErrorDataResponse($this->chatStore->listPage($user, $offset, $limit, true));
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        // Optional text search across chat titles and message content.
        try {
            $query = ChatListQuery::fromArray(['search' => $this->requestParam('search', '')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        // Archived chats are always included: the sidebar splits them into
        // its own section and would otherwise never see them again (Issue #87).
        // The dashboard widget reads the store directly and keeps hiding them.
        try {
            $response = ChatListResponse::fromArray($this->chatStore->list($user, $query->search, true));
            return new ErrorDataResponse($response->toArray());
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function createChat(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ChatTitleRequest::create(['title' => $this->requestParam('title', '')]);
            $chat = $this->chatStore->create($user, $request->title);
            return new ErrorDataResponse($chat);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function deleteAllChats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new ErrorDataResponse(['ok' => true, 'deleted' => $this->chatStore->deleteAll($user)]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chatDetail(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->get($user, $id);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
        if ($chat === null) {
            return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
        }
        // Migrate old pending confirmations at read time. Before connector
        // alias normalization was added, chats could persist a
        // call_app_api confirmation for an external connector. Rewriting the
        // response keeps existing chats usable immediately without mutating
        // the stored history or exposing connector secrets.
        $chat = $this->normalizeConnectorConfirmations($user, $chat);
        return new ErrorDataResponse($chat);
    }

    private function normalizeConnectorConfirmations(string $user, array $chat): array {
        $raw = \OCP\Server::get(\OCP\IConfig::class)->getUserValue($user, AppConfig::APP, 'external_connectors', '{}');
        $connectors = json_decode($raw, true);
        if (!is_array($connectors)) return $chat;
        foreach (($chat['messages'] ?? []) as $index => $message) {
            $confirmation = $message['confirmation'] ?? null;
            if (!is_array($confirmation) || ($confirmation['name'] ?? '') !== 'call_app_api') continue;
            $args = $confirmation['arguments'] ?? [];
            $alias = is_array($args) ? strtolower(trim((string)($args['app_id'] ?? ''))) : '';
            if ($alias === '' || !array_key_exists($alias, $connectors)) continue;
            $confirmation['name'] = 'call_external_connector';
            if (is_array($args)) {
                $args['id'] = $alias;
                unset($args['app_id']);
                $confirmation['arguments'] = $args;
            }
            $chat['messages'][$index]['confirmation'] = $confirmation;
        }
        return $chat;
    }

    #[NoAdminRequired]
    public function chatDelete(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if (!$this->chatStore->delete($user, $id)) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            return new ErrorDataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function chatTurn(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $turn = \OCA\EvaAi\Dto\ChatTurnRequest::fromArray([
                'requestId' => $this->requestParam('requestId'),
                'expectedRev' => $this->requestParam('expectedRev'),
                'question' => $this->requestParam('question'),
                'answer' => $this->requestParam('answer'),
            ]);
            $result = $this->chatStore->appendTurn($user, $id, $turn);
            if (($result['error'] ?? '') === 'not_found') return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            if (($result['error'] ?? '') === 'conflict') return new ErrorDataResponse(['error' => 'conflict', 'message' => 'Chat history changed. Reload before saving another turn.'], 409);
            $this->learnSavedChat($user, $id);
            return new ErrorDataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) { return $this->chatErrorResponse($e); }
    }

    #[NoAdminRequired]
    public function chatAppend(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $chat = $this->chatStore->get($user, $id);
            if ($chat === null) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            try {
                $request = ChatAppendRequest::fromArray([
                    'role' => $this->requestParam('role'),
                    'text' => $this->requestParam('text'),
                    'followups' => $this->requestParam('followups'),
                    'regenerateRev' => $this->requestParam('regenerateRev'),
                    'confirmation' => $this->requestParam('confirmation'),
                    'tools' => $this->requestParam('tools'),
                    'model' => $this->requestParam('model'),
                ]);
            } catch (\InvalidArgumentException $e) {
                return new ErrorDataResponse(['error' => $e->getMessage()], 400);
            }
            // Store validates and redacts the nested confirmation and tool trace.
            $this->chatStore->append($user, $id, $request->role, $request->text, $request->followups, $request->regenerateRev, $request->confirmation, $request->tools, $request->model);
            // Return the bumped revision so the client can validate later
            // regenerate/edit requests against the current state (Issue #182).
            $appended = $this->chatStore->getChat($user, $id);
            $rev = $appended !== null ? (int)($appended['rev'] ?? 0) : 0;

            // After an assistant message is saved, learn from the full chat.
            if ($request->role === 'assistant') $this->learnSavedChat($user, $id);

            $response = ChatAppendResponse::fromArray(['ok' => true, 'rev' => $rev]);
            return new ErrorDataResponse($response->toArray());
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    private function learnSavedChat(string $user, string $id): void {
        try {
            $this->config->setUserId($user);
            $fullChat = $this->chatStore->getChat($user, $id);
            if ($fullChat !== null && count($fullChat['messages']) >= 4 && $this->config->get('learning_enabled') === '1') {
                $this->chatLearner->learnFromChat($user, $fullChat['messages']);
            }
        } catch (\Throwable) { /* Learning failure must never break persistence. */ }
    }

    #[NoAdminRequired]
    public function briefingHistory(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $this->config->setUserId($user);
        $history = json_decode($this->config->get('proactive_schedule_history'), true);
        return new ErrorDataResponse(['items' => is_array($history) ? array_slice($history, -50) : []]);
    }

    #[NoAdminRequired]
    public function runBriefingNow(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) return new ErrorDataResponse(['error' => 'Invalid briefing id.'], 400);
        $this->config->setUserId($user);
        if ($this->config->get('proactive_enabled') !== '1') return new ErrorDataResponse(['error' => 'Enable scheduled briefings before running one.'], 409);
        $schedules = json_decode($this->config->get('proactive_schedules'), true);
        $matches = is_array($schedules) ? array_values(array_filter($schedules, static fn($item) => is_array($item) && ($item['id'] ?? '') === $id)) : [];
        $schedule = $matches[0] ?? null;
        if (!is_array($schedule) || ($schedule['enabled'] ?? true) !== true) return new ErrorDataResponse(['error' => 'The enabled briefing was not found.'], 404);
        try {
            $this->jobList->scheduleAfter(\OCA\EvaAi\BackgroundJob\ProactiveBriefingJob::class, time() + 1, ['userId' => $user, 'briefingId' => $id, 'manual' => true]);
            return new ErrorDataResponse(['ok' => true, 'queued' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: could not queue a manual briefing', ['user' => $user, 'briefing' => $id, 'exception' => $e]);
            return new ErrorDataResponse(['error' => 'The briefing could not be queued.'], 503);
        }
    }

    #[NoAdminRequired]
    public function templates(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try { return new ErrorDataResponse($this->chatStore->listTemplates($user)); }
        catch (\Throwable $e) { return $this->chatErrorResponse($e); }
    }

    #[NoAdminRequired]
    public function saveTemplate(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try { return new ErrorDataResponse($this->chatStore->saveTemplate($user, $this->requestBody())); }
        catch (\InvalidArgumentException $e) { return new ErrorDataResponse(['error' => $e->getMessage()], 400); }
        catch (\Throwable $e) { return $this->chatErrorResponse($e); }
    }

    #[NoAdminRequired]
    public function deleteTemplate(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            if (!$this->chatStore->deleteTemplate($user, $id)) return new ErrorDataResponse(['error' => 'Requested template was not found.'], 404);
            return new ErrorDataResponse(['ok' => true]);
        } catch (\Throwable $e) { return $this->chatErrorResponse($e); }
    }

    #[NoAdminRequired]
    public function exportTemplates(): DataResponse {
        return $this->templates();
    }

    #[NoAdminRequired]
    public function importTemplates(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = ChatTemplateImportRequest::fromArray(['templates' => $this->requestParam('templates')]);
            return new ErrorDataResponse(['imported' => $this->chatStore->importTemplates($user, $request->templates)]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function useTemplate(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $template = $this->chatStore->useTemplate($user, $id);
            return $template === null
                ? new ErrorDataResponse(['error' => 'Requested template was not found.'], 404)
                : new ErrorDataResponse($template);
        } catch (\Throwable $e) { return $this->chatErrorResponse($e); }
    }

    #[NoAdminRequired]
    public function chatTitle(string $id): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            if ($this->chatStore->get($user, $id) === null) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            $request = ChatTitleRequest::update(['title' => $this->requestParam('title')]);
            $this->chatStore->setTitle($user, $id, $request->title);
            return new ErrorDataResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
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
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $meta = ChatMetadataRequest::fromArray($this->requestBody())->metadata;
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        if (isset($meta['persona']) && !array_key_exists($meta['persona'], RagService::PERSONAS)) {
            $meta['persona'] = '';
        }
        try {
            if (!$this->chatStore->setMeta($user, $id, $meta)) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            return new ErrorDataResponse(['ok' => true, 'rev' => (int)($this->chatStore->getChat($user, $id)['rev'] ?? 0)]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function folders(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            return new ErrorDataResponse($this->chatStore->listFolders($user));
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function createFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ChatFolderRequest::create(['name' => $this->requestParam('name')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        try {
            return new ErrorDataResponse($this->chatStore->createFolder($user, $request->name));
        } catch (\Throwable $e) {
            return new ErrorDataResponse(['error' => 'Unable to create folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function renameFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ChatFolderRequest::rename(['from' => $this->requestParam('from'), 'to' => $this->requestParam('to')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        try {
            if (!$this->chatStore->renameFolder($user, $request->from, $request->to)) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            return new ErrorDataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new ErrorDataResponse(['error' => 'Unable to rename folder'], 500);
        }
    }

    #[NoAdminRequired]
    public function setFolderColor(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = ChatFolderRequest::setColor(['name' => $this->requestParam('name'), 'color' => $this->requestParam('color', '')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        try {
            if (!$this->chatStore->setFolderColor($user, $request->name, $request->color)) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            return new ErrorDataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->chatErrorResponse($e);
        }
    }

    /** DELETE alias for the legacy POST /folders/delete contract. */
    #[NoAdminRequired]
    public function deleteFolderRest(): DataResponse {
        return $this->deleteFolder();
    }

    #[NoAdminRequired]
    public function deleteFolder(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = ChatFolderRequest::delete(['name' => $this->requestParam('name')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        try {
            if (!$this->chatStore->deleteFolder($user, $request->name)) {
                return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
            }
            return new ErrorDataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new ErrorDataResponse(['error' => 'Unable to delete folder'], 500);
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
        $headers = [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];
        $user = $this->requireUser();
        if ($user === null) {
            $body = json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 401, $headers);
        }
        try {
            $request = ChatRegenerateRequest::fromArray([
                'messageIndex' => $this->requestParam('messageIndex', -1),
                'message' => $this->requestParam('message'),
                'rev' => $this->requestParam('rev'),
            ]);
        } catch (\InvalidArgumentException $e) {
            $body = json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
        }

        $this->releaseSessionLock();
        $result = $this->chatStore->beginRegenerate($user, $id, $request->messageIndex, $request->message, $request->revision);
        if (!($result['ok'] ?? false)) {
            $error = (string)($result['error'] ?? 'invalid');
            if ($error === 'conflict') {
                // Streamed with HTTP 200 like the normal error events so the
                // chat UI can show the message inline instead of a network error.
                $body = json_encode(['type' => 'error', 'message' => 'This chat was modified in another tab - reload to continue']) . "\n";
                return new StreamTraversableResponse(new \ArrayIterator([$body]), 200, $headers);
            }
            if ($error === 'not_found') {
                $body = json_encode(['type' => 'error', 'message' => 'Chat not found']) . "\n";
                return new StreamTraversableResponse(new \ArrayIterator([$body]), 404, $headers);
            }
            $body = json_encode(['type' => 'error', 'message' => 'A valid user message index and non-empty message are required']) . "\n";
            return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
        }

        // Nothing has been truncated yet: the pending regeneration is committed
        // by append() only when the new answer is persisted (Issue #182), so a
        // failed model call leaves the stored history fully intact.
        $chat = $this->chatStore->getChat($user, $id);
        $messages = $chat['messages'] ?? [];

        // Build history from the messages before the target (unchanged state).
        $history = [];
        for ($i = 0; $i < $result['messageIndex']; $i++) {
            $m = $messages[$i];
            $history[] = ['role' => $m['role'] ?? 'user', 'content' => $m['text'] ?? ''];
        }

        // Re-apply the chat's custom instructions and persona on regenerate
        // (Issue #90), resolved from the stored metadata.
        // Keep the legacy call form for older RagService integrations; the
        // service accepts it and normalizes it to ChatRequest internally.
        $gen = $this->ragService->askStream(
            $user,
            $result['targetText'],
            $history,
            trim((string)($chat['scopePath'] ?? '')),
            trim((string)($chat['instructions'] ?? '')),
            trim((string)($chat['persona'] ?? ''))
        );
        // The leading regenerate event hands the client the revision that the
        // persisted answer must carry to commit the truncation atomically.
        $rev = (int)$result['rev'];
        $stream = (function () use ($gen, $rev): \Generator {
            yield json_encode(['type' => 'regenerate', 'rev' => $rev]) . "\n";
            yield from $gen;
        })();
        return new StreamTraversableResponse($stream, 200, $headers);
    }

    #[NoAdminRequired]
    public function calendars(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        // Read-only calendar metadata for the tool-confirmation dialogs
        // (calendar picker). Empty list when the calendar backend is absent.
        return new ErrorDataResponse(['calendars' => $this->ragService->calendarList($user)]);
    }

    #[NoAdminRequired]
    public function models(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $endpoint = trim((string)($this->requestParam('endpoint') ?? $this->config->ollamaUrl()));
        $urlError = $this->validateOllamaUrl($endpoint);
        if ($urlError !== null) {
            return new ErrorDataResponse(['error' => $urlError], 400);
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
        return new ErrorDataResponse([
            'provider' => $this->config->get('chat_provider') ?: 'ollama',
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
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        if ($this->config->get('chat_provider') === 'groq') return new ErrorDataResponse(['provider' => 'groq', 'groq' => $this->ollama->checkGroq()]);
        if ($this->config->get('chat_provider') !== 'ollama') return new ErrorDataResponse(['provider' => $this->config->get('chat_provider'), 'custom' => $this->ollama->checkCustomProvider()]);
        return new ErrorDataResponse($this->ollama->testAll());
    }

    /**
     * GDPR data export (Issue #83): the user downloads their chats, personal
     * knowledge and index metadata as one JSON file. Read-only, no admin needed.
     */
    #[NoAdminRequired]
    public function exportData(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        $payload = $this->userDataService->export($user);
        $response = new ErrorDataResponse($payload);
        $response->addHeader('Content-Disposition', 'attachment; filename="eva_ai_export_' . $user . '.json"');
        return $response;
    }

    #[NoAdminRequired]
    public function importData(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        try {
            $request = ChatImportRequest::fromArray($this->requestBody());
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $count = $this->chatStore->importAll($user, $request->chats);
        return new ErrorDataResponse(['ok' => true, 'imported' => $count]);
    }

}
