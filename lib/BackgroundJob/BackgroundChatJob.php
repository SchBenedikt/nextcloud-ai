<?php
declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BackgroundChatQueue;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/** Processes chat requests after the originating browser request disappears. */
final class BackgroundChatJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private BackgroundChatQueue $queue,
        private ChatStore $chats,
        private RagService $rag,
        private IManager $notifications,
        private IURLGenerator $urls,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) { parent::__construct($time); $this->setInterval(30); }

    protected function run($argument): void {
        $users = $this->queue->users();
        foreach ($users as $user) {
            $item = $this->queue->claim($user);
            if (!is_array($item)) continue;
            $id = (string)($item['id'] ?? '');
            try {
                $chatId = (string)($item['chatId'] ?? '');
                $chat = $this->chats->get($user, $chatId);
                if ($chat === null) throw new \RuntimeException('Chat no longer exists');
                // Queued chats are read-only unless the user explicitly opted
                // into background actions in their personal EVA settings.
                // The setting is read at execution time, so disabling it also
                // prevents actions for already queued requests.
                $this->rag->setSurface(\OCA\EvaAi\Service\ToolPolicy::SURFACE_WEB);
                $this->rag->setUserIdForExecution($user);
                $backgroundActions = $this->rag->backgroundActionsEnabled($user);
                $deadline = (int)($item['deadline'] ?? 0);
                $result = $this->rag->ask($user, (string)$item['message'], is_array($item['history'] ?? null) ? $item['history'] : [], (string)($chat['scopePath'] ?? ''), (string)($chat['instructions'] ?? ''), (string)($chat['persona'] ?? ''), null, $backgroundActions, $backgroundActions, fn(): bool => $this->queue->isCancellationRequested($user, $id) || ($deadline > 0 && time() >= $deadline), function (string $phase, ?string $tool, ?array $arguments = null) use ($user, $id): void {
                    $this->queue->updateProgress($user, $id, $phase, $tool, $arguments);
                });
                if (($result['error'] ?? null) === 'cancelled') {
                    if ($deadline > 0 && time() >= $deadline) {
                        $this->queue->markTimedOut($user, $id);
                        $notification = $this->notifications->createNotification();
                        $notification->setApp(AppConfig::APP)->setUser($user)->setObject('chat', $chatId)->setSubject('background_failed', ['text' => 'EVA background run exceeded its five-minute time limit.'])->setLink($this->urls->linkToRouteAbsolute('eva_ai.page.app') . '?chat=' . rawurlencode($chatId))->setDateTime(new \DateTime());
                        $this->notifications->notify($notification);
                    } else $this->queue->complete($user, $id);
                    continue;
                }
                $this->queue->updateProgress($user, $id, 'finalizing');
                $answer = trim((string)($result['answer'] ?? ''));
                if ($answer === '') throw new \RuntimeException((string)($result['error'] ?? 'The model returned no answer'));
                $this->chats->append($user, $chatId, 'assistant', $answer, is_array($result['followups'] ?? null) ? $result['followups'] : []);
                $notification = $this->notifications->createNotification();
                $notification->setApp(AppConfig::APP)->setUser($user)->setObject('chat', $chatId)->setSubject('answer_ready', ['text' => mb_strimwidth($answer, 0, 400, '…')])->setLink($this->urls->linkToRouteAbsolute('eva_ai.page.app') . '?chat=' . rawurlencode($chatId))->setDateTime(new \DateTime());
                $this->notifications->notify($notification);
                $this->queue->complete($user, $id, $answer);
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: background chat failed', ['user' => $user, 'job' => $id, 'exception' => $e]);
                if ($this->queue->retry($user, $id, $e->getMessage())) {
                    $notification = $this->notifications->createNotification();
                    $notification->setApp(AppConfig::APP)->setUser($user)->setObject('chat', (string)($item['chatId'] ?? ''))->setSubject('background_failed', ['text' => mb_strimwidth($e->getMessage(), 0, 400, '…')])->setLink($this->urls->linkToRouteAbsolute('eva_ai.page.app') . '?chat=' . rawurlencode((string)($item['chatId'] ?? '')))->setDateTime(new \DateTime());
                    $this->notifications->notify($notification);
                }
            }
        }
        // Timed jobs are normally picked at their interval, but a busy
        // Nextcloud queue can defer that tick for several minutes. Keep
        // draining our durable queue promptly whenever users still have
        // queued work (including delayed retries).
        if ($this->queue->users() !== []) {
            try {
                $this->jobList->scheduleAfter(self::class, time() + 5);
            } catch (\Throwable $e) {
                $this->logger->debug('eva_ai: could not schedule background chat follow-up', ['exception' => $e]);
            }
        }
    }
}
