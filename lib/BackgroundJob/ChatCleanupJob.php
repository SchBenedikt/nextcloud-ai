<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Daily cleanup of old chats (chat retention). For every user whose
 * `chat_retention_days` setting is greater than 0, chats that have not been
 * used for that many days are deleted. Users with 0 (the default) or no chat
 * data are skipped quickly. The per-user scan is bounded by a time budget so
 * one large instance cannot block a cron tick forever.
 */
class ChatCleanupJob extends TimedJob {
    private const MAX_SECONDS = 50;
    private const BATCH = 200;

    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private ChatStore $chatStore,
        private IUserManager $userManager,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
    }

    protected function run($argument): void {
        $startedAt = time();
        $offset = 0;
        while (true) {
            $users = $this->userManager->search('', self::BATCH, $offset);
            if ($users === []) {
                break;
            }
            $offset += count($users);
            foreach ($users as $user) {
                if (time() - $startedAt >= self::MAX_SECONDS) {
                    $this->logger->info('eva_ai chat cleanup budget reached', [
                        'budget_seconds' => self::MAX_SECONDS,
                    ]);
                    return;
                }
                $uid = $user->getUID();
                if ($uid === '') {
                    continue;
                }
                $this->config->setUserId($uid);
                $days = (int)$this->config->get('chat_retention_days');
                if ($days <= 0) {
                    continue;
                }
                try {
                    $deleted = $this->chatStore->deleteOlderThan($uid, $days);
                    if ($deleted > 0) {
                        $this->logger->info('eva_ai chat cleanup', [
                            'user' => $uid,
                            'deleted' => $deleted,
                            'retention_days' => $days,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai chat cleanup failed for user', [
                        'user' => $uid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
        $this->config->setUserId(null);
    }
}