<?php
declare(strict_types=1);
namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Vector\GenerationBuildWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Dormant unless an administrator has explicitly started a build and enabled points. */
class GenerationBuildJob extends TimedJob {
    public function __construct(ITimeFactory $time,private GenerationBuildWorker $worker,private LoggerInterface $logger) {
        parent::__construct($time);$this->setInterval(60);
    }
    protected function run($argument): void {
        try {$this->worker->run();}
        catch(\Throwable){$this->logger->warning('eva_ai: generation build tick interrupted');}
    }
}
