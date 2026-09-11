<?php

declare(strict_types=1);

namespace OCA\EvaAi\Settings;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\WebSearchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings page (Issue #82): renders the native Nextcloud admin
 * dashboard for Eva AI. The template follows the same pattern as the
 * Social app — server-side rendered sections with AJAX interactions.
 */
class Admin implements ISettings {
    public function __construct(
        private AppConfig $config,
        private Ollama $ollama,
        private DocumentMapper $documentMapper,
        private IndexScheduler $scheduler,
        private IUserManager $userManager,
        private WebSearchService $webSearch,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm(): TemplateResponse {
        // Load dedicated admin JS/CSS (not the heavy Vue SPA bundle).
        Util::addScript('eva_ai', 'admin-settings');
        Util::addStyle('eva_ai', 'admin-settings');

        $this->config->setUserId(null);

        // Instance-wide admin settings.
        $adminSettings = $this->config->adminAll();

        // Ollama health check.
        $ollamaStatus = $this->ollama->status();
        $ollamaOnline = (bool)($ollamaStatus['ping'] ?? false);

        // User overview data.
        $aggregates = $this->documentMapper->aggregatePerUser();
        $enrolled = $this->config->enrolledUserIds();
        $userIdSet = [];
        foreach ($aggregates as $row) {
            $userIdSet[$row['user_id']] = true;
        }
        foreach ($enrolled as $uid) {
            if ($uid !== '') {
                $userIdSet[$uid] = true;
            }
        }

        $totalDocuments = 0;
        $totalChunks = 0;
        $users = [];
        foreach (array_keys($userIdSet) as $uid) {
            $agg = null;
            foreach ($aggregates as $row) {
                if ($row['user_id'] === $uid) {
                    $agg = $row;
                    break;
                }
            }
            $displayName = '';
            $user = $this->userManager->get($uid);
            if ($user !== null) {
                $displayName = (string)$user->getDisplayName();
            }
            $this->config->setUserId($uid);
            $users[] = [
                'userId' => $uid,
                'displayName' => $displayName !== '' ? $displayName : $uid,
                'documents' => (int)($agg['documents'] ?? 0),
                'chunks' => (int)($agg['chunks'] ?? 0),
                'lastIndexedAt' => $agg['last_indexed_at'] ?? null,
                'enrolled' => $this->config->get('index_enrolled') === '1',
                'indexing' => $this->config->get('index_running') === '1',
                'mode' => $this->config->get('index_mode'),
                'error' => $this->config->get('last_index_error'),
            ];
            $totalDocuments += (int)($agg['documents'] ?? 0);
            $totalChunks += (int)($agg['chunks'] ?? 0);
        }
        $this->config->setUserId(null);
        usort($users, static fn(array $a, array $b): int => strcmp($a['userId'], $b['userId']));

        $schedulerOverview = $this->scheduler->overview();

        // API base for AJAX requests.
        $apiBase = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/');

        return new TemplateResponse('eva_ai', 'admin', [
            'adminSettings' => $adminSettings,
            'ollamaOnline' => $ollamaOnline,
            'ollamaUrl' => $adminSettings['ollama_url'] ?? 'http://127.0.0.1:11434',
            'users' => $users,
            'userCount' => count($users),
            'totalDocuments' => $totalDocuments,
            'totalChunks' => $totalChunks,
            'scheduler' => $schedulerOverview,
            'apiBase' => $apiBase,
            'webSearchConfigured' => $this->webSearch->isConfigured(),
            'webSearchKeyConfigured' => $this->webSearch->hasApiKey(),
            'webSearchProviders' => WebSearchService::PROVIDERS,
        ]);
    }

    public function getSection(): string {
        return 'eva_ai';
    }

    public function getPriority(): int {
        return 10;
    }
}
