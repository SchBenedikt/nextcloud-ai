<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Vector\VectorErasureWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Disabled by default through fresh instance flags checked by the worker. */
class VectorErasureJob extends TimedJob {
    public function __construct(ITimeFactory $time, private VectorErasureWorker $worker, private LoggerInterface $logger) {
        parent::__construct($time); $this->setInterval(60);
    }
    protected function run($argument): void {
        try { $this->worker->run(); }
        catch (\Throwable) {
            // The lease can be recovered after a DB/process failure. Avoid
            // logging exception details that may contain event/target data.
            try { $this->logger->warning('eva_ai: vector erasure job interrupted'); } catch (\Throwable) {}
        }
    }
}
