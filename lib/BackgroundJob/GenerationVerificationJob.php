<?php
declare(strict_types=1);
namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Vector\GenerationVerificationWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Inert until an administrator starts verification for a staged generation. */
class GenerationVerificationJob extends TimedJob {
    public function __construct(ITimeFactory$time,private GenerationVerificationWorker$worker,private LoggerInterface$logger){parent::__construct($time);$this->setInterval(60);}
    protected function run($argument):void {
        try{$this->worker->run();}
        catch(\Throwable){$this->logger->warning('eva_ai: generation verification tick interrupted');}
    }
}
