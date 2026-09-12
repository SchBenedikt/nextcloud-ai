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
        // status()['ping'] is the detail array (ok/url/error), not a boolean:
        // coercing the array reported "connected" for every install, even with
        // an unreachable model server.
        $ollamaStatus = $this->ollama->status();
        $ollamaOnline = (bool)($ollamaStatus['ping']['ok'] ?? false);
        $ollamaError = (string)($ollamaStatus['ping']['error'] ?? '');

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
        $totalFailed = 0;
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
                // Files that could not be read or embedded on the last pass.
                // They are retried next time and never stop the run, but the
                // admin should be able to see that something is off.
                'failed' => $this->config->getInt('last_index_failed', 0),
            ];
            $totalDocuments += (int)($agg['documents'] ?? 0);
            $totalChunks += (int)($agg['chunks'] ?? 0);
            $totalFailed += $this->config->getInt('last_index_failed', 0);
        }
        $this->config->setUserId(null);
        usort($users, static fn(array $a, array $b): int => strcmp($a['userId'], $b['userId']));

        $schedulerOverview = $this->scheduler->overview();

        // API base for AJAX requests.
        $apiBase = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/');

        return new TemplateResponse('eva_ai', 'admin', [
            // Skipped-file counts are per-user runtime state, so they are summed
            // here instead of being read from adminAll().
            'adminSettings' => $adminSettings + ['last_index_failed' => (string)$totalFailed],
            'ollamaOnline' => $ollamaOnline,
            'ollamaError' => $ollamaError,
            // The effective instance value, not a hardcoded default: the admin
            // page used to display http://127.0.0.1:11434 even when a different
            // server was configured.
            'ollamaUrl' => $this->config->ollamaUrl(),
            'users' => $users,
            'userCount' => count($users),
            'totalDocuments' => $totalDocuments,
            'totalChunks' => $totalChunks,
            'scheduler' => $schedulerOverview,
            'apiBase' => $apiBase,
            'webSearchConfigured' => $this->webSearch->isConfigured(),
            'webSearchKeyConfigured' => $this->webSearch->hasApiKey(),
            'webSearchProviders' => WebSearchService::PROVIDERS,
            'webSearchBrowserStatus' => $this->webSearch->browserStatus(),
            'webSearchBrowserBrowsersPath' => $this->webSearch->browserBrowsersPath(),
            'webSearchBrowserInstallCommand' => $this->webSearch->browserInstallCommand(),
        ]);
    }

    public function getSection(): string {
        return 'eva_ai';
    }

    public function getPriority(): int {
        return 10;
    }
}
