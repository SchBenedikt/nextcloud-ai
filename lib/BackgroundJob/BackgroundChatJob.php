<?php
declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BackgroundChatQueue;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
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
        private LoggerInterface $logger,
    ) { parent::__construct($time); $this->setInterval(30); }

    protected function run($argument): void {
        foreach ($this->queue->users() as $user) {
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
                $result = $this->rag->ask($user, (string)$item['message'], is_array($item['history'] ?? null) ? $item['history'] : [], (string)($chat['scopePath'] ?? ''), (string)($chat['instructions'] ?? ''), (string)($chat['persona'] ?? ''), null, $backgroundActions, $backgroundActions);
                $answer = trim((string)($result['answer'] ?? ''));
                if ($answer === '') throw new \RuntimeException((string)($result['error'] ?? 'The model returned no answer'));
                $this->chats->append($user, $chatId, 'assistant', $answer, is_array($result['followups'] ?? null) ? $result['followups'] : []);
                $notification = $this->notifications->createNotification();
                $notification->setApp(AppConfig::APP)->setUser($user)->setObject('chat', $chatId)->setSubject('answer_ready', ['text' => mb_strimwidth($answer, 0, 400, '…')])->setLink($this->urls->linkToRouteAbsolute('eva_ai.page.app') . '?chat=' . rawurlencode($chatId))->setDateTime(new \DateTime());
                $this->notifications->notify($notification);
                $this->queue->complete($user, $id);
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: background chat failed', ['user' => $user, 'job' => $id, 'exception' => $e]);
                if ($this->queue->retry($user, $id, $e->getMessage())) {
                    $notification = $this->notifications->createNotification();
                    $notification->setApp(AppConfig::APP)->setUser($user)->setObject('chat', (string)($item['chatId'] ?? ''))->setSubject('background_failed', ['text' => mb_strimwidth($e->getMessage(), 0, 400, '…')])->setLink($this->urls->linkToRouteAbsolute('eva_ai.page.app') . '?chat=' . rawurlencode((string)($item['chatId'] ?? '')))->setDateTime(new \DateTime());
                    $this->notifications->notify($notification);
                }
            }
        }
    }
}
