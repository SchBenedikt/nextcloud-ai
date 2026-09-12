<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
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
    ) {
        parent::__construct($time);
        // Nextcloud cron itself may run less often. The per-minute interval
        // means no artificial delay is added on installations with AJAX/Webcron.
        $this->setInterval(60);
    }

    protected function run($argument): void {
        try {
            $users = $this->rawConfig->getUsersForUserValue(AppConfig::APP, 'proactive_enabled', '1');
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: unable to enumerate proactive schedules', ['exception' => $e]);
            return;
        }
        foreach (array_unique(array_map('strval', $users)) as $userId) {
            $this->deliverDueSchedules($userId);
        }
    }

    private function deliverDueSchedules(string $userId): void {
        $this->config->setUserId($userId);
        $schedules = json_decode($this->config->get('proactive_schedules'), true);
        if (!is_array($schedules)) {
            return;
        }
        $runs = json_decode($this->config->get('proactive_schedule_runs'), true);
        $runs = is_array($runs) ? $runs : [];
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
            if ($id === '' || $prompt === '' || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) !== 1 || !in_array($weekday, $days, true)) {
                continue;
            }
            $allowActions = ($schedule['allow_actions'] ?? false) === true;
            // A cron run can arrive a few minutes late, but it must never send
            // the same briefing twice after retries or concurrent workers.
            $slot = $now->format('Y-m-d') . ' ' . $time;
            if (($runs[$id] ?? '') === $slot || $minute < $time || $minute > (new \DateTimeImmutable($time))->modify('+9 minutes')->format('H:i')) {
                continue;
            }
            try {
                if ($allowActions) {
                    $this->rag->setSurface(ToolPolicy::SURFACE_TASKPROCESSING_CONFIRMED);
                }
                $mode = $allowActions
                    ? 'This briefing explicitly allows autonomous actions. Execute only actions needed for the request, and do not invent extra work.'
                    : 'You are in read-only scheduled mode: never execute, propose, or request confirmation for actions.';
                $answer = $this->rag->ask($userId, "Scheduled EVA briefing. Answer the following request concisely. " . $mode . "\n\n" . $prompt, [], '', '', '', null, $allowActions, $allowActions);
                $text = trim((string)($answer['answer'] ?? ''));
                if ($text === '') {
                    throw new \RuntimeException('empty model answer');
                }
                $notification = $this->notifications->createNotification();
                $notification->setApp(AppConfig::APP)
                    ->setUser($userId)
                    ->setObject('proactive', $id . '-' . $now->format('YmdHi'))
                    ->setSubject('scheduled_briefing', ['text' => mb_strimwidth($text, 0, 1000, '…')])
                    ->setLink($this->urlGenerator->linkToRouteAbsolute('eva_ai.page.app'))
                    ->setDateTime(new \DateTime());
                $this->notifications->notify($notification);
                $runs[$id] = $slot;
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: proactive briefing failed', ['user' => $userId, 'schedule' => $id, 'exception' => $e]);
            }
        }
        $this->config->set('proactive_schedule_runs', json_encode($runs, JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
