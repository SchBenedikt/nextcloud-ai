<?php

declare(strict_types=1);

namespace OCA\EvaAi\Settings;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\WebSearchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Native Nextcloud admin settings for instance-wide EVA configuration.
 */
class Admin implements ISettings {
    public function __construct(
        private AppConfig $config,
        private DocumentMapper $documentMapper,
        private IndexScheduler $scheduler,
        private WebSearchService $webSearch,
        private IURLGenerator $urlGenerator,
        private ?\OCA\EvaAi\Vector\VectorStorageStatus $vectorStorageStatus = null,
    ) {
    }

    public function getForm(): TemplateResponse {
        // Load dedicated admin JS/CSS (not the heavy Vue SPA bundle).
        Util::addScript('eva_ai', 'admin-settings');
        Util::addStyle('eva_ai', 'admin-settings');

        $this->config->setUserId(null);

        // Instance-wide admin settings.
        $adminSettings = $this->config->adminAll();

        $totals = $this->documentMapper->aggregateInstance();
        $schedulerSummary = $this->scheduler->summary();

        // API base for AJAX requests.
        $apiBase = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/');

        return new TemplateResponse('eva_ai', 'admin', [
            'adminSettings' => $adminSettings,
            'vectorStorage' => $this->vectorStorageStatus?->snapshot() ?? ['available' => false],
            'totals' => $totals,
            'scheduler' => $schedulerSummary,
            'apiBase' => $apiBase,
            'storageAdminUrl' => $this->urlGenerator->linkToRoute('eva_ai.page.admin'),
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
