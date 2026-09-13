<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use OCA\EvaAi\BackgroundJob\BackgroundChatJob;
use OCA\EvaAi\BackgroundJob\ChatCleanupJob;
use OCA\EvaAi\BackgroundJob\IndexJob;
use OCA\EvaAi\BackgroundJob\ProactiveBriefingJob;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IDBConnection;

/**
 * Starts EVA from a clean runtime state after an app upgrade.
 *
 * Durable EVA queues and agent snapshots belong to the previous code version;
 * retaining them can execute stale tool plans after deployment. Remove EVA's
 * queued jobs and runtime state, then re-register the periodic jobs. This is
 * intentionally limited to EVA-owned data and is idempotent.
 */
class ResetEvaJobsOnUpgradeRepairStep implements IRepairStep {
    private const APP = 'eva_ai';
    private const USER_STATE = [
        'index_running', 'index_started', 'index_heartbeat', 'index_finished',
        'last_index_processed', 'last_index_total', 'last_index_error',
        'last_index_cache_hits', 'last_index_cache_misses', 'last_index_ollama_requests',
        'last_index_failed', 'index_config_hash', 'index_mode', 'index_cancel_requested',
        'index_run_id', 'index_enrolled', 'knowledge_initialized', 'proactive_schedule_runs',
        'search_revision', 'background_chat_queue', 'learned_app_apis', 'learned_file_locations',
    ];

    /** @var list<class-string> */
    private const JOBS = [IndexJob::class, ChatCleanupJob::class, ProactiveBriefingJob::class, BackgroundChatJob::class];

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private IJobList $jobList,
        private IDBConnection $db,
    ) {}

    public function getName(): string {
        return 'Reset EVA jobs and runtime state after upgrade';
    }

    public function run(IOutput $output): void {
        $this->config->setAppValue(self::APP, 'background_chat_queue', '[]');
        $this->config->setAppValue(self::APP, 'background_chat_history', '[]');
        $this->config->setAppValue(self::APP, 'background_chat_users', '[]');
        $this->config->setAppValue(self::APP, 'index_scheduler_queue', '[]');
        $this->config->setAppValue(self::APP, 'index_scheduler_active', '{}');
        $this->config->setAppValue(self::APP, 'index_job_running', '0');
        $this->config->setAppValue(self::APP, 'index_job_stop_requested', '1');

        $users = 0;
        $this->userManager->callForAllUsers(function ($user) use (&$users): void {
            $uid = (string)$user->getUID();
            foreach (self::USER_STATE as $key) {
                try { $this->config->deleteUserValue($uid, self::APP, $key); } catch (\Throwable) { /* best effort */ }
            }
            // Leave a durable stop marker for a worker that is already inside
            // a model/tool loop; it will observe this between steps and exit.
            $this->config->setUserValue($uid, self::APP, 'index_cancel_requested', '1');
            $users++;
        });

        try {
            $this->db->executeStatement('DELETE FROM *PREFIX*eva_ai_agent_state');
            // A requested clean restart also removes EVA's derived index; the
            // user's original Nextcloud files remain untouched.
            $this->db->executeStatement('DELETE FROM *PREFIX*eva_ai_chunks');
            $this->db->executeStatement('DELETE FROM *PREFIX*eva_ai_documents');
        } catch (\Throwable) {
            // Table may not exist on an interrupted first install.
        }

        foreach (self::JOBS as $job) {
            try { $this->jobList->remove($job); $this->jobList->add($job); } catch (\Throwable) { /* best effort */ }
        }
        $output->info('Reset EVA queues, agent state and ' . count(self::JOBS) . ' background jobs for ' . $users . ' users.');
    }
}
