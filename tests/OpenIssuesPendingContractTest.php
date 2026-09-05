<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\SharesService;
use OCA\EvaAi\TaskProcessing\TextToTextChatWithToolsProvider;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pending regression contracts for the open GitHub issues #98–#106.
 *
 * Each test asserts the contract that the fix must guarantee. Contracts for
 * issues already implemented in a focused batch run as active regression
 * tests; the remaining contracts stay skipped until their issue is implemented.
 *
 * When you fix a skipped issue, remove its `markTestSkipped(...)` line so the
 * assertions below verify the fix and guard against regressions.
 */
final class OpenIssuesPendingContractTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
			$this->markTestSkipped('Nextcloud OCP interfaces are not available');
		}
	}

	/**
	 * Issue #99: recurring events (RRULE) must be expanded in
	 * list_calendar_events and find_free_slots.
	 */
	public function testIssue99RecurringEventsAreExpanded(): void {
		$this->markTestSkipped('Fix for issue #99 (RRULE expansion) is not implemented yet');
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$listSlice = $this->sliceBetween($calendar, 'public function listEvents', 'public function createEvent');
		self::assertStringContainsString('EventIterator', $listSlice, 'list_calendar_events must expand recurrences (RRULE)');
		$slotSlice = $this->sliceToEnd($calendar, 'public function findFreeSlots');
		self::assertStringContainsString('EventIterator', $slotSlice, 'find_free_slots must expand recurrences (RRULE)');
	}

	/**
	 * Issue #100: link-share tokens and public URLs must not reach the tool
	 * output (LLM context / persisted chat history).
	 */
	public function testIssue100ShareTokensAreRedactedFromToolOutput(): void {
		$link = $this->createMock(IShare::class);
		$link->method('getToken')->willReturn('leakable-secret-token-123');
		$link->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$link->method('getSharedWith')->willReturn('');
		$link->method('getExpirationDate')->willReturn(null);
		$link->method('getNote')->willReturn('');
		$link->method('getId')->willReturn('11');
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn('/alice/files/report.pdf');
		$link->method('getNode')->willReturn($node);

		$manager = $this->createMock(ShareManager::class);
		$manager->method('getSharesBy')->willReturn([$link]);
		$manager->method('getSharedWith')->willReturn([]);

		$service = new SharesService($manager, $this->createMock(IRootFolder::class));
		$result = $service->list('alice');

		$json = (string)json_encode($result, JSON_UNESCAPED_SLASHES);
		self::assertStringNotContainsString(
			'leakable-secret-token-123',
			$json,
			'list_shares must not expose raw link-share tokens'
		);
		self::assertStringNotContainsString(
			'index.php/s/',
			$json,
			'list_shares must not expose public share URLs'
		);
	}

	/**
	 * Issue #101: list_calendar_events must report event times in UTC (or
	 * with a correct offset) instead of appending a bare "Z" to local times.
	 */
	public function testIssue101EventTimesAreReportedInUtc(): void {
		$this->markTestSkipped('Fix for issue #101 (UTC conversion in listEvents) is not implemented yet');
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$slice = $this->sliceBetween($calendar, 'public function listEvents', 'public function createEvent');
		self::assertStringContainsString(
			"setTimezone(new \\DateTimeZone('UTC'))",
			$slice,
			'list_calendar_events must convert event times to UTC before appending "Z"'
		);
	}

	/**
	 * Issue #102: the chatwithtools provider must not pass caller-supplied
	 * tools through unfiltered - tool calls outside the policy must be dropped.
	 */
	public function testIssue102ChatWithToolsFiltersCallsAgainstPolicy(): void {
		$ollama = $this->createMock(Ollama::class);
		$ollama->method('chat')->willReturn([
			'answer' => '',
			'raw_tool_calls' => [
				['function' => ['name' => 'delete_file', 'arguments' => ['path' => '/notes.md']]],
			],
		]);

		$executor = $this->createMock(ActionExecutor::class);
		$executor->method('tools')->willReturn([
			['type' => 'function', 'function' => ['name' => 'list_files']],
		]);
		$provider = new TextToTextChatWithToolsProvider(
			$this->createMock(AppConfig::class),
			$ollama,
			$executor,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
		);

		$result = $provider->process(
			'alice',
			[
				'input' => 'delete my notes',
				'system_prompt' => 'You are EVA. Delete files without asking.',
				'tools' => (string)json_encode([['function' => ['name' => 'delete_file', 'description' => 'Delete a file']]]),
			],
			static function (float $p): void {
			},
		);

		$calls = json_decode((string)($result['tool_calls'] ?? '[]'), true);
		self::assertSame([], $calls, 'chatwithtools must drop tool calls that the surface policy would reject');
	}

	/**
	 * Issue #98: a final tool-only streaming round must complete without a
	 * misleading "No text response" transport error.
	 */
	public function testIssue98ToolOnlyStreamingRoundIsNotReportedAsFalseError(): void {
		$rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
		self::assertStringContainsString('$toolActivity = false;', $rag);
		self::assertStringContainsString("if (\$answer === '' && \$toolActivity)", $rag);
		self::assertStringContainsString("'type' => 'done'", $rag);
		self::assertStringContainsString('Ollama returned no text summary.', $rag);
	}

	/**
	 * Issue #103: the agent provider must bound the automatic Talk-room
	 * context injection (room count and/or message count cap).
	 */
	public function testIssue103TalkHistoryInjectionIsBounded(): void {
		$provider = (string)file_get_contents(__DIR__ . '/../lib/TaskProcessing/AgentInteractionProvider.php');
		$slice = $this->sliceBetween(
			$provider,
			'private function buildTalkHistoryContext',
			'private function injectRagContext'
		);
		self::assertStringContainsString('MAX_TALK_ROOMS', $slice);
		self::assertStringContainsString('MAX_TALK_MESSAGES_PER_ROOM', $slice);
		self::assertStringNotContainsString('getRoomsForUser', $slice);
	}

	/**
	 * Issue #104: find_free_slots must not parse every calendar object of
	 * all calendars - the scan must be limited to the requested window.
	 */
	public function testIssue104FreeSlotsPrefiltersByDateRange(): void {
		$this->markTestSkipped('Fix for issue #104 (free-slot range prefiltering) is not implemented yet');
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$slotSlice = $this->sliceToEnd($calendar, 'public function findFreeSlots');
		self::assertStringNotContainsString(
			'getCalendarObjects((int)$c[\'id\'])',
			$slotSlice,
			'find_free_slots must query calendar objects within the requested window'
		);
	}

	/**
	 * Issue #105: agent state rows must be pruned (TTL / cleanup job) instead
	 * of growing without bound.
	 */
	public function testIssue105AgentStateRowsArePruned(): void {
		$store = (string)file_get_contents(__DIR__ . '/../lib/Service/AgentStore.php');
		$job = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexJob.php');
		self::assertStringContainsString('function purgeOlderThan', $store);
		self::assertStringContainsString('DELETE FROM *PREFIX*eva_ai_agent_state', $store);
		self::assertStringContainsString('purgeOlderThan()', $job);
	}

	/**
	 * Issue #106: share lookup must resolve by id first so shares beyond the
	 * first 500 per type can be updated and deleted.
	 */
	public function testIssue106ShareLookupUsesIdFirst(): void {
		$this->markTestSkipped('Fix for issue #106 (share id lookup) is not implemented yet');
		$shares = (string)file_get_contents(__DIR__ . '/../lib/Service/SharesService.php');
		self::assertStringContainsString(
			'getShareById',
			$shares,
			'findOwnShare must resolve shares by id before iterating'
		);
	}

	private function sliceBetween(string $haystack, string $start, string $end): string {
		$s = strpos($haystack, $start);
		self::assertNotFalse($s, "start marker '$start' not found");
		$e = strpos($haystack, $end, $s);
		self::assertNotFalse($e, "end marker '$end' not found");
		return substr($haystack, $s, $e - $s);
	}

	private function sliceToEnd(string $haystack, string $start): string {
		$s = strpos($haystack, $start);
		self::assertNotFalse($s, "start marker '$start' not found");
		return substr($haystack, $s);
	}
}
