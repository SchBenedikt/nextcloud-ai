<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

use OCA\EvaAi\Service\RagService;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
        private RagService $ragService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Erste Seite: die reguläre Nextcloud-App-Shell (Vue/NC-Stil wie Files).
     * Der Chat selbst wird intern von einem Vanilla-Script aufgebaut.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function app(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function settings(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function documents(): TemplateResponse {
        return $this->appPage('index');
    }

    /**
     * Fallback ohne App-Shell: reine Chat-Seite (Vanilla-HTML, eigenes Layout).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function standalone(): TemplateResponse {
        Util::addTranslations('eva_ai');
        \OCP\Util::addScript('eva_ai', 'chat');
        \OCP\Util::addHeader('meta', [
            'name' => 'requesttoken',
            'content' => \OC::$server->get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue(),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-api',
            'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-stream',
            'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/streamChat?format=json'),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-version',
            'content' => 'standalone-1',
        ]);

        $response = new TemplateResponse('eva_ai', 'standalone', [
            'version' => 'standalone-1',
        ]);
        return $this->noCache($response);
    }

    private function appPage(string $template): TemplateResponse {
        Util::addTranslations('eva_ai');
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
            \OCP\Util::addScript('eva_ai', $main);
            \OCP\Util::addHeader('meta', [
                'name' => 'requesttoken',
                'content' => \OC::$server->get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue(),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-api',
                'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-stream',
                'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/streamChat?format=json'),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-version',
                'content' => 'shell-v2',
            ]);
        }
        $response = new TemplateResponse('eva_ai', $template, [
            'apiBase' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
        ]);
        return $this->noCache($response);
    }

    private function noCache(TemplateResponse $response): TemplateResponse {
        foreach ([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ] as $name => $value) {
            $response->addHeader($name, $value);
        }
        return $response;
    }
}