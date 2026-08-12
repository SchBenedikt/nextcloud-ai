<?php

declare(strict_types=1);

namespace OCA\EvaAi\Notification;

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
	) {
	}

	public function getID(): string {
		return 'eva_ai';
	}

	public function getName(): string {
		return 'EVA – Chat';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'eva_ai') {
			throw new UnknownNotificationException();
		}

		if ($notification->getSubject() === 'answer_ready') {
			$params = $notification->getSubjectParameters();
			$notification->setParsedSubject('EVA answer ready');
			$notification->setParsedMessage((string)($params['text'] ?? ''));
			$notification->setIcon($this->urlGenerator->imagePath('eva_ai', 'app.svg'));
			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
