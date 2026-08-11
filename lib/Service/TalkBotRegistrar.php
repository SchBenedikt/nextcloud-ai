<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Model\BotServerMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Stellt sicher, dass der Eva-AI-Bot in Nextcloud Talk (talk_bots_server)
 * registriert ist. NC hat keinen "AppInstalled"-Hook fuer optionale
 * Dependencies, daher registrieren wir beim Boot, falls spreed aktiv und
 * der Bot noch nicht existiert. Idempotent.
 */
class TalkBotRegistrar {
	public const BOT_URL = 'nextcloudapp://eva_ai/bot';

	public function __construct(
		private IAppManager $appManager,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Wird beim App-Boot aufgerufen. Wenn spreed nicht installiert ist,
	 * passiert nichts. Wenn der Bot schon existiert, nichts. Sonst wird
	 * er angelegt.
	 */
	public function ensureRegistered(): void {
		if (!$this->appManager->isEnabledForAnyone('spreed')) {
			return;
		}
		// Talk is optional; resolve the mapper lazily so that the app can boot
		// even when Talk is not installed or enabled.
		$botServerMapper = \OCP\Server::get(BotServerMapper::class);
		try {
			$botServerMapper->findByUrl(self::BOT_URL);
			return; // bereits registriert
		} catch (DoesNotExistException) {
			// neu anlegen
		} catch (DbException $e) {
			$this->logger->warning('eva_ai: Talk-Bot lookup failed: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return;
		}
		$bot = new BotServer();
		$bot->setName('Eva');
		$bot->setUrl(self::BOT_URL);
		$bot->setUrlHash(sha1(self::BOT_URL));
		$bot->setSecret($this->random->generate(64));
		$bot->setState(Bot::STATE_ENABLED);
		// FEATURE_EVENT: Talk dispatcht BotInvokeEvent an unseren Listener.
		// FEATURE_RESPONSE: Bot darf auch via /ocs/.../bot/{token}/message antworten.
		$bot->setFeatures(Bot::FEATURE_RESPONSE | Bot::FEATURE_EVENT);
		$bot->setDescription('Eva AI assistant: chat with your indexed files.');
		try {
			$botServerMapper->insert($bot);
		} catch (DbException $e) {
			$this->logger->warning('eva_ai: could not auto-register Talk bot: ' . $e->getMessage(), [
				'exception' => $e,
			]);
		}
	}
}
