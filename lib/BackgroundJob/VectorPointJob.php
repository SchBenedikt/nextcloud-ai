<?php
declare(strict_types=1);
namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Vector\VectorPointWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** No transport until instance-wide point consent and connection flags permit it. */
class VectorPointJob extends TimedJob {
    public function __construct(ITimeFactory $time, private VectorPointWorker $worker, private LoggerInterface $logger) {
        parent::__construct($time); $this->setInterval(60);
    }
    protected function run($argument): void {
        try { $this->worker->run(); }
        catch (\Throwable) {
            try { $this->logger->warning('eva_ai: vector point job interrupted'); } catch (\Throwable) {}
        }
    }
}
