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
        return new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
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

    public function testBudgetFloorAndCeilingAreBounded(): void {
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return $key === 'index_job_max_seconds' ? '120' : '';
        });
        $job = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $config,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
            $this->createMock(LoggerInterface::class)
        );
        $method = new \ReflectionMethod(IndexJob::class, 'budgetSeconds');
        self::assertSame(120, $method->invoke($job));

        $configSmall = $this->createMock(AppConfig::class);
        $configSmall->method('get')->willReturnCallback(static function (string $key): string {
            return $key === 'index_job_max_seconds' ? '1' : '';
        });
        $jobSmall = new IndexJob(
            $this->createMock(ITimeFactory::class),
            $configSmall,
            $this->createMock(Indexer::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(AgentStore::class),
            $this->createMock(LoggerInterface::class)
        );
        // Below the floor the default is used so a tiny misconfiguration cannot
        // cause a thundering-herd of runs.
        self::assertSame(50, $method->invoke($jobSmall));
    }
}
