<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\CalendarService;
use OCA\EvaAi\Service\CalendarToolExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BriefingToolExecutor;
use OCA\EvaAi\Service\EmailService;
use OCA\EvaAi\Service\EmailToolExecutor;
use OCA\EvaAi\Service\ShareToolExecutor;
use OCA\EvaAi\Service\SharesService;
use PHPUnit\Framework\TestCase;

final class DomainToolExecutorTest extends TestCase {
	public function testBriefingExecutorOwnsScheduledBriefingTools(): void {
		$config = $this->createMock(AppConfig::class);
		$config->method('get')->with('proactive_schedules')->willReturn(json_encode([
			['id' => 'briefing-1', 'prompt' => 'Daily update', 'time' => '08:30', 'days' => [1, 2, 3], 'enabled' => true],
		]));
		$executor = new BriefingToolExecutor($config);

		self::assertContains('create_scheduled_assignment', $executor->tools());
		self::assertSame([
			'ok' => true,
			'result' => ['briefings' => [[
				'id' => 'briefing-1', 'prompt' => 'Daily update', 'time' => '08:30',
				'days' => [1, 2, 3], 'enabled' => true, 'allow_actions' => false,
			]]],
		], $executor->execute('list_scheduled_briefings', 'alice', []));
	}

	public function testCalendarExecutorOwnsCalendarAndTaskTools(): void {
		$calendar = $this->createMock(CalendarService::class);
		$calendar->expects(self::once())->method('listEvents')->with('alice', ['limit' => 2])->willReturn(['ok' => true, 'result' => ['events' => []]]);
		$executor = new CalendarToolExecutor($calendar);

		self::assertContains('list_tasks', $executor->tools());
		self::assertSame(['ok' => true, 'result' => ['events' => []]], $executor->execute('list_calendar_events', 'alice', ['limit' => 2]));
	}

	public function testShareExecutorDelegatesToShareService(): void {
		$shares = $this->createMock(SharesService::class);
		$shares->expects(self::once())->method('delete')->with('alice', ['share_id' => '12'])->willReturn(['ok' => true, 'result' => ['deleted' => true]]);
		$executor = new ShareToolExecutor($shares);

		self::assertSame(['ok' => true, 'result' => ['deleted' => true]], $executor->execute('delete_share', 'alice', ['share_id' => '12']));
	}

	public function testEmailExecutorKeepsMailErrorBoundary(): void {
		$email = $this->createMock(EmailService::class);
		$email->expects(self::once())->method('search')->with('alice', 'invoice', 3)->willReturn([['id' => 7]]);
		$executor = new EmailToolExecutor($email);

		self::assertSame(['ok' => true, 'result' => ['mails' => [['id' => 7]]]], $executor->execute('search_mails', 'alice', ['query' => 'invoice', 'limit' => 3]));

		$broken = $this->createMock(EmailService::class);
		$broken->method('search')->willThrowException(new \RuntimeException('offline'));
		$result = (new EmailToolExecutor($broken))->execute('search_mails', 'alice', ['query' => 'invoice']);
		self::assertFalse($result['ok']);
		self::assertSame('Mail access failed: offline', $result['error']);
	}
}
