<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\IndexJob;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AgentStore;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Regression coverage for Issue #112: the periodic IndexJob must not let one
 * slow user starve every other user. A bounded time budget stops the run and
 * lets the next cron tick continue, and a round-robin marker rotates the start
 * position so later users are reached fairly.
 */
final class IndexJobSchedulingTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function job(string $lastUser = ''): IndexJob {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key) use ($lastUser): string {
            return match ($key) {
                'index_job_last_user' => $lastUser,
                'index_job_max_seconds' => '50',
                default => '',
            };
        });
        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('queuedUsers')->willReturn([]);
        return new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
            $scheduler,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @param list<string> $users */
    private function rotate(array $users): array {
        $reflection = new ReflectionClass(IndexJob::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        // Bind config mock for the private rotation helper through a fresh
        // instance created with the mock collaborators.
        $job = $this->job();
        $method = new \ReflectionMethod(IndexJob::class, 'rotateFromLastProcessed');
        return $method->invoke($job, $users);
    }

    public function testNoMarkerStartsAtTheBeginning(): void {
        self::assertSame(['a', 'b', 'c'], $this->rotate(['a', 'b', 'c']));
    }

    public function testRotationContinuesAfterTheLastProcessedUser(): void {
        $job = $this->job('b');
        $reflection = new ReflectionClass(IndexJob::class);
        $method = $reflection->getMethod('rotateFromLastProcessed');
        self::assertSame(['c', 'a', 'b'], $method->invoke($job, ['a', 'b', 'c']));
    }

    public function testRotationWrapsAroundAfterTheFinalUser(): void {
        $job = $this->job('c');
        $reflection = new ReflectionClass(IndexJob::class);
        $method = $reflection->getMethod('rotateFromLastProcessed');
        self::assertSame(['a', 'b', 'c'], $method->invoke($job, ['a', 'b', 'c']));
    }

    public function testRotationIgnoresUsersThatNoLongerExist(): void {
        $job = $this->job('gone');
        $reflection = new ReflectionClass(IndexJob::class);
        $method = $reflection->getMethod('rotateFromLastProcessed');
        self::assertSame(['a', 'b', 'c'], $method->invoke($job, ['a', 'b', 'c']));
    }

    public function testStopRequestIsAcknowledgedWithoutIndexing(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'index_job_stop_requested' => '1',
                'index_job_running' => '0',
                default => '',
            };
        });
        $config->expects(self::once())->method('set')->with('index_job_stop_requested', '0');
        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->expects(self::never())->method('queuedUsers');
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects(self::never())->method('run');
        $agentStore = $this->createMock(AgentStore::class);
        $agentStore->expects(self::never())->method('purgeOlderThan');

        $job = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $indexer,
            $this->createMock(DocumentMapper::class),
            $agentStore,
            $scheduler,
            $this->createMock(LoggerInterface::class)
        );
        $method = new \ReflectionMethod(IndexJob::class, 'run');
        $method->invoke($job, null);
    }

    /**
     * A library larger than one bounded pass must be driven pass after pass
     * within the tick, instead of stopping at a single pass per cron run.
     * This is what lets several thousand files catch up in a few cron runs.
     */
    public function testLargeLibraryIsIndexedOverMultiplePassesPerTick(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'index_job_max_seconds' => '50',
                'index_job_stop_requested' => '0',
                'index_job_running' => '0',
                default => '',
            };
        });
        $config->method('hasIndexEnrollment')->willReturn(false);
        $config->method('isIndexEnrolled')->willReturn(true);

        $mapper = $this->createMock(DocumentMapper::class);
        $mapper->method('distinctUserIds')->willReturn(['alice']);

        // Every pass reports progress, so the job keeps going until its per-user
        // slice or the pass ceiling is reached.
        $indexer = $this->createMock(Indexer::class);
        $passes = 0;
        $indexer->method('run')->willReturnCallback(
            static function () use (&$passes): array {
                $passes++;
                return ['processed' => 5, 'error' => null];
            }
        );

        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('queuedUsers')->willReturn([]);

        $job = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $indexer,
            $mapper,
            $this->createMock(AgentStore::class),
            $scheduler,
            $this->createMock(LoggerInterface::class)
        );

        $method = new \ReflectionMethod(IndexJob::class, 'run');
        $method->invoke($job, null);

        self::assertGreaterThan(1, $passes, 'a large library must be indexed over multiple passes');
    }

    /** A pass that changes nothing means the scope is indexed; stop immediately. */
    public function testNoProgressPassStopsAfterOneCall(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'index_job_max_seconds' => '50',
                default => '',
            };
        });
        $config->method('hasIndexEnrollment')->willReturn(false);
        $config->method('isIndexEnrolled')->willReturn(true);

        $mapper = $this->createMock(DocumentMapper::class);
        $mapper->method('distinctUserIds')->willReturn(['alice']);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects(self::once())->method('run')->willReturn(['processed' => 0, 'error' => null]);

        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('queuedUsers')->willReturn([]);

        $job = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $indexer,
            $mapper,
            $this->createMock(AgentStore::class),
            $scheduler,
            $this->createMock(LoggerInterface::class)
        );

        $method = new \ReflectionMethod(IndexJob::class, 'run');
        $method->invoke($job, null);
    }

    public function testBudgetFloorAndCeilingAreBounded(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return $key === 'index_job_max_seconds' ? '120' : '';
        });
        $scheduler = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $scheduler->method('queuedUsers')->willReturn([]);
        $job = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
            $scheduler,
            $this->createMock(LoggerInterface::class)
        );
        $method = new \ReflectionMethod(IndexJob::class, 'budgetSeconds');
        self::assertSame(120, $method->invoke($job));

        $configSmall = $this->createMock(AppConfig::class);
        $configSmall->method('get')->willReturnCallback(static function (string $key): string {
            return $key === 'index_job_max_seconds' ? '1' : '';
        });
        $schedulerSmall = $this->createMock(\OCA\EvaAi\Service\IndexScheduler::class);
        $schedulerSmall->method('queuedUsers')->willReturn([]);
        $jobSmall = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $configSmall,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
            $schedulerSmall,
            $this->createMock(LoggerInterface::class)
        );
        // Below the floor the default is used so a tiny misconfiguration cannot
        // cause a thundering-herd of runs.
        self::assertSame(50, $method->invoke($jobSmall));
    }
}
