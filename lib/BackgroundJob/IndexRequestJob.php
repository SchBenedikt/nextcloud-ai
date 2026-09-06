<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs an explicitly requested index in cron/queue, independent of the browser.
 * The indexer uses bounded passes; this job repeats them until the user's
 * configured scope is up to date or the user requests cancellation.
 */
class IndexRequestJob extends QueuedJob {
    // Keep one queue execution bounded. A follow-up job is queued when the
    // pass budget is exhausted, so very large libraries can make progress
    // without monopolising a worker indefinitely.
    private const MAX_PASSES = 1;

    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private Indexer $indexer,
        private LoggerInterface $logger,
        private IJobList $jobList
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $userId = is_array($argument) ? trim((string)($argument['userId'] ?? '')) : '';
        $mode = is_array($argument) ? (string)($argument['mode'] ?? 'all') : 'all';
        $runId = is_array($argument) ? (string)($argument['runId'] ?? '') : '';
        $waitForCancellation = is_array($argument) && !empty($argument['waitForCancellation']);
        if ($userId === '' || (!$waitForCancellation && $runId === '') || !in_array($mode, ['all', 'files', 'mail'], true)) {
            return;
        }

        $this->config->setUserId($userId);
        if ($waitForCancellation) {
            // The old worker owns the per-user lock while it observes the stop
            // request. Wait in the queued job instead of returning a spurious
            // lock error, then claim a fresh run after the old state is gone.
            for ($attempt = 0; $attempt < 60 && $this->config->get('index_running') === '1'; $attempt++) {
                sleep(1);
            }
            if ($this->config->get('index_running') === '1' || $this->config->get('index_cancel_requested') === '1') {
                $this->config->setUserId(null);
                return;
            }
            $runId = bin2hex(random_bytes(16));
            if (!$this->config->tryClaimIndex($userId)) {
                $this->config->setUserId(null);
                return;
            }
            $this->config->setUserId($userId);
            $this->config->set('index_started', (string)time());
            $this->config->set('index_heartbeat', (string)time());
            $this->config->set('index_finished', '0');
            $this->config->set('last_index_error', '');
            $this->config->set('index_mode', $mode);
            $this->config->set('index_cancel_requested', '0');
            $this->config->set('index_run_id', $runId);
        }
        $queuedContinuation = false;
        $passes = 0;
        $lastResult = null;
        $ownsRun = $this->config->get('index_run_id') === $runId;
        try {
            if (!$ownsRun) {
                return;
            }
            // A stop request can arrive while this job is still queued. Do not
            // clear that request here; the stop endpoint must win the race.
            if ($this->cancelRequested($runId)) {
                return;
            }

            $this->config->set('index_running', '1');
            $this->config->set('index_mode', $mode);
            do {
                if ($this->cancelRequested($runId)) {
                    break;
                }
                $lastResult = $this->indexer->run($userId, null, $mode, true, $runId);
                $passes++;

                // A bounded pass that changed nothing means the complete
                // current scope is indexed. Errors must not be retried in a
                // tight loop while Ollama or Mail is unavailable.
                if (($lastResult['error'] ?? null) !== null || (int)($lastResult['processed'] ?? 0) === 0) {
                    break;
                }
            } while ($passes < self::MAX_PASSES);

            if ($passes >= self::MAX_PASSES && ($lastResult['error'] ?? null) === null
                && (int)($lastResult['processed'] ?? 0) > 0 && !$this->cancelRequested($runId)) {
                // Do not report a successful continuation as an error. Queue
                // the same run again; the run id preserves cancellation and
                // stale-job protection across the hand-off.
                $this->config->set('index_running', '1');
                $this->config->set('index_mode', $mode);
                $this->jobList->add(self::class, [
                    'userId' => $userId,
                    'mode' => $mode,
                    'runId' => $runId,
                    'generation' => (int)($argument['generation'] ?? 0) + 1,
                ]);
                $queuedContinuation = true;
                $this->logger->info('eva_ai: queued follow-up index pass batch', [
                    'userId' => $userId,
                    'mode' => $mode,
                ]);
            }
        } catch (\Throwable $e) {
            $this->config->set('last_index_error', $e->getMessage());
            $this->logger->error('eva_ai: requested background index failed', [
                'userId' => $userId,
                'mode' => $mode,
                'exception' => $e,
            ]);
        } finally {
            // A stale job must never clear a newer run's state.
            if (!$queuedContinuation && $this->config->get('index_run_id') === $runId) {
                $this->config->set('index_running', '0');
                $this->config->set('index_finished', (string)time());
                $this->config->set('index_mode', 'idle');
                $this->config->set('index_cancel_requested', '0');
                $this->config->set('index_heartbeat', '');
            }
            $this->config->setUserId(null);
        }
    }

    private function cancelRequested(string $runId): bool {
        return $this->config->get('index_cancel_requested') === '1'
            || $this->config->get('index_run_id') !== $runId;
    }
}
