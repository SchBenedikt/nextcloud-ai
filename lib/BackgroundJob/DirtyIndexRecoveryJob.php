<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\DirtyIndexStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Re-schedule due work and reclaim incremental-index leases after worker crashes. */
class DirtyIndexRecoveryJob extends TimedJob {
    private const RECOVERY_INTERVAL_SECONDS = 300;
    private const USER_BATCH = 100;

    public function __construct(
        ITimeFactory $time,
        private DirtyIndexStore $dirty,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::RECOVERY_INTERVAL_SECONDS);
    }

    protected function run($argument): void {
        $users = $this->dirty->dueUserIds(self::USER_BATCH);
        foreach ($users as $userId) {
            $this->jobList->scheduleAfter(ReindexFileJob::class, time() + 1, ['userId' => $userId]);
        }
        if ($users !== []) {
            $this->logger->debug('eva_ai scheduled recovery for due incremental file-index queues', ['users' => count($users)]);
        }
    }
}
