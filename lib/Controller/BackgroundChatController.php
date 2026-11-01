<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\BackgroundJob\BackgroundChatJob;
use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Controller\Traits\RequestLimits;
use OCA\EvaAi\Dto\BackgroundChatIdRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RequestRateLimiter;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Owns admission, persistence and lifecycle controls for queued chat turns. */
final class BackgroundChatController extends OCSController {
    use ReadsRequestParameters;
    use RequestLimits;

    private const MAX_MESSAGE_LENGTH = 50000;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private \OCA\EvaAi\Service\BackgroundChatQueue $backgroundChatQueue,
        private ChatStore $chatStore,
        private IJobList $jobList,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
        private ?RequestRateLimiter $requestRateLimiter = null,
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
    }

    private function requireUser(): ?string {
        return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
    }

    private function rateLimitResponse(string $user, string $bucket, int $max): ?DataResponse {
        return $this->consumeRequestLimit($user, $bucket, $max, $this->requestRateLimiter, $this->cacheFactory, $this->logger);
    }

    private function messageTooLong(string $message): bool {
        return mb_strlen($message) > self::MAX_MESSAGE_LENGTH;
    }

    /** Release the PHP session lock before storage or background work. */
    private function releaseSessionLock(): void {
        try {
            \OCP\Server::get(\OCP\ISession::class)->close();
        } catch (\Throwable) {
            // CLI/test surfaces may not have an active session.
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
}
