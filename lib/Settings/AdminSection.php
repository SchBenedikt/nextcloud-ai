<?php

declare(strict_types=1);

namespace OCA\EvaAi\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * "Eva AI" entry in the Nextcloud administration settings sidebar
 * (Issue #82). Its form is rendered by {@see Admin}.
 */
class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {
    }

    public function getID(): string {
        return 'eva_ai';
    }

    public function getName(): string {
        return $this->l->t('Eva AI');
    }

    public function getPriority(): int {
        return 45;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('eva_ai', 'app.svg');
    }
}