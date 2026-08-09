<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class IndexJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private Indexer $indexer,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
    }

    protected function run($argument): void {
        $user = $this->config->get('index_user');
        if ($user === '') {
            return;
        }
        // Avoid overlapping runs.
        if ($this->config->get('index_running') === '1') {
            $started = (int)$this->config->get('index_started');
            if (time() - $started < 3600) {
                return;
            }
        }
        $this->logger->info('eva-ai index job start', ['user' => $user]);
        $this->indexer->run($user);
    }
}