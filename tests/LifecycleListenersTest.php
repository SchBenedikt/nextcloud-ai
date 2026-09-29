<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\{ChatCleanupJob, JobRegistry};
use OCA\EvaAi\Listener\{AppLifecycleListener, UserDeletedListener};
use OCA\EvaAi\Service\{AppConfig, UserDataService};
use OCP\App\Events\AppUpdateEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class LifecycleListenersTest extends TestCase {
	protected function setUp(): void {
		if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
			self::markTestSkipped('Nextcloud OCP interfaces are not available');
		}
	}

	public function testMatchingAppUpdateRestoresOnlyMissingScheduledJobs(): void {
		$scheduled = array_fill_keys(JobRegistry::JOBS, true);
		unset($scheduled[ChatCleanupJob::class]);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::exactly(count(JobRegistry::JOBS)))->method('has')->willReturnCallback(
			static fn(string $class, mixed $argument): bool => $scheduled[$class] ?? false
		);
		$jobs->expects(self::once())->method('add')->with(ChatCleanupJob::class);

		(new AppLifecycleListener($jobs))->handle(new AppUpdateEvent(AppConfig::APP));
	}

	public function testUnrelatedEventsDoNotChangeScheduledJobs(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::never())->method('has');
		$jobs->expects(self::never())->method('add');

		(new AppLifecycleListener($jobs))->handle(new AppUpdateEvent('another_app'));
		(new AppLifecycleListener($jobs))->handle(new class extends Event {});
	}

	public function testUserDeletionEventDelegatesCleanupToTheDataService(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$data = $this->createMock(UserDataService::class);
		$data->expects(self::once())->method('cleanupDeletedAccount')->with('alice');

		(new UserDeletedListener($data))->handle(new UserDeletedEvent($user));
	}
}
