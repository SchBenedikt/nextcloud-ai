<?php

declare(strict_types=1);

namespace OCA\RagChat\AppInfo;

use OCA\RagChat\BackgroundJob\IndexJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'ragchat';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerParameter('appId', self::APP_ID);
        // Benachrichtigungs-Notifier: zeigt "AI answer ready" in der Glocke an.
        $context->registerNotifierService(\OCA\RagChat\Notification\Notifier::class);
        // AI-Provider: stellt den RAG-Chat für die Assistant-App (TaskProcessing) bereit.
        $context->registerTaskProcessingProvider(\OCA\RagChat\TaskProcessing\TextToTextChatProvider::class);
    }

    public function boot(IBootContext $context): void {
        // Ensure the periodic indexing job is scheduled. In NC 34 + MySQL can
        // a DB read inside an open request transaction raise, so we guard it –
        // the job is also (re-)added by the index API endpoint on demand.
        try {
            $container = $context->getAppContainer();
            $container->get(IJobList::class)->add(IndexJob::class);
        } catch (\Throwable $e) {
            // Non-fatal: indexing is also triggered explicitly via the API.
        }

        // Header-Button: AI-Icon rechts oben neben den Benachrichtigungen,
        // damit man aus jeder Ansicht direkt in den Chat springen kann.
        try {
            if ($context->getAppContainer()->get(IUserSession::class)->isLoggedIn()) {
                Util::addScript(self::APP_ID, 'header');
            }
        } catch (\Throwable $e) {
            // Non-fatal: Button ist ein reines Komfort-Feature.
        }
    }
}