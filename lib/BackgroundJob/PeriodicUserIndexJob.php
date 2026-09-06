<?php

declare(strict_types=1);
namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class PeriodicUserIndexJob extends QueuedJob {
    public function __construct(ITimeFactory $time, private AppConfig $config, private Indexer $indexer, private LoggerInterface $logger) { parent::__construct($time); }
    protected function run($argument): void {
        $user = (string)($argument['userId'] ?? '');
        if ($user === '' || !$this->config->isIndexEnrolled($user)) { return; }
        try { $this->indexer->run($user, 10); }
        catch (\Throwable $e) { $this->logger->warning('EVA periodic user pass failed', ['exception' => $e]); }
        finally { $this->config->setUserId(null); }
    }
}
