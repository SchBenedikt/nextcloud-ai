<?php
declare(strict_types=1);

namespace OCA\EvaAi\BackgroundJob;

use OCP\BackgroundJob\IJobList;

/** Keeps recurring EVA jobs registered across fresh installs and upgrades. */
final class JobRegistry {
    /** @var list<class-string> */
    public const JOBS = [
        IndexJob::class,
        ChatCleanupJob::class,
        ProactiveBriefingJob::class,
        BackgroundChatJob::class,
    ];

    public static function ensureScheduled(IJobList $jobs): void {
        foreach (self::JOBS as $job) {
            try {
                if (!$jobs->has($job, null)) {
                    $jobs->add($job);
                }
            } catch (\Throwable) {
                // A single unavailable job must not prevent the app booting.
            }
        }
    }
}
