<?php

declare(strict_types=1);

namespace OCA\EvaAi\Notification;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Stellt unsere Benachrichtigungen in der Nextcloud-Nachrichtenliste (Glocke) dar.
 */
class Notifier implements INotifier {
	public function getID(): string {
		return 'eva-ai';
	}

	public function getName(): string {
		return 'EVA – Chat';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'eva-ai') {
			throw new UnknownNotificationException();
		}

		if ($notification->getSubject() === 'answer_ready') {
			$params = $notification->getSubjectParameters();
			$notification->setParsedSubject('EVA answer ready');
			$notification->setParsedMessage((string)($params['text'] ?? ''));
			$notification->setIcon(\OC::$WEBROOT . '/apps/eva-ai/img/eva-icon.svg');
			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
