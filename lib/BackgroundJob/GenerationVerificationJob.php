<?php
declare(strict_types=1);
namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Db\GenerationVerificationRepository;
use OCA\EvaAi\Vector\GenerationVerificationWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Queues local verification for enrolled SQL generations and advances bounded verification work. */
class GenerationVerificationJob extends TimedJob {
    public function __construct(ITimeFactory $time, private GenerationVerificationWorker $worker,
        private GenerationVerificationRepository $verifications, private LoggerInterface $logger) {
        parent::__construct($time);
        $this->setInterval(60);
    }
    protected function run($argument):void {
        try {
            $this->verifications->startNextEnrolledSql();
            $this->worker->run();
        }
        catch(\Throwable){$this->logger->warning('eva_ai: generation verification tick interrupted');}
    }
}
