<?php

declare(strict_types=1);

namespace OCA\EvaAi\AppInfo;

use OCA\EvaAi\BackgroundJob\IndexJob;
use OCA\EvaAi\BackgroundJob\ProactiveBriefingJob;
use OCA\EvaAi\BackgroundJob\BackgroundChatJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use OCP\Util;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'eva_ai';

    /**
     * Register a provider only when the target Nextcloud core task type is
     * available. TaskProcessing gained additional task types over time, while
     * EVA supports a wider Nextcloud version range. Registering a provider for
     * a class that is not present makes TaskProcessing fail while rendering
     * unrelated pages (for example the dashboard).
     *
     * @param class-string $taskTypeClass
     * @param class-string $providerClass
     */
    private function registerTaskProcessingProviderIfSupported(
        IRegistrationContext $context,
        string $taskTypeClass,
        string $providerClass,
    ): void {
        if (class_exists($taskTypeClass)) {
            $context->registerTaskProcessingProvider($providerClass);
        }
    }

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
        // EVA ships a small production Composer dependency (Symfony YAML) for
        // OpenAPI documents published as YAML. Nextcloud does not load an
        // app's Composer autoloader automatically, so load it when present;
        // installations without the optional vendor directory keep working.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        $context->registerParameter('appId', self::APP_ID);
        $context->registerEventListener(AppEnableEvent::class, \OCA\EvaAi\Listener\AppLifecycleListener::class);
        $context->registerEventListener(AppUpdateEvent::class, \OCA\EvaAi\Listener\AppLifecycleListener::class);
        // Benachrichtigungs-Notifier: zeigt "EVA answer ready" in der Glocke an.
        $context->registerNotifierService(\OCA\EvaAi\Notification\Notifier::class);
        // Keep the TaskProcessing surface data-driven so adding a provider
        // requires one entry and cannot accidentally skip the compatibility
        // guard for older Nextcloud cores (Issue #323).
        $taskProcessingProviders = [
            [\OCP\TaskProcessing\TaskTypes\TextToTextChat::class, \OCA\EvaAi\TaskProcessing\TextToTextChatProvider::class],
            [\OCP\TaskProcessing\TaskTypes\ContextAgentInteraction::class, \OCA\EvaAi\TaskProcessing\AgentInteractionProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools::class, \OCA\EvaAi\TaskProcessing\TextToTextChatWithToolsProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToText::class, \OCA\EvaAi\TaskProcessing\EvaTextToTextProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextSummary::class, \OCA\EvaAi\TaskProcessing\EvaSummaryProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextHeadline::class, \OCA\EvaAi\TaskProcessing\EvaHeadlineProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextTopics::class, \OCA\EvaAi\TaskProcessing\EvaTopicsProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextTranslate::class, \OCA\EvaAi\TaskProcessing\EvaTranslateProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextReformulation::class, \OCA\EvaAi\TaskProcessing\EvaReformulateProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextProofread::class, \OCA\EvaAi\TaskProcessing\EvaProofreadProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs::class, \OCA\EvaAi\TaskProcessing\EvaReformatProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextChangeTone::class, \OCA\EvaAi\TaskProcessing\EvaChangeToneProvider::class],
            [\OCP\TaskProcessing\TaskTypes\ContextWrite::class, \OCA\EvaAi\TaskProcessing\EvaContextWriteProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextImprove::class, \OCA\EvaAi\TaskProcessing\EvaImproveProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextSimplification::class, \OCA\EvaAi\TaskProcessing\EvaSimplificationProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToTextFormalization::class, \OCA\EvaAi\TaskProcessing\EvaFormalizationProvider::class],
            [\OCP\TaskProcessing\TaskTypes\GenerateEmoji::class, \OCA\EvaAi\TaskProcessing\EvaEmojiProvider::class],
            [\OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition::class, \OCA\EvaAi\TaskProcessing\EvaOcrProvider::class],
            [\OCP\TaskProcessing\TaskTypes\AnalyzeImages::class, \OCA\EvaAi\TaskProcessing\EvaAnalyzeImagesProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToImage::class, \OCA\EvaAi\TaskProcessing\EvaTextToImageProvider::class],
            [\OCP\TaskProcessing\TaskTypes\AudioToText::class, \OCA\EvaAi\TaskProcessing\EvaAudioToTextProvider::class],
            [\OCP\TaskProcessing\TaskTypes\AudioToTextSubtitles::class, \OCA\EvaAi\TaskProcessing\EvaAudioSubtitlesProvider::class],
            [\OCP\TaskProcessing\TaskTypes\TextToSpeech::class, \OCA\EvaAi\TaskProcessing\EvaTextToSpeechProvider::class],
            [\OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate::class, \OCA\EvaAi\TaskProcessing\EvaAudioTranslateProvider::class],
            [\OCP\TaskProcessing\TaskTypes\AudioToAudioChat::class, \OCA\EvaAi\TaskProcessing\EvaAudioChatProvider::class],
            [\OCP\TaskProcessing\TaskTypes\MultimodalChatWithTools::class, \OCA\EvaAi\TaskProcessing\MultimodalChatWithToolsProvider::class],
            [\OCP\TaskProcessing\TaskTypes\ContextAgentAudioInteraction::class, \OCA\EvaAi\TaskProcessing\ContextAgentAudioProvider::class],
            [\OCP\TaskProcessing\TaskTypes\MultimodalContextAgentInteraction::class, \OCA\EvaAi\TaskProcessing\MultimodalContextAgentProvider::class],
        ];
        foreach ($taskProcessingProviders as [$taskType, $provider]) {
            $this->registerTaskProcessingProviderIfSupported($context, $taskType, $provider);
        }
        // Native Unified Search: expose bounded, permission-checked matches
        // from EVA's indexed file knowledge without invoking an AI model.
        $context->registerSearchProvider(\OCA\EvaAi\Search\EvaSearchProvider::class);
        // Talk-Bot: reagiert auf BotInvokeEvent, wenn Nextcloud Talk installiert ist.
        $context->registerEventListener(\OCA\Talk\Events\BotInvokeEvent::class, \OCA\EvaAi\Listener\TalkBotListener::class);
        // GDPR-Erasure: Kontenloeschung raeumt alle eva_ai-Daten des Users ab (Issue #83).
        $context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, \OCA\EvaAi\Listener\UserDeletedListener::class);
        // Incremental re-indexing via file hooks (Issue #79): edits, creates,
        // renames and deletes queue a debounced background reindex per user
        // instead of waiting for the next full scan.
        $context->registerEventListener(\OCP\Files\Events\Node\NodeCreatedEvent::class, \OCA\EvaAi\Listener\FileChangeListener::class);
        $context->registerEventListener(\OCP\Files\Events\Node\NodeWrittenEvent::class, \OCA\EvaAi\Listener\FileChangeListener::class);
        $context->registerEventListener(\OCP\Files\Events\Node\NodeDeletedEvent::class, \OCA\EvaAi\Listener\FileChangeListener::class);
        $context->registerEventListener(\OCP\Files\Events\Node\NodeRenamedEvent::class, \OCA\EvaAi\Listener\FileChangeListener::class);
        // Dashboard-Widget: EVA AI Quick-Chat und Status auf dem Dashboard.
        $context->registerDashboardWidget(\OCA\EvaAi\Dashboard\EVAWidget::class);
        // Talk-Bot wird zusaetzlich in boot() ueber TalkBotRegistrar registriert,
        // siehe OCA\EvaAi\Service\TalkBotRegistrar::ensureRegistered().
    }

    public function boot(IBootContext $context): void {
        // Ensure the periodic indexing job is scheduled. In NC 34 + MySQL can
        // a DB read inside an open request transaction raise, so we guard it –
        // the job is also (re-)added by the index API endpoint on demand.
        try {
            $container = $context->getAppContainer();
            $jobs = $container->get(IJobList::class);
            if (!$jobs->has(IndexJob::class, null)) $jobs->add(IndexJob::class);
            // Existing installations do not re-read info.xml until the next
            // enable/upgrade, therefore register the new opt-in job here too.
            if (!$jobs->has(ProactiveBriefingJob::class, null)) $jobs->add(ProactiveBriefingJob::class);
            if (!$jobs->has(BackgroundChatJob::class, null)) $jobs->add(BackgroundChatJob::class);
        } catch (\Throwable $e) {
            $this->logBootstrapFailure('background jobs', $e);
        }

        // Talk-Bot: beim Boot sicherstellen, dass er in talk_bots_server
        // eingetragen ist. Spreed ist optional (info.xml), bei nicht
        // installierter Talk-App ist das ein No-op. Idempotent.
        try {
            $context->getAppContainer()->get(\OCA\EvaAi\Service\TalkBotRegistrar::class)
                ->ensureRegistered();
        } catch (\Throwable $e) {
            $this->logBootstrapFailure('Talk bot registration', $e);
        }

        // Header-Button: AI-Icon rechts oben neben den Benachrichtigungen,
        // damit man aus jeder Ansicht direkt in den Chat springen kann.
        try {
            if ($context->getAppContainer()->get(IUserSession::class)->isLoggedIn()) {
                Util::addScript(self::APP_ID, 'header');
            }
        } catch (\Throwable $e) {
            $this->logBootstrapFailure('header button', $e);
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
            $this->logBootstrapFailure('Files action', $e);
        }
    }

    /** Log optional bootstrap failures without making boot dependent on logging. */
    private function logBootstrapFailure(string $component, \Throwable $e): void {
        try {
            \OC::$server->get(\Psr\Log\LoggerInterface::class)->warning(
                'eva_ai: optional bootstrap component failed',
                ['component' => $component, 'exception' => $e->getMessage()]
            );
        } catch (\Throwable) {
            // Logging must never turn an optional bootstrap failure into a boot failure.
        }
    }
}
