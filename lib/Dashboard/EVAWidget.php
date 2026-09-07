<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * EVA AI dashboard widget — quick-chat entry point on the Nextcloud Dashboard.
 */
class EVAWidget implements IWidget {
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator
    ) {
    }

    public function getId(): string {
        return 'eva_ai';
    }

    public function getTitle(): string {
        return $this->l10n->t('EVA AI');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-eva-ai';
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('eva_ai.page#index');
    }

    public function load(): void {
        \OCP\Util::addStyle('eva_ai', 'widget');
    }
}
