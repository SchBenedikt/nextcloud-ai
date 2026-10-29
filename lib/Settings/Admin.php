<?php

declare(strict_types=1);

namespace OCA\EvaAi\Settings;

use OCA\EvaAi\Service\AppConfig;
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

        // API base for AJAX requests.
        $apiBase = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/');

        return new TemplateResponse('eva_ai', 'admin', [
            'adminSettings' => $adminSettings,
            'apiBase' => $apiBase,
            'storageAdminUrl' => $this->urlGenerator->linkToRoute('eva_ai.page.admin'),
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
