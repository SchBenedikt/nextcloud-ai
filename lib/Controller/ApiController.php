<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Controller\Traits\ChatRequestCoordination;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\OllamaUrlValidator;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Dto\ChatImportRequest;
use OCA\EvaAi\Dto\ChatRegenerateRequest;
use OCA\EvaAi\Dto\ConfirmToolRequest;
use OCA\EvaAi\Dto\ChatTemplateImportRequest;
use OCA\EvaAi\Dto\ChatAppendRequest;
use OCA\EvaAi\Dto\ChatAppendResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Http\StreamTraversableResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ApiController extends OCSController {
    use ReadsRequestParameters;
    use ChatRequestCoordination;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private RagService $ragService,
        private ActionExecutor $executor,
        private Ollama $ollama,
        private IJobList $jobList,
        private ChatStore $chatStore,
        private \OCA\EvaAi\Service\UserDataService $userDataService,
        private \OCA\EvaAi\Service\ChatLearner $chatLearner,
        private \OCA\EvaAi\Service\IndexScheduler $indexScheduler,
        private \OCA\EvaAi\Service\UsageMetrics $usageMetrics,
        private LoggerInterface $logger,
        private SystemController $systemController,
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
    }

    /** Release the PHP session lock before a long model/tool request starts. */
    private function releaseSessionLock(): void {
        $this->releasePhpSessionLock();
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
