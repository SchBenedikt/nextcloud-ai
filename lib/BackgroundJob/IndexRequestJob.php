<?php

declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs an explicitly requested index in cron/queue, independent of the browser.
 * The indexer uses bounded passes; this job repeats them until the user's
 * configured scope is up to date or the user requests cancellation.
 */
class IndexRequestJob extends QueuedJob {
    private const MAX_PASSES = 1000;

    public function __construct(
        ITimeFactory $time,
        private AppConfig $config,
        private Indexer $indexer,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $userId = is_array($argument) ? trim((string)($argument['userId'] ?? '')) : '';
        $mode = is_array($argument) ? (string)($argument['mode'] ?? 'all') : 'all';
        $runId = is_array($argument) ? (string)($argument['runId'] ?? '') : '';
        if ($userId === '' || $runId === '' || !in_array($mode, ['all', 'files', 'mail'], true)) {
            return;
        }

        $this->config->setUserId($userId);
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

            if ($passes >= self::MAX_PASSES) {
                $this->config->set('last_index_error', 'Indexing stopped after the safety pass limit.');
                $this->logger->warning('eva_ai: background index pass limit reached', [
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
            if ($this->config->get('index_run_id') === $runId) {
                $this->config->set('index_running', '0');
                $this->config->set('index_finished', (string)time());
                $this->config->set('index_mode', 'idle');
                $this->config->set('index_cancel_requested', '0');
            }
            $this->config->setUserId(null);
        }
    }

    private function cancelRequested(string $runId): bool {
        return $this->config->get('index_cancel_requested') === '1'
            || $this->config->get('index_run_id') !== $runId;
    }
}
