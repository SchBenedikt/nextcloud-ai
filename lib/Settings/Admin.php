<?php

declare(strict_types=1);

namespace OCA\EvaAi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings form (Issue #82): renders the Vue admin dashboard inside
 * the Nextcloud administration settings. The frontend reads the initial
 * state flag and shows the admin view instead of the personal chat app.
 */
class Admin implements ISettings {
    public function __construct(
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('admin_mode', true);
        $this->initialState->provideInitialState(
            'api_base',
            $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/')
        );
        // Load the same production bundle as the main app page.
        $jsDir = $this->appManager->getAppPath('eva_ai') . '/js';
        $main = null;
        $candidates = [];
        foreach (glob($jsDir . '/eva_ai-main*.js') ?: [] as $file) {
            $base = basename($file);
            if (!str_starts_with($base, 'eva_ai-main') || str_ends_with($base, '.map')) {
                continue;
            }
            $candidates[$file] = filemtime($file);
        }
        if ($candidates !== []) {
            arsort($candidates);
            $main = basename((string)array_key_first($candidates), '.js');
        }
        if ($main !== null) {
            Util::addScript('eva_ai', $main);
        }
        return new TemplateResponse('eva_ai', 'admin', [
            'apiBase' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
        ]);
    }

    public function getSection(): string {
        return 'eva_ai';
    }

    public function getPriority(): int {
        return 10;
    }
}