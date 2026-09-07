<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\UserDataService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * GDPR erasure (Issue #83): when a Nextcloud account is deleted, remove every
 * eva_ai row, AppData folder, KNOWLEDGE.md and per-user config value. The
 * cleanup is best-effort and must never abort the account deletion.
 *
 * @implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    public function __construct(private UserDataService $userData) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }
        $this->userData->cleanupDeletedAccount((string)$event->getUser()->getUID());
    }
}
