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
use OCA\EvaAi\Service\TalkChatService;
use OCA\EvaAi\Service\TalkToolExecutor;
use OCA\EvaAi\Service\TerminalToolExecutor;
use OCA\EvaAi\Service\ContactsToolExecutor;
use OCA\EvaAi\Service\FileToolExecutor;
use OCP\Accounts\IAccountManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
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

	public function testTalkExecutorDelegatesRoomListingForEffectiveUser(): void {
		$talk = $this->createMock(TalkChatService::class);
		$talk->expects(self::once())->method('rooms')->with('alice', 5)->willReturn(['rooms' => [['token' => 'room-1']]]);
		$executor = new TalkToolExecutor($talk);

		self::assertContains('send_talk_message', $executor->tools());
		self::assertSame(['ok' => true, 'result' => ['rooms' => [['token' => 'room-1']]]], $executor->execute('list_talk_rooms', 'alice', ['limit' => 5]));
	}

	public function testTerminalExecutorKeepsCommandsDisabledByDefault(): void {
		$config = $this->createMock(AppConfig::class);
		$config->expects(self::once())->method('get')->with('safe_commands_enabled')->willReturn('0');
		$executor = new TerminalToolExecutor($config);

		self::assertSame(['ok' => false, 'error' => 'Safe local commands are disabled in EVA settings.'], $executor->execute('run_safe_command', 'alice', ['command' => 'date']));
	}

	public function testContactsExecutorSearchesForTheEffectiveUserQuery(): void {
		if (!interface_exists(IContactsManager::class)) {
			$this->markTestSkipped('The optional Contacts app interface is not available');
		}
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->expects(self::once())->method('search')->with('Ada', ['FN', 'NICKNAME', 'EMAIL', 'ORG'])->willReturn([
			['FN' => 'Ada Lovelace', 'EMAIL' => ['ada@example.test'], 'TEL' => ['123'], 'ORG' => 'Analytical Engines'],
		]);
		$executor = new ContactsToolExecutor($contacts, $this->createMock(IAccountManager::class), $this->createMock(IUserManager::class));

		self::assertContains('update_profile', $executor->tools());
		self::assertSame(['ok' => true, 'result' => ['query' => 'Ada', 'contacts' => [[
			'name' => 'Ada Lovelace', 'emails' => ['ada@example.test'], 'phones' => ['123'], 'org' => 'Analytical Engines',
		]]]], $executor->execute('find_contact', 'alice', ['query' => 'Ada']));
	}

	public function testFileExecutorOwnsFileAndKnowledgeTools(): void {
		if (!interface_exists(IRootFolder::class)) {
			$this->markTestSkipped('Nextcloud file APIs are not available');
		}
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects(self::never())->method('getUserFolder');
		$config = $this->createMock(AppConfig::class);
		$config->expects(self::once())->method('get')->with('learned_file_locations')->willReturn('{}');
		$executor = new FileToolExecutor($rootFolder, $config);

		self::assertContains('search_files', $executor->tools());
		self::assertContains('update_knowledge', $executor->tools());
		self::assertContains('list_learned_file_locations', $executor->tools());
		self::assertSame(['ok' => true, 'result' => ['locations' => [], 'note' => 'Only paths and types are stored; refresh with list_files or search_files when a location may have changed.']], $executor->execute('list_learned_file_locations', 'alice', []));
	}
}
