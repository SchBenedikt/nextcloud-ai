<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
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
        private AppConfig $config,
        private DocumentMapper $documentMapper,
        private Indexer $indexer,
        private RagService $ragService,
        private Ollama $ollama,
        private IndexScheduler $scheduler,
        private IJobList $jobList,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request);
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
                'online' => (bool)($this->ollama->status()['ping'] ?? false),
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