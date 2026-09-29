<?php

declare(strict_types=1);

namespace OCA\EvaAi\Notification;

use OCA\EvaAi\Service\AppConfig;
use OCP\L10N\IFactory;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Stellt unsere Benachrichtigungen in der Nextcloud-Nachrichtenliste (Glocke) dar.
 */
class Notifier implements INotifier {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return 'eva_ai';
	}

	public function getName(): string {
		return $this->l10nFactory->get(AppConfig::APP)->t('EVA – Chat');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'eva_ai') {
			throw new UnknownNotificationException();
		}

		if (in_array($notification->getSubject(), ['answer_ready', 'scheduled_briefing', 'background_failed'], true)) {
			$l10n = $this->l10nFactory->get(AppConfig::APP, $languageCode);
			$params = $notification->getSubjectParameters();
			$subject = $notification->getSubject();
			$subjectKey = match ($subject) {
				'scheduled_briefing' => 'EVA scheduled briefing',
				'background_failed' => 'EVA task failed',
				default => 'EVA answer ready',
			};
			$notification->setParsedSubject($l10n->t($subjectKey));
			$notification->setParsedMessage((string)($params['text'] ?? ''));
			// NC >= 30 verlangt absolute URLs fuer das Icon; relative Pfade
			// werfen InvalidValueException (subklasse von \InvalidArgumentException)
			// und lassen die Benachrichtigung mit Log-Spam scheitern.
			$notification->setIcon($this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath('eva_ai', 'app.svg')
			));
			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
