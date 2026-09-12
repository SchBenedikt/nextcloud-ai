<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use OCA\EvaAi\BackgroundJob\IndexJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-registers the periodic indexing job so its interval matches the current
 * configuration on upgrade.
 *
 * Nextcloud stores a job's interval in the jobs table when the job is first
 * added and does not touch it afterwards, so lowering
 * `index_job_interval_minutes` on an existing install would otherwise have no
 * effect until the job row was removed by hand. Removing and re-adding the job
 * re-reads the interval from the freshly constructed job. It is safe to repeat:
 * the worst case is that the next tick runs slightly earlier.
 */
class RefreshIndexJobIntervalRepairStep implements IRepairStep {
    public function __construct(private IJobList $jobList) {
    }

    public function getName(): string {
        return 'Refresh the EVA background indexing interval';
    }

    public function run(IOutput $output): void {
        try {
            $this->jobList->remove(IndexJob::class);
            $this->jobList->add(IndexJob::class);
            $output->info('Re-registered the EVA background indexing job with the configured interval.');
        } catch (\Throwable $e) {
            // A background-job hiccup must never fail the whole upgrade.
            $output->warning('Could not refresh the EVA background indexing job: ' . $e->getMessage());
        }
    }
}
