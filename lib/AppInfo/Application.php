<?php

declare(strict_types=1);

namespace OCA\EvaAi\AppInfo;

use OCA\EvaAi\BackgroundJob\IndexJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'eva_ai';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerParameter('appId', self::APP_ID);
        // Benachrichtigungs-Notifier: zeigt "EVA answer ready" in der Glocke an.
        $context->registerNotifierService(\OCA\EvaAi\Notification\Notifier::class);
        // EVA-Provider: stellt den RAG-Chat für die Assistant-App (TaskProcessing) bereit.
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\TextToTextChatProvider::class);
        // EVA-Provider fuer den Original-Chat, den Agenten mit Bestaetigungs-Flow (core:contextagent:interaction)
        // und Chat mit Tool-Unterstuetzung (core:text2text:chatwithtools).
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\AgentInteractionProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\TextToTextChatWithToolsProvider::class);
        // EVA Text-Provider: lokale Ollama-basierte Alternative zu OpenAI für Text-Aufgaben.
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaTextToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaSummaryProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaHeadlineProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaTopicsProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaTranslateProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaReformulateProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaProofreadProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaReformatProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaChangeToneProvider::class);
        $context->registerTaskProcessingProvider(\OCA\EvaAi\TaskProcessing\EvaContextWriteProvider::class);
        // Talk-Bot: reagiert auf BotInvokeEvent, wenn Nextcloud Talk installiert ist.
        $context->registerEventListener(\OCA\Talk\Events\BotInvokeEvent::class, \OCA\EvaAi\Listener\TalkBotListener::class);
        // Talk-Bot wird zusaetzlich in boot() ueber TalkBotRegistrar registriert,
        // siehe OCA\EvaAi\Service\TalkBotRegistrar::ensureRegistered().
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

        // Talk-Bot: beim Boot sicherstellen, dass er in talk_bots_server
        // eingetragen ist. Spreed ist optional (info.xml), bei nicht
        // installierter Talk-App ist das ein No-op. Idempotent.
        try {
            $context->getAppContainer()->get(\OCA\EvaAi\Service\TalkBotRegistrar::class)
                ->ensureRegistered();
        } catch (\Throwable $e) {
            // Non-fatal: das OCC-Kommando 'eva_ai:talk:setup' bleibt als Fallback.
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

        // Files-Action: "Mit AI oeffnen" / "Mit diesen Dateien chatten" im
        // Rechtsklick-Menu des Dateimanagers. Der dritte Parameter 'files'
        // ist der Trick: Util::addScript haengt das Skript an die Files-App
        // als Dependency und liefert es auch dann aus, wenn der aktuelle
        // Request /apps/files/* ist. NC rendert das Skript dann zusammen mit
        // den Files-Skripten. comments/viewer/files_sharing machen es genauso.
        try {
            if ($context->getAppContainer()->get(IUserSession::class)->isLoggedIn()
                && $context->getAppContainer()->get(\OCP\App\IAppManager::class)->isEnabledForAnyone('files')) {
                Util::addScript(self::APP_ID, 'eva_ai_filesaction', 'files');
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }
    }
}