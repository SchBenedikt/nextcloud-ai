<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\ChatStorageMigration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Incrementally imports existing histories and enables verified per-user SQL reads. */
final class ChatStorageMigrationJob extends TimedJob {
	private const APP_ID = 'eva_ai';
	private const CURSOR_KEY = 'chat_sql_migration_offset';
	private const USERS_PER_RUN = 2;
	private const BATCHES_PER_USER = 2;

	public function __construct(
		ITimeFactory $time,
		private ChatStorageMigration $migration,
		private IUserManager $users,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		$offset = max(0, (int)$this->config->getAppValue(self::APP_ID, self::CURSOR_KEY, '0'));
		$accounts = $this->users->search('', self::USERS_PER_RUN, $offset);
		if ($accounts === []) {
			$this->config->setAppValue(self::APP_ID, self::CURSOR_KEY, '0');
			return;
		}

		foreach ($accounts as $account) {
			$userId = $account->getUID();
			if ($userId === '') {
				$offset++;
				continue;
			}
			try {
				$result = $this->migration->migrate($userId, self::BATCHES_PER_USER);
				if ($result['status'] === 'in-progress') {
					// Keep the same user at the head of the cursor until its
					// checkpointed import reaches verification.
					$this->config->setAppValue(self::APP_ID, self::CURSOR_KEY, (string)$offset);
					return;
				}
				if (in_array($result['status'], ['complete', 'already-current'], true)) {
					$this->migration->enableSqlReads($userId, $result['status'] === 'complete');
				}
			} catch (\Throwable $e) {
				$this->logger->warning('eva_ai: automatic chat SQL migration deferred for an account', [
					'exception' => $e::class,
				]);
			}
			$offset++;
		}

		$this->config->setAppValue(self::APP_ID, self::CURSOR_KEY, (string)$offset);
	}
}
