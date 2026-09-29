<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkChatService;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Lock\ILockingProvider;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Delivers explicitly configured EVA briefings through Nextcloud's notification
 * centre. Schedules are strictly opt-in and read-only by default. A schedule
 * may explicitly opt into autonomous actions; that opt-in is kept per
 * schedule and the agent still fails closed when an app token is unavailable.
 */
final class ProactiveBriefingJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private IConfig $rawConfig,
        private RagService $rag,
        private IManager $notifications,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ILockingProvider $lockingProvider,
        private IMailer $mailer,
        private IUserManager $userManager,
        private TalkChatService $talkChat,
    ) {
        parent::__construct($time);
        // Nextcloud cron itself may run less often. The per-minute interval
        // means no artificial delay is added on installations with AJAX/Webcron.
        $this->setInterval(60);
    }

    protected function run($argument): void {
        $lockPath = 'eva_ai/proactive-briefing-job';
        $locked = false;
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, self::class);
            $locked = true;
        } catch (\Throwable $e) {
            // Nextcloud's lock provider rejects an overlapping cron run. Skip
            // it; the active worker will persist the delivered time slots.
            $this->logger->debug('eva_ai: skipped overlapping proactive briefing run', ['exception' => $e->getMessage()]);
            return;
        }
        try {
            $targetUser = is_array($argument) ? trim((string)($argument['userId'] ?? '')) : '';
            $targetBriefing = is_array($argument) ? trim((string)($argument['briefingId'] ?? '')) : '';
            $manual = is_array($argument) && ($argument['manual'] ?? false) === true;
            if ($manual) {
                if ($targetUser === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $targetBriefing) !== 1) {
                    $this->logger->warning('eva_ai: rejected invalid manual briefing job arguments');
                    return;
                }
                $user = $this->userManager->get($targetUser);
                if ($user === null || !$user->isEnabled()) return;
                $users = [$targetUser];
            } else {
                try {
                    $users = $this->rawConfig->getUsersForUserValue(AppConfig::APP, 'proactive_enabled', '1');
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai: unable to enumerate proactive schedules', ['exception' => $e]);
                    return;
                }
            }
            foreach (array_unique(array_map('strval', $users)) as $userId) {
                $this->deliverDueSchedules($userId, $manual && $userId === $targetUser ? $targetBriefing : null);
            }
        } finally {
            if ($locked) {
                try {
                    $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Throwable $e) {
                    $this->logger->debug('eva_ai: could not release proactive briefing lock', ['exception' => $e->getMessage()]);
                }
            }
        }
    }

    private function deliverDueSchedules(string $userId, ?string $forcedBriefingId = null): void {
        $this->config->setUserId($userId);
        if ($forcedBriefingId !== null && $this->config->get('proactive_enabled') !== '1') return;
        $schedules = json_decode($this->config->get('proactive_schedules'), true);
        if (!is_array($schedules)) {
            return;
        }
        $runs = json_decode($this->config->get('proactive_schedule_runs'), true);
        $runs = is_array($runs) ? $runs : [];
        $history = json_decode($this->config->get('proactive_schedule_history'), true);
        $history = is_array($history) ? $history : [];
        $timezone = trim((string)$this->rawConfig->getUserValue($userId, 'core', 'timezone', ''));
        try {
            $now = new \DateTimeImmutable('now', $timezone !== '' ? new \DateTimeZone($timezone) : null);
        } catch (\Throwable) {
            $now = new \DateTimeImmutable('now');
        }
        $weekday = (int)$now->format('N');
        $minute = $now->format('H:i');
        foreach (array_slice($schedules, 0, 20) as $schedule) {
            if (!is_array($schedule) || ($schedule['enabled'] ?? true) !== true) {
                continue;
            }
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($schedule['id'] ?? '')) ?? '';
            $prompt = trim((string)($schedule['prompt'] ?? ''));
            $time = (string)($schedule['time'] ?? '');
            $days = array_map('intval', is_array($schedule['days'] ?? null) ? $schedule['days'] : []);
            if ($id === '' || ($forcedBriefingId !== null && $id !== $forcedBriefingId) || $prompt === '' || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) !== 1 || ($forcedBriefingId === null && !in_array($weekday, $days, true))) {
                continue;
            }
            $allowActions = ($schedule['allow_actions'] ?? false) === true;
            // A cron run can arrive a few minutes late, but it must never send
            // the same briefing twice after retries or concurrent workers.
            $slot = $now->format('Y-m-d') . ' ' . $time;
            if ($forcedBriefingId === null) {
                $scheduledAt = $now->setTime((int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
                if (($runs[$id] ?? '') === $slot || $now < $scheduledAt || $now > $scheduledAt->modify('+9 minutes')) continue;
            }
            try {
                if ($allowActions) {
                    $this->rag->setSurface(ToolPolicy::SURFACE_TASKPROCESSING_CONFIRMED);
                }
                $mode = $allowActions
                    ? 'This briefing explicitly allows autonomous actions. Execute only actions needed for the request, and do not invent extra work.'
                    : 'You are in read-only scheduled mode: never execute, propose, or request confirmation for actions.';
                $briefingType = match ($schedule['type'] ?? 'custom') {
                    'morning' => 'daily morning briefing',
                    'document_digest' => 'digest of new or updated indexed documents',
                    default => 'custom scheduled report',
                };
                $answer = $this->rag->ask(new \OCA\EvaAi\Dto\ChatRequest(
                    userId: $userId,
                    message: "Scheduled EVA {$briefingType}. Answer the following request concisely. " . $mode . "\n\n" . $prompt,
                    allowActions: $allowActions,
                    autonomousActions: $allowActions,
                ));
                $text = trim((string)($answer['answer'] ?? ''));
                if ($text === '') {
                    throw new \RuntimeException('empty model answer');
                }
                $channels = $schedule['channels'] ?? ['notification'];
                if (!is_array($channels) || $channels === []) $channels = ['notification'];
                $delivered = [];
                $failures = [];
                foreach (array_values(array_unique($channels)) as $channel) {
                    try {
                        if ($channel === 'notification') {
                            $notification = $this->notifications->createNotification();
                            $notification->setApp(AppConfig::APP)
                                ->setUser($userId)
                                ->setObject('proactive', $id . '-' . $now->format('YmdHi') . '-' . ($forcedBriefingId !== null ? bin2hex(random_bytes(3)) : 'scheduled'))
                                ->setSubject('scheduled_briefing', ['text' => mb_strimwidth($text, 0, 1000, '…')])
                                ->setLink($this->urlGenerator->linkToRouteAbsolute('eva_ai.page.app'))
                                ->setDateTime(new \DateTime());
                            $this->notifications->notify($notification);
                        } elseif ($channel === 'email') {
                            $user = $this->userManager->get($userId);
                            $email = trim((string)($user?->getEMailAddress() ?? ''));
                            if ($email === '') throw new \RuntimeException('Add an email address to your Nextcloud profile first.');
                            $message = $this->mailer->createMessage();
                            $message->setSubject('EVA briefing: ' . mb_strimwidth($prompt, 0, 70, '…'))
                                ->setPlainBody($text)
                                ->setTo([$email]);
                            $failed = $this->mailer->send($message);
                            if ($failed !== []) throw new \RuntimeException('The configured mail server rejected the recipient.');
                        } elseif ($channel === 'talk') {
                            $result = $this->talkChat->send($userId, (string)($schedule['talk_room'] ?? ''), mb_strimwidth($text, 0, TalkChatService::MAX_MESSAGE_CHARS, '…'));
                            if (($result['ok'] ?? false) !== true) throw new \RuntimeException((string)($result['error'] ?? 'Talk delivery failed.'));
                        } else {
                            throw new \RuntimeException('Unsupported briefing delivery channel.');
                        }
                        $delivered[] = $channel;
                    } catch (\Throwable $deliveryError) {
                        $failures[$channel] = mb_strimwidth($deliveryError->getMessage(), 0, 240, '…');
                    }
                }
                if ($delivered === []) throw new \RuntimeException('No delivery channel succeeded: ' . implode('; ', $failures));
                if ($forcedBriefingId === null) $runs[$id] = $slot;
                $history[] = [
                    'id' => $id,
                    'type' => (string)($schedule['type'] ?? 'custom'),
                    'prompt' => mb_strimwidth($prompt, 0, 500, '…'),
                    'text' => mb_strimwidth($text, 0, 4000, '…'),
                    'channels' => $delivered,
                    'failures' => $failures,
                    'status' => $failures === [] ? 'success' : 'partial',
                    'manual' => $forcedBriefingId !== null,
                    'time' => time(),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: proactive briefing failed', ['user' => $userId, 'schedule' => $id, 'exception' => $e]);
                $history[] = [
                    'id' => $id,
                    'type' => (string)($schedule['type'] ?? 'custom'),
                    'prompt' => mb_strimwidth($prompt, 0, 500, '…'),
                    'text' => '',
                    'channels' => [],
                    'failures' => ['briefing' => mb_strimwidth($e->getMessage(), 0, 240, '…')],
                    'status' => 'failed',
                    'manual' => $forcedBriefingId !== null,
                    'time' => time(),
                ];
            }
        }
        $this->config->set('proactive_schedule_runs', json_encode($runs, JSON_UNESCAPED_SLASHES) ?: '{}');
        $this->config->set('proactive_schedule_history', json_encode(array_slice($history, -50), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
