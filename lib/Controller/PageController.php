<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

use OCA\EvaAi\Service\RagService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\ContentSecurityPolicy;
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
        $jsDir = $this->appManager->getAppPath('eva_ai') . '/js';
        $standalone = null;
        $candidates = [];
        foreach (glob($jsDir . '/eva_ai_standalone*.js') ?: [] as $file) {
            $base = basename($file);
            if ($base !== 'eva_ai_standalone.js' || str_ends_with($base, '.map')) {
                continue;
            }
            $candidates[$file] = filemtime($file);
        }
        if ($candidates !== []) {
            arsort($candidates);
            $standalone = basename((string)array_key_first($candidates), '.js');
        }
        if ($standalone !== null) {
            \OCP\Util::addScript('eva_ai', $standalone);
        }
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
        $this->allowWebImages($response);
        return $this->noCache($response);
    }

    private function appPage(string $template): TemplateResponse {
        Util::addTranslations('eva_ai');
        $jsDir = $this->appManager->getAppPath('eva_ai') . '/js';
        $main = null;
        $candidates = [];
        foreach (glob($jsDir . '/eva_ai-main*.js') ?: [] as $file) {
            $base = basename($file);
            // Only the webpack main bundle qualifies; leftover artifacts from
            // older build configurations (e.g. eva_ai-main-groq.*.js) must
            // never be picked as the app bundle.
            if ($base !== 'eva_ai-main.js'
                || str_ends_with($base, '.map')) {
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
        $this->allowWebImages($response);
        return $this->noCache($response);
    }

    /**
     * Allow the pictures a web answer embeds to actually load.
     *
     * Nextcloud's own policy for images is `img-src 'self' data: blob:` (plus
     * map tiles), which blocks every remote picture. An answer that shows a
     * product photo or a photo of an event therefore rendered as a broken image
     * and - since the chat degrades a broken image to a link on purpose - the
     * user saw a link instead of the picture they asked for.
     *
     * The relaxation is deliberately scoped to this app's own pages and to
     * images, and the pictures themselves are already constrained on the way in:
     * they are only ever inserted as a markdown image the answer chose, they
     * carry `referrerpolicy="no-referrer"` so the host learns nothing about the
     * reader, and they load lazily, so an image the user never scrolls to is
     * never fetched.
     */
    private function allowWebImages(TemplateResponse $response): void {
        $policy = new ContentSecurityPolicy();
        $policy->addAllowedImageDomain('*');
        $response->setContentSecurityPolicy($policy);
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