<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\{BackgroundChatJob, ChatCleanupJob, IndexJob, JobRegistry, ProactiveBriefingJob};
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
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects(self::exactly(4))->method('has')->willReturnCallback(
            static function (string $class, mixed $argument) use (&$scheduled): bool { return $scheduled[$class] ?? false; }
        );
        $jobs->expects(self::exactly(3))->method('add')->willReturnCallback(
            static function (string $class) use (&$scheduled): void { $scheduled[$class] = true; }
        );

        JobRegistry::ensureScheduled($jobs);

        self::assertSame([
            IndexJob::class => true,
            ChatCleanupJob::class => true,
            ProactiveBriefingJob::class => true,
            BackgroundChatJob::class => true,
        ], $scheduled);
        self::assertSame(JobRegistry::JOBS, [IndexJob::class, ChatCleanupJob::class, ProactiveBriefingJob::class, BackgroundChatJob::class]);
    }
}
