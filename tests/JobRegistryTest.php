<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\{AccountCleanupJob, BackgroundChatJob, ChatCleanupJob, ChatStorageMigrationJob, DirtyIndexRecoveryJob, GenerationBuildJob, GenerationVectorCompactionJob, GenerationVerificationJob, IndexJob, JobRegistry, LexicalIndexBackfillJob, ProactiveBriefingJob, RateLimitCleanupJob, UsageMetricsRetentionJob, VectorErasureJob, VectorNormBackfillJob, VectorPointJob};
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class JobRegistryTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testEnsuresEveryRecurringJobWithoutReplacingExistingJobs(): void {
        $scheduled = [IndexJob::class => true];
        $added = [];
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects(self::exactly(count(JobRegistry::JOBS)))->method('has')->willReturnCallback(
            static function (string $class) use (&$scheduled): bool { return $scheduled[$class] ?? false; }
        );
        $jobs->expects(self::exactly(count(JobRegistry::JOBS) - 1))->method('add')->willReturnCallback(
            static function (string $class) use (&$scheduled, &$added): void {
                $scheduled[$class] = true;
                $added[] = $class;
            }
        );

        JobRegistry::ensureScheduled($jobs);

        // Every recurring job stays registered, and an already-scheduled job is
        // not added a second time.
        self::assertSame([
            IndexJob::class => true,
            ChatCleanupJob::class => true,
            ChatStorageMigrationJob::class => true,
            ProactiveBriefingJob::class => true,
            BackgroundChatJob::class => true,
            VectorErasureJob::class => true,
            VectorPointJob::class => true,
            GenerationBuildJob::class => true,
            GenerationVerificationJob::class => true,
            AccountCleanupJob::class => true,
            VectorNormBackfillJob::class => true,
            LexicalIndexBackfillJob::class => true,
            GenerationVectorCompactionJob::class => true,
            UsageMetricsRetentionJob::class => true,
            RateLimitCleanupJob::class => true,
            DirtyIndexRecoveryJob::class => true,
        ], $scheduled);
        self::assertNotContains(IndexJob::class, $added, 'an existing job must not be scheduled twice');
        self::assertSame(count(JobRegistry::JOBS) - 1, count($added));
    }
}
