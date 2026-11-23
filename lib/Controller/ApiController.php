<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\OllamaUrlValidator;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ApiController extends OCSController {
    use ReadsRequestParameters;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private RagService $ragService,
        private Ollama $ollama,
        private IJobList $jobList,
        private \OCA\EvaAi\Service\IndexScheduler $indexScheduler,
        private \OCA\EvaAi\Service\UsageMetrics $usageMetrics,
        private LoggerInterface $logger,
        private SystemController $systemController,
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
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
        $urlError = OllamaUrlValidator::validate($endpoint);
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

}
