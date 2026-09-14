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

	/** Stable bot profiles. Each profile has the same permission boundary as
	 * EVA, but gives Talk users a focused entry point for common workflows. */
	public const PROFILES = [
		['id' => 'general', 'name' => 'Eva', 'url' => self::BOT_URL, 'description' => 'Eva AI assistant for files, calendar, mail and web research.'],
		['id' => 'research', 'name' => 'Eva Research', 'url' => 'nextcloudapp://eva_ai/bot/research', 'description' => 'Eva bot focused on research, sources and concise briefings.'],
		['id' => 'calendar', 'name' => 'Eva Calendar', 'url' => 'nextcloudapp://eva_ai/bot/calendar', 'description' => 'Eva bot focused on calendars, availability and reminders.'],
		['id' => 'mail', 'name' => 'Eva Mail', 'url' => 'nextcloudapp://eva_ai/bot/mail', 'description' => 'Eva bot focused on reading and summarizing Nextcloud Mail.'],
		['id' => 'files', 'name' => 'Eva Files', 'url' => 'nextcloudapp://eva_ai/bot/files', 'description' => 'Eva bot focused on finding and explaining files.'],
	];

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
		foreach (self::PROFILES as $profile) {
			$url = $profile['url'];
			try {
				$botServerMapper->findByUrl($url);
				continue;
			} catch (DoesNotExistException) {
				// create below
			} catch (DbException $e) {
				$this->logger->warning('eva_ai: Talk-Bot lookup failed: ' . $e->getMessage(), ['exception' => $e, 'url' => $url]);
				continue;
			}
			$bot = new BotServer();
			$bot->setName($profile['name']);
			$bot->setUrl($url);
			$bot->setUrlHash(sha1($url));
			$bot->setSecret($this->random->generate(64));
			$bot->setState(Bot::STATE_ENABLED);
			$bot->setFeatures(Bot::FEATURE_RESPONSE | Bot::FEATURE_EVENT);
			$bot->setDescription($profile['description']);
			try {
				$botServerMapper->insert($bot);
			} catch (DbException $e) {
				$this->logger->warning('eva_ai: could not auto-register Talk bot: ' . $e->getMessage(), ['exception' => $e, 'url' => $url]);
			}
		}
	}
}
