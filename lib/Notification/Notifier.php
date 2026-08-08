<?php

declare(strict_types=1);

namespace OCA\RagChat\Notification;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Stellt unsere Benachrichtigungen in der Nextcloud-Nachrichtenliste (Glocke) dar.
 */
class Notifier implements INotifier {
	public function getID(): string {
		return 'ragchat';
	}

	public function getName(): string {
		return 'AI – Chat';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'ragchat') {
			throw new UnknownNotificationException();
		}

		if ($notification->getSubject() === 'answer_ready') {
			$params = $notification->getSubjectParameters();
			$notification->setParsedSubject('AI answer ready');
			$notification->setParsedMessage((string)($params['text'] ?? ''));
			$notification->setIcon(\OC::$WEBROOT . '/apps/ragchat/img/ragchat-icon.svg');
			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
