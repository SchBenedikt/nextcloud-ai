<?php
declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\BackgroundJob\JobRegistry;
use OCA\EvaAi\Service\AppConfig;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** Ensures scheduled jobs exist without changing user data during app updates. */
final class AppLifecycleListener implements IEventListener {
    public function __construct(private IJobList $jobs) {
    }

    public function handle(Event $event): void {
        $appId = $event instanceof AppEnableEvent || $event instanceof AppUpdateEvent
            ? $event->getAppId()
            : '';
        if ($appId !== AppConfig::APP) {
            return;
        }

        JobRegistry::ensureScheduled($this->jobs);
    }
}
