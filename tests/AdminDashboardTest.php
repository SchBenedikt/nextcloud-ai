<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\AdminController;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Issue #82: the admin dashboard must only be reachable by administrators,
 * expose per-user index metadata, and offer re-index/reset/enrollment
 * management — without ever leaking personal file content.
 */
final class AdminDashboardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testAllEndpointsAreAdminRequired(): void {
        $reflection = new ReflectionClass(AdminController::class);
        foreach (['overview', 'reindex', 'reset', 'setEnrollment', 'stopBackgroundIndex'] as $method) {
            $m = $reflection->getMethod($method);
            $attributes = $m->getAttributes(AdminRequired::class);
            self::assertNotEmpty(
                $attributes,
                "AdminController::$method must be #[AdminRequired]"
            );
        }
    }

    public function testOverviewContainsMetadataOnly(): void {
        $controller = $this->controller();
        $mapper = $this->createMock(DocumentMapper::class);
        $mapper->method('aggregatePerUser')->willReturn([
            ['user_id' => 'alice', 'documents' => 12, 'chunks' => 340, 'last_indexed_at' => 1700000000],
            ['user_id' => 'bob', 'documents' => 3, 'chunks' => 40, 'last_indexed_at' => null],
        ]);
        $controller = $this->controller(mapper: $mapper);

        $response = $controller->overview();
        $data = $response->getData();

        self::assertArrayHasKey('users', $data);
        self::assertArrayHasKey('scheduler', $data);
        self::assertArrayHasKey('ollama', $data);
        self::assertCount(2, $data['users']);

        $alice = $data['users'][0];
        self::assertSame('alice', $alice['userId']);
        self::assertSame(12, $alice['documents']);
        self::assertSame(340, $alice['chunks']);
        self::assertSame(1700000000, $alice['lastIndexedAt']);
        // Metadata only: never file paths, names or content.
        self::assertArrayNotHasKey('files', $alice);
        self::assertArrayNotHasKey('content', $alice);
        self::assertArrayNotHasKey('path', $alice);
    }

    public function testOverviewIncludesEnrolledUsersWithoutDocuments(): void {
        $mapper = $this->createMock(DocumentMapper::class);
        $mapper->method('aggregatePerUser')->willReturn([]);
        $config = $this->createMock(AppConfig::class);
        $config->method('enrolledUserIds')->willReturn(['carol']);
        $config->method('get')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'index_enrolled' => '1',
                'index_running' => '0',
                'index_mode' => 'idle',
                'last_index_error' => '',
                'actions_enabled' => '1',
                'mail_index_enabled' => '1',
                default => '',
            };
        });

        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Carol');
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('carol')->willReturn($user);

        $controller = $this->controller(mapper: $mapper, config: $config, userManager: $userManager);
        $data = $controller->overview()->getData();

        self::assertCount(1, $data['users']);
        self::assertSame('carol', $data['users'][0]['userId']);
        self::assertSame('Carol', $data['users'][0]['displayName']);
        self::assertSame(0, $data['users'][0]['documents']);
        self::assertTrue($data['users'][0]['enrolled']);
    }

    public function testReindexQueuesJobAndEnrolls(): void {
        $user = $this->createMock(IUser::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['index_running', '0'],
        ]);

        $jobList = $this->createMock(IJobList::class);
        $jobList->expects(self::once())->method('add')
            ->with(\OCA\EvaAi\BackgroundJob\IndexRequestJob::class, self::callback(
                static fn(array $arg): bool => ($arg['userId'] ?? '') === 'alice' && ($arg['mode'] ?? '') === 'all'
            ));
        $config->expects(self::once())->method('setIndexEnrolled')->with('alice', true);

        $controller = $this->controller(config: $config, userManager: $userManager, jobList: $jobList);
        $response = $controller->reindex('alice');
        self::assertTrue($response->getData()['queued']);
        self::assertSame('alice', $response->getData()['userId']);
    }

    public function testReindexUnknownUserReturns404(): void {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn(null);
        $controller = $this->controller(userManager: $userManager);
        $response = $controller->reindex('ghost');
        self::assertSame(404, $response->getStatus());
    }

    public function testReindexRunningUserReturns409(): void {
        $user = $this->createMock(IUser::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['index_running', '1'],
        ]);

        $controller = $this->controller(config: $config, userManager: $userManager);
        $response = $controller->reindex('alice');
        self::assertSame(409, $response->getStatus());
    }

    public function testResetDeletesUserIndex(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Alice');
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['index_running', '0'],
            ['index_enrolled', '0'],
            ['index_mode', 'idle'],
            ['last_index_error', ''],
            ['actions_enabled', '1'],
            ['mail_index_enabled', '1'],
        ]);
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects(self::once())->method('reset')->with('alice')->willReturn(['documents' => 5, 'chunks' => 100]);

        $controller = $this->controller(config: $config, indexer: $indexer, userManager: $userManager);
        $response = $controller->reset('alice');
        $data = $response->getData();
        self::assertSame(['documents' => 5, 'chunks' => 100], $data['result']);
        self::assertSame('alice', $data['userId']);
    }

    public function testSetEnrollmentTogglesFromQueryParam(): void {
        $user = $this->createMock(IUser::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $key === 'enabled' ? 'false' : $default
        );

        $config = $this->createMock(AppConfig::class);
        $config->expects(self::once())->method('setIndexEnrolled')->with('alice', false);
        $config->method('isIndexEnrolled')->willReturn(false);

        $controller = $this->controller(request: $request, config: $config, userManager: $userManager);
        $response = $controller->setEnrollment('alice');

        $data = $response->getData();
        self::assertFalse($data['enrolled']);
    }

    public function testSetEnrollmentEnablesViaTrueParam(): void {
        $user = $this->createMock(IUser::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $key === 'enabled' ? 'true' : $default
        );

        $config = $this->createMock(AppConfig::class);
        $config->expects(self::once())->method('setIndexEnrolled')->with('alice', true);
        $config->method('isIndexEnrolled')->willReturn(true);

        $controller = $this->controller(request: $request, config: $config, userManager: $userManager);
        $data = $controller->setEnrollment('alice')->getData();
        self::assertTrue($data['enrolled']);
    }

    public function testStopBackgroundIndexFlagsAndCancelsActivePasses(): void {
        $scheduler = $this->createMock(IndexScheduler::class);
        $scheduler->method('activeUsers')->willReturn(['alice', 'bob']);
        $scheduler->method('overview')->willReturn(['running' => 0, 'limit' => 2, 'queued' => 0]);

        // setUserId switches the scope; index_job_stop_requested is stored at
        // app scope (null) and each active user gets index_cancel_requested.
        $config = $this->createMock(AppConfig::class);
        $calls = [];
        $config->method('set')->willReturnCallback(static function (string $key, string $value) use (&$calls): void {
            $calls[] = ['set', $key, $value];
        });
        $config->method('setUserId')->willReturnCallback(static function (?string $uid) use (&$calls): void {
            $calls[] = ['uid', $uid];
        });

        $controller = $this->controller(config: $config, scheduler: $scheduler);
        $data = $controller->stopBackgroundIndex()->getData();

        self::assertTrue($data['stopped']);
        self::assertSame(['alice', 'bob'], $data['requestedFor']);
        self::assertArrayHasKey('scheduler', $data);
        self::assertContains(['set', 'index_job_stop_requested', '1'], $calls);
        self::assertSame(2, count(array_filter(
            $calls,
            static fn(array $c): bool => $c[0] === 'set' && $c[1] === 'index_cancel_requested' && $c[2] === '1'
        )));
        // Cancel flags must be written in the active user's scope.
        self::assertContains(['uid', 'alice'], $calls);
        self::assertContains(['uid', 'bob'], $calls);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function controller(
        ?DocumentMapper $mapper = null,
        ?AppConfig $config = null,
        ?Indexer $indexer = null,
        ?IUserManager $userManager = null,
        ?IJobList $jobList = null,
        ?IRequest $request = null,
        ?IndexScheduler $scheduler = null,
    ): AdminController {
        return new AdminController(
            'eva_ai',
            $request ?? $this->createMock(IRequest::class),
            $config ?? $this->createMock(AppConfig::class),
            $mapper ?? $this->createMock(DocumentMapper::class),
            $indexer ?? $this->createMock(Indexer::class),
            $this->createMock(RagService::class),
            $this->createMock(Ollama::class),
            $scheduler ?? $this->createMock(IndexScheduler::class),
            $jobList ?? $this->createMock(IJobList::class),
            $userManager ?? $this->createMock(IUserManager::class),
        );
    }
}