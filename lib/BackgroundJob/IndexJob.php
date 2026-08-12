<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic background indexing job.
 *
 * Servers every user that has EVA index data (independent background indexing
 * per user) plus the legacy configured `index_user`, instead of only the
 * single app-global index_user (Issue #7). Each per-user pass is bounded by
 * the indexer's max_files_per_run budget, so the whole job stays bounded even
 * on multi-user instances.
 */
class IndexJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private Indexer $indexer,
        private DocumentMapper $documentMapper,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
    }

    protected function run($argument): void {
        // The scheduler lock is global; the actual progress/settings are per user.
        $this->config->setUserId(null);
        if ($this->config->get('index_job_running') === '1') {
            $started = (int)$this->config->get('index_job_started');
            if (time() - $started < 3600) {
                return;
            }
        }
        $this->config->set('index_job_running', '1');
        $this->config->set('index_job_started', (string)time());

        try {
            $users = $this->documentMapper->distinctUserIds();
            $configured = $this->config->get('index_user');
            if ($configured !== '') {
                $users[] = $configured;
            }
            $users = array_values(array_unique(array_filter($users, static fn($u) => $u !== '')));

            foreach ($users as $user) {
                // Do not compete with an explicitly requested per-user job.
                $this->config->setUserId($user);
                if ($this->config->get('index_running') === '1') {
                    continue;
                }
                $this->logger->info('eva_ai index job start', ['user' => $user]);
                try {
                    $this->indexer->run($user);
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai index job failed for user', [
                        'user' => $user,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->config->setUserId(null);
            $this->config->set('index_job_running', '0');
        }
    }
}
