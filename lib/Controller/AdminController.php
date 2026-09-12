<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\WebSearchService;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Admin-only surface (Issue #82). Every endpoint is #[AdminRequired], so
 * non-admins receive a 403 before any data is read. The overview exposes
 * only metadata (counts, timestamps, state) - never file paths, contents or
 * personal data beyond the user id and display name.
 */
class AdminController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        // Resolved by the container to the calling user, which is what makes a
        // live web-search test use the admin's own provider choice.
        private ?string $userId,
        private AppConfig $config,
        private DocumentMapper $documentMapper,
        private Indexer $indexer,
        private RagService $ragService,
        private Ollama $ollama,
        private IndexScheduler $scheduler,
        private IJobList $jobList,
        private IUserManager $userManager,
        private WebSearchService $webSearch,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Instance-wide settings that only an administrator may read.
     *
     * Web search enabled/provider have moved to per-user settings so each
     * user can individually enable DuckDuckGo (free, no API key) or other
     * providers. Admin-only: weather tool, instance search infrastructure.
     */
    #[AdminRequired]
    public function getSettings(): DataResponse {
        $this->config->setUserId(null);
        return new DataResponse($this->adminSettingsPayload());
    }

    /**
     * Run one live web search and report exactly what the assistant would get.
     *
     * The admin page can configure a provider but could not show whether it
     * actually answers, so a broken provider or an enabled-but-unusable setup
     * only became visible in a chat. This runs the real service (same provider,
     * ranking, page reading and image extraction) and returns a bounded summary,
     * including the concrete error when there is one. It uses the calling
     * admin's own per-user settings, because web search is opt-in per account.
     */
    #[AdminRequired]
    public function testWebSearch(): DataResponse {
        $this->config->setUserId($this->userId);
        $this->webSearch->setUserId($this->userId);
        $query = trim((string)$this->request->getParam('query', ''));
        $mode = trim((string)$this->request->getParam('mode', 'web'));
        if (!in_array($mode, WebSearchService::MODES, true)) {
            $mode = 'web';
        }
        if ($query === '') {
            return new DataResponse(['ok' => false, 'error' => 'Enter a search query first.'], 400);
        }

        $provider = $this->webSearch->provider();
        $result = $this->webSearch->search($query, 5, $mode);
        if (!$result['ok']) {
            return new DataResponse([
                'ok' => false,
                'provider' => $provider,
                'mode' => $mode,
                'enabled' => $this->webSearch->isEnabled(),
                'error' => (string)($result['error'] ?? 'The search failed.'),
            ]);
        }

        $rows = [];
        foreach ($result['results'] as $hit) {
            $rows[] = [
                'title' => (string)($hit['title'] ?? ''),
                'url' => (string)($hit['url'] ?? ''),
                'source' => (string)($hit['source'] ?? ''),
                'published' => (int)($hit['published'] ?? 0),
                'news' => !empty($hit['news']),
                'chars' => mb_strlen((string)($hit['content'] ?? '')),
                'highlightChars' => mb_strlen((string)($hit['highlights'] ?? '')),
                'images' => is_array($hit['images'] ?? null) ? count($hit['images']) : 0,
                'snippet' => mb_substr((string)($hit['snippet'] ?? ''), 0, 300),
            ];
        }

        return new DataResponse([
            'ok' => true,
            'provider' => $provider,
            'mode' => $mode,
            'enabled' => $this->webSearch->isEnabled(),
            'results' => $rows,
        ]);
    }

    /**
     * Save instance-wide settings. Unknown keys are ignored, every value is
     * validated before it is stored, and the web search API key is write-only.
     */
    #[AdminRequired]
    public function saveSettings(): DataResponse {
        $this->config->setUserId(null);

        $validationErrors = [];
        $pending = [];
        foreach (AppConfig::ADMIN_SETTINGS as $key) {
            $value = $this->param($key);
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                $validationErrors[$key] = $key . ' must be a scalar value.';
                continue;
            }
            $error = $this->config->validateValue($key, $value);
            if ($error !== null) {
                $validationErrors[$key] = $key . ' ' . $error . '.';
                continue;
            }
            $pending[$key] = (string)$value;
        }

        $apiKey = $this->param('web_search_api_key');
        $removeApiKey = $this->boolParam('remove_web_search_api_key', false);
        if ($apiKey !== null && !is_scalar($apiKey)) {
            $validationErrors['web_search_api_key'] = 'web_search_api_key must be a string.';
        }

        if ($validationErrors !== []) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => array_values($validationErrors),
            ], 400);
        }

        foreach ($pending as $key => $value) {
            if (in_array($key, ['weather_tool_enabled', 'web_search_enabled', 'web_search_safe_search'], true)) {
                $value = in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0';
            }
            $this->config->set($key, $value);
        }

        try {
            if ($removeApiKey) {
                $this->webSearch->saveApiKey('');
            } elseif (is_scalar($apiKey) && trim((string)$apiKey) !== '') {
                $this->webSearch->saveApiKey((string)$apiKey);
            }
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'error' => 'Invalid settings.',
                'validationErrors' => ['web_search_api_key must be 8-256 characters: letters, digits, dot, underscore or dash.'],
            ], 400);
        }

        return new DataResponse($this->adminSettingsPayload());
    }

    /**
     * Admin settings payload. The API key itself is never returned - only
     * whether one is stored - so a leaked admin response cannot leak a secret.
     *
     * @return array<string,mixed>
     */
    private function adminSettingsPayload(): array {
        return $this->config->adminAll() + [
            'web_search_providers' => WebSearchService::PROVIDERS,
            'web_search_key_configured' => $this->webSearch->hasApiKey(),
            'web_search_configured' => $this->webSearch->isConfigured(),
        ];
    }

    /**
     * Read a scalar parameter from the query/form first, then the JSON body
     * (the Vue client sends PUT bodies for settings).
     */
    private function param(string $key): mixed
    {
        $value = $this->request->getParam($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }
        $raw = (string)file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded) && array_key_exists($key, $decoded)) {
            return $decoded[$key];
        }
        return null;
    }

    #[AdminRequired]
    public function overview(): DataResponse {
        // Users with at least one indexed document…
        $aggregates = $this->documentMapper->aggregatePerUser();
        // …plus users explicitly enrolled in background indexing (their
        // index may legitimately still be empty, e.g. before the first pass).
        $enrolled = $this->config->enrolledUserIds();
        $userIdSet = [];
        foreach ($aggregates as $row) {
            $userIdSet[$row['user_id']] = true;
        }
        foreach ($enrolled as $uid) {
            if ($uid !== '') {
                $userIdSet[$uid] = true;
            }
        }

        $users = [];
        foreach (array_keys($userIdSet) as $uid) {
            $agg = null;
            foreach ($aggregates as $row) {
                if ($row['user_id'] === $uid) {
                    $agg = $row;
                    break;
                }
            }
            $users[] = $this->userRow($uid, $agg);
        }
        usort($users, static fn(array $a, array $b): int => strcmp((string)$a['userId'], (string)$b['userId']));

        return new DataResponse([
            'users' => $users,
            'scheduler' => $this->scheduler->overview(),
            'ollama' => [
                // ping is a detail array; the boolean lives in its 'ok' key.
                'online' => (bool)($this->ollama->status()['ping']['ok'] ?? false),
            ],
        ]);
    }

    /**
     * Stop the periodic background indexing run (cron IndexJob) instance-wide.
     *
     * The durable index_job_stop_requested flag aborts the running tick at its
     * next user boundary and keeps the following tick idle. In-flight per-user
     * passes (scheduler slots) additionally receive index_cancel_requested so
     * each worker releases its claim promptly instead of finishing its whole
     * file budget.
     */
    #[AdminRequired]
    public function stopBackgroundIndex(): DataResponse {
        $this->config->setUserId(null);
        $this->config->set('index_job_stop_requested', '1');
        $activeUsers = $this->scheduler->activeUsers();
        foreach ($activeUsers as $uid) {
            $this->config->setUserId($uid);
            $this->config->set('index_cancel_requested', '1');
        }
        $this->config->setUserId(null);
        return new DataResponse([
            'stopped' => true,
            'requestedFor' => $activeUsers,
            'scheduler' => $this->scheduler->overview(),
        ]);
    }

    #[AdminRequired]
    public function reindex(string $userId): DataResponse {
        $user = $this->resolveUser($userId);
        if ($user === null) {
            return new DataResponse(['error' => 'Unknown user.'], 404);
        }
        $this->config->setUserId($user);
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Indexing is already running for this user.'], 409);
        }
        $this->jobList->add(\OCA\EvaAi\BackgroundJob\IndexRequestJob::class, [
            'userId' => $user,
            'mode' => 'all',
        ]);
        $this->config->setIndexEnrolled($user, true);
        return new DataResponse(['queued' => true, 'userId' => $user]);
    }

    #[AdminRequired]
    public function reset(string $userId): DataResponse {
        $user = $this->resolveUser($userId);
        if ($user === null) {
            return new DataResponse(['error' => 'Unknown user.'], 404);
        }
        $this->config->setUserId($user);
        if ($this->config->get('index_running') === '1') {
            return new DataResponse(['error' => 'Stop indexing before deleting the index.'], 409);
        }
        $deleted = $this->indexer->reset($user);
        return new DataResponse([
            'result' => $deleted,
            'userId' => $user,
            'user' => $this->userRow($user, null),
        ]);
    }

    #[AdminRequired]
    public function setEnrollment(string $userId): DataResponse {
        $user = $this->resolveUser($userId);
        if ($user === null) {
            return new DataResponse(['error' => 'Unknown user.'], 404);
        }
        // The Vue client sends the toggle as a JSON body; read it the same
        // way the chat endpoints do (query param first, body second).
        $enabled = $this->boolParam('enabled', false);
        $this->config->setIndexEnrolled($user, $enabled);
        return new DataResponse([
            'userId' => $user,
            'enrolled' => $this->config->isIndexEnrolled($user),
        ]);
    }

    /**
     * Read a boolean parameter from the request, falling back to the JSON
     * body for the axios client used by the Vue admin view.
     */
    private function boolParam(string $key, bool $default): bool {
        $value = $this->request->getParam($key, null);
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }
        $raw = (string)file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded) && array_key_exists($key, $decoded)) {
            return filter_var($decoded[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }
        return $default;
    }

    /**
     * Resolve a raw user id to an existing account. Enforced even though the
     * endpoints are admin-only so a typo cannot silently act on nothing.
     */
    private function resolveUser(string $userId): ?string {
        $userId = trim($userId);
        if ($userId === '' || $this->userManager->get($userId) === null) {
            return null;
        }
        return $userId;
    }

    /**
     * Build one admin overview row (metadata only).
     *
     * @param array{user_id:string,documents:int,chunks:int,last_indexed_at:?int}|null $agg
     * @return array<string,mixed>
     */
    private function userRow(string $userId, ?array $agg): array {
        $displayName = '';
        $user = $this->userManager->get($userId);
        if ($user !== null) {
            $displayName = (string)$user->getDisplayName();
        }
        $this->config->setUserId($userId);
        return [
            'userId' => $userId,
            'displayName' => $displayName !== '' ? $displayName : $userId,
            'documents' => (int)($agg['documents'] ?? 0),
            'chunks' => (int)($agg['chunks'] ?? 0),
            'lastIndexedAt' => $agg['last_indexed_at'] ?? null,
            'enrolled' => $this->config->get('index_enrolled') === '1',
            'indexing' => $this->config->get('index_running') === '1',
            'mode' => $this->config->get('index_mode'),
            'error' => $this->config->get('last_index_error'),
            'actionsEnabled' => $this->config->get('actions_enabled') === '1',
            'mailIndexEnabled' => $this->config->get('mail_index_enabled') === '1',
        ];
    }
}