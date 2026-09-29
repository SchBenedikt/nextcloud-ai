<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\ProactiveBriefingJob;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkChatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProactiveBriefingJobTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		foreach ([IConfig::class, ITimeFactory::class, IManager::class, IURLGenerator::class, IUserManager::class, ILockingProvider::class, IMailer::class] as $interface) {
			if (!interface_exists($interface)) {
				self::markTestSkipped('Nextcloud interfaces unavailable; run the Nextcloud app lifecycle job');
			}
		}
	}

	public function testManualRunIgnoresScheduleTimeAndRecordsTheTalkDelivery(): void {
		$state = [
			'proactive_enabled' => '1',
			'proactive_schedules' => json_encode([[
				'id' => 'briefing-1', 'prompt' => 'Summarize the project', 'time' => '08:00', 'days' => [1],
				'type' => 'document_digest', 'channels' => ['talk'], 'talk_room' => 'Project room', 'enabled' => true,
			]]),
			'proactive_schedule_runs' => '{}',
			'proactive_schedule_history' => '[]',
		];
		$config = $this->createMock(AppConfig::class);
		$config->method('get')->willReturnCallback(static fn(string $key): string => $state[$key] ?? '');
		$config->expects(self::exactly(2))->method('set')->willReturnCallback(static function (string $key, string $value) use (&$state): void { $state[$key] = $value; });
		$config->expects(self::once())->method('setUserId')->with('alice');

		$rawConfig = $this->createMock(IConfig::class);
		$rawConfig->method('getUserValue')->willReturn('Europe/Berlin');
		$rag = $this->createMock(RagService::class);
		$rag->expects(self::once())->method('ask')->willReturn(['answer' => 'Three updated documents were found.']);
		$talk = $this->createMock(TalkChatService::class);
		$talk->expects(self::once())->method('send')->with('alice', 'Project room', 'Three updated documents were found.')
			->willReturn(['ok' => true]);

		$job = new ProactiveBriefingJob(
			$this->createMock(ITimeFactory::class),
			$config,
			$rawConfig,
			$rag,
			$this->createMock(IManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(IMailer::class),
			$this->createMock(IUserManager::class),
			$talk,
		);

		$method = new \ReflectionMethod(ProactiveBriefingJob::class, 'deliverDueSchedules');
		$method->invoke($job, 'alice', 'briefing-1');

		self::assertSame([], json_decode($state['proactive_schedule_runs'], true), 'A manual run must not consume the next scheduled slot.');
		$history = json_decode($state['proactive_schedule_history'], true);
		self::assertCount(1, $history);
		self::assertSame('document_digest', $history[0]['type']);
		self::assertSame(['talk'], $history[0]['channels']);
		self::assertSame('success', $history[0]['status']);
		self::assertTrue($history[0]['manual']);
	}
}
