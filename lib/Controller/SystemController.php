<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BackgroundChatQueue;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\UsageMetrics;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Read-only system and dashboard endpoints for the EVA application. */
final class SystemController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private RagService $ragService,
        private DocumentMapper $documentMapper,
        private ChatStore $chatStore,
        private KnowledgeInitializer $knowledgeInitializer,
        private IndexScheduler $indexScheduler,
        private UsageMetrics $usageMetrics,
        private Ollama $ollama,
        private ICacheFactory $cacheFactory,
        private BackgroundChatQueue $backgroundChatQueue,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
        $this->config->setUserId($this->userId);
    }

    private function requireUser(): ?string {
        return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
    }

    #[NoAdminRequired]
    public function status(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->knowledgeInitializer->ensureInitialized($user);
        $status = $this->ragService->buildStatus($user);
        try {
            $credentials = \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class);
            $status['genericApi'] = [
                'tokenConfigured' => $credentials->nextcloudTokenConfigured($user),
            ];
            $providerId = (string)$this->config->get('chat_provider');
            $status['customProvider'] = [
                'providerId' => $providerId,
                'keyConfigured' => !in_array($providerId, ['ollama', 'groq'], true)
                    && $credentials->customConfigured($user, $providerId),
            ];
        } catch (\Throwable) {
            $status['genericApi'] = ['tokenConfigured' => false];
            $status['customProvider'] = ['providerId' => '', 'keyConfigured' => false];
        }
        // Fair multi-user scheduling snapshot (Issue #142): global running
        // count, limit and this user's queue position - cheap, no polling.
        $status['scheduler'] = $this->indexScheduler->snapshot($user);
        return new DataResponse($status);
    }

    /**
     * Dashboard summary (Issue: app home): document/chunk/size aggregates,
     * chat counts + recent chats, folder count and a slim status snapshot.
     */
    #[NoAdminRequired]
    public function stats(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $this->knowledgeInitializer->ensureInitialized($user);
            $agg = $this->documentMapper->aggregateForUser($user);
            $status = $this->ragService->buildStatus($user);
            $chats = $this->chatStore->list($user, null, true);
            $active = array_values(array_filter($chats, static fn($c) => empty($c['archived'])));
            $recent = $active;
            usort($recent, static fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
            return new DataResponse([
                'documents' => [
                    'count' => $agg['count'],
                    'chunks' => $agg['chunks'],
                    'size' => $agg['size'],
                ],
                'chats' => [
                    'total' => count($chats),
                    'active' => count($active),
                    'archived' => count($chats) - count($active),
                    'recent' => array_slice($recent, 0, 6),
                ],
                'folders' => count($this->chatStore->listFolders($user)),
                'status' => [
                    'ollamaOnline' => (bool)($status['ollamaOnline'] ?? false),
                    'ollamaError' => $status['ollamaError'] ?? null,
                    'chatModel' => $status['chatModel'] ?? '',
                    'embeddingModel' => $status['embeddingModel'] ?? '',
                    'indexing' => (bool)($status['indexing'] ?? false),
                    'lastFinished' => $status['lastFinished'] ?? null,
                    'lastError' => $status['lastError'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof \OCA\EvaAi\Service\ChatStoreBusyException) {
                return new DataResponse(['error' => 'busy', 'message' => $e->getMessage()], 503);
            }
            $this->logger->error('eva_ai: dashboard summary failed', [
                'user' => $user,
                'exception' => $e->getMessage(),
            ]);
            return new DataResponse(['error' => 'Unable to build dashboard summary'], 500);
        }
    }

    /** Privacy-preserving model usage for the signed-in user. */
    #[NoAdminRequired]
    public function metrics(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        return new DataResponse($this->usageMetrics->summaryForUser($user));
    }

    /** Lightweight diagnostics for troubleshooting a slow or incomplete install. */
    #[NoAdminRequired]
    public function health(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new DataResponse(['error' => 'Not logged in'], 401);
        $cache = $this->cacheFactory->createDistributed('eva_ai_health_');
        $cacheKey = 'health_' . substr(hash('sha256', $user), 0, 24);
        $cached = $cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) return new DataResponse($decoded, !empty($decoded['ok']) ? 200 : 503);
        }
        $provider = $this->ollama->status();
        $queue = $this->backgroundChatQueue->status($user);
        $activeQueue = count(array_filter($queue, static fn(array $item): bool => in_array($item['status'] ?? '', ['pending', 'running'], true)));
        $lastIndexError = (string)$this->config->get('index_error');
        $checks = [
            'provider' => (bool)($provider['ping']['ok'] ?? false),
            'chat_model' => $this->ollama->selectedChatModel() !== '',
            'index' => $lastIndexError === '',
            'queue' => $activeQueue < 10,
        ];
        $payload = [
            'ok' => !in_array(false, $checks, true),
            'checks' => $checks,
            'provider' => ['online' => $checks['provider'], 'model' => $this->ollama->selectedChatModel(), 'latency_ms' => $provider['meta']['latencyMs'] ?? null],
            'queue' => ['active' => $activeQueue, 'total' => count($queue)],
            'index' => ['last_error' => $lastIndexError !== '' ? $lastIndexError : null],
            'generated_at' => time(),
        ];
        try { $cache->set($cacheKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 10); } catch (\Throwable) { }
        return new DataResponse($payload, !in_array(false, $checks, true) ? 200 : 503);
    }

    /** Return a concise, localized time-of-day greeting for the dashboard. */
    #[NoAdminRequired]
    public function greeting(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        $this->config->setUserId($user);
        $period = $this->dayPeriod();
        $lang = $this->uiLanguage();
        $cacheKey = 'greeting_' . substr(hash('sha256', $user), 0, 16) . '_' . $period . '_' . substr(hash('sha256', $lang), 0, 8);
        $cache = $this->cacheFactory->createDistributed('eva_ai_greeting_');
        $cached = $cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return new DataResponse(['greeting' => $cached, 'period' => $period]);
        }
        $greeting = $this->staticGreeting($period, $lang);
        $cache->set($cacheKey, $greeting, 6 * 3600);
        return new DataResponse(['greeting' => $greeting, 'period' => $period]);
    }

    /** 'night'|'morning'|'afternoon'|'evening' based on the server's clock. */
    private function dayPeriod(): string {
        $h = (int)date('G');
        if ($h < 5) {
            return 'night';
        }
        if ($h < 12) {
            return 'morning';
        }
        if ($h < 18) {
            return 'afternoon';
        }
        return 'evening';
    }

    /** The user's Nextcloud UI language ('de', 'en', ...). */
    private function uiLanguage(): string {
        try {
            return \OCP\Server::get(\OCP\L10N\IFactory::class)->findLanguage('eva_ai');
        } catch (\Throwable $e) {
            return 'en';
        }
    }

    /** Static fallback greeting in the user's UI language. */
    private function staticGreeting(string $period, string $lang): string {
        $de = ['night' => 'Gute Nacht', 'morning' => 'Guten Morgen', 'afternoon' => 'Guten Tag', 'evening' => 'Guten Abend'];
        $en = ['night' => 'Good night', 'morning' => 'Good morning', 'afternoon' => 'Good afternoon', 'evening' => 'Good evening'];
        $map = str_starts_with($lang, 'de') ? $de : $en;
        return $map[$period] ?? $map['morning'];
    }

}
