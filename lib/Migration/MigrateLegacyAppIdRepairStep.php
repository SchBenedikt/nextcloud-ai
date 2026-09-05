<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use OCP\IConfig;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Migrates configuration created while the app was named eva-ai.
 *
 * The step deliberately copies before deleting and is idempotent, so an
 * interrupted upgrade can be run again without losing settings. Database
 * tables are handled by the schema migrations; AppData/chat namespaces remain
 * readable through ChatStore's legacy-folder migration.
 */
class MigrateLegacyAppIdRepairStep implements IRepairStep {
    private const LEGACY = 'eva-ai';
    private const CURRENT = 'eva_ai';

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
    ) {
    }

    public function getName(): string {
        return 'Migrate EVA configuration from eva-ai to eva_ai';
    }

    public function run(IOutput $output): void {
        $keys = $this->config->getAppKeys(self::LEGACY);
        foreach ($keys as $key) {
            $value = $this->config->getAppValue(self::LEGACY, $key, '');
            if ($value !== '') {
                $this->config->setAppValue(self::CURRENT, $key, $value);
            }
        }

        $userCount = 0;
        $this->userManager->callForAllUsers(function ($user) use (&$userCount): void {
            $userId = (string)$user->getUID();
            foreach ($this->config->getUserKeys($userId, self::LEGACY) as $key) {
                $value = $this->config->getUserValue($userId, self::LEGACY, $key, '');
                if ($value !== '') {
                    $this->config->setUserValue($userId, self::CURRENT, $key, $value);
                }
            }
            $userCount++;
        });

        // Remove only after every value was copied. This avoids leaving a
        // second active configuration namespace behind after a successful run.
        if ($keys !== []) {
            $this->config->deleteAppValues(self::LEGACY);
        }
        if ($userCount > 0) {
            $this->config->deleteAppFromAllUsers(self::LEGACY);
        }
        $output->info('Migrated legacy EVA configuration from eva-ai to eva_ai.');
    }
}
