<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\CalendarService;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\SharesService;
use OCA\EvaAi\TaskProcessing\TextToTextChatWithToolsProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pending regression contracts for the open GitHub issues #98–#106,
 * plus implemented contracts for #70 and #93.
 *
 * Each test asserts the contract that the fix must guarantee. Contracts for
 * implemented issues run as active regression tests; the remaining contracts
 * stay skipped until their issue is implemented.
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

	/** Generic app APIs must not pass credential-shaped JSON fields to the model. */
	public function testGenericAppApiPayloadRedactsSecrets(): void {
		$reflection = new \ReflectionClass(ActionExecutor::class);
		$instance = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('redactApiPayload');
		$result = $method->invoke($instance, [
			'name' => 'demo',
			'access_token' => 'secret-value',
			'nested' => ['password' => 'secret-password', 'visible' => 'ok'],
		]);
		self::assertSame('demo', $result['name']);
		self::assertSame('[redacted]', $result['access_token']);
		self::assertSame('[redacted]', $result['nested']['password']);
		self::assertSame('ok', $result['nested']['visible']);
	}

	/** A per-user JSON queue must be discoverable by the worker. */
	public function testBackgroundQueueEnumeratesUsersWithoutExactValueLookup(): void {
		$queue = (string)file_get_contents(__DIR__ . '/../lib/Service/BackgroundChatQueue.php');
		self::assertStringContainsString('getAppValue(AppConfig::APP, self::USERS_KEY', $queue);
		self::assertStringContainsString('rememberUser($user)', $queue);
		self::assertStringContainsString('Server::get(IUserManager::class)', $queue);
		self::assertStringContainsString('getUserValue($uid, AppConfig::APP, self::KEY', $queue);
		self::assertStringNotContainsString('getUsersForUserValue(AppConfig::APP, self::KEY))))', $queue);
	}

    /**
     * Issue #70: search_files must search bounded readable content as well as
     * filenames and report when its traversal limits are reached.
     */
    public function testIssue70SearchFilesSearchesBoundedTextContent(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $search = $reflection->getMethod('searchFiles');
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('notes.txt');
        $file->method('getSize')->willReturn(128);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getContent')->willReturn('The 2026 budget is approved.');
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $result = $search->invoke($instance, $folder, ['query' => 'budget']);
        self::assertSame('content', $result['result']['matches'][0]['reason']);
        self::assertStringContainsString('budget', $result['result']['matches'][0]['snippet']);
        self::assertFalse($result['result']['truncated']);

        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString("'reason' => 'content'", $executor);
        self::assertStringContainsString('getContent()', $this->sliceBetween($executor, 'private function searchWalk', 'private function findContact'));
        self::assertStringContainsString('MAX_SEARCH_NODES', $executor);
        self::assertStringContainsString("'truncated' => \$truncated", $executor);
        self::assertStringContainsString('MAX_SEARCH_FILE_BYTES', $executor);
        self::assertStringContainsString("'visited_nodes' => \$visited", $executor);
        self::assertStringContainsString("'extracted_documents' => \$extracted", $executor);
        self::assertStringContainsString('force_refresh', $executor);
        self::assertStringContainsString("'max_depth'", $executor);
        self::assertStringContainsString("'max_nodes'", $executor);
        self::assertStringContainsString("'max_results'", $executor);
		self::assertStringContainsString('unindexed PDF, DOCX, XLSX, PPTX, ODF and EPUB', $executor);
		self::assertStringContainsString('common unindexed PDF, DOCX, XLSX, PPTX, ODF and EPUB content', (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php'));
    }

    /** Direct file search must also find text inside common unindexed documents. */
    public function testIssue70SearchFilesExtractsUnindexedOfficeDocuments(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects(self::once())
            ->method('extractTextForAgent')
            ->willReturn('The migration plan is scheduled for October.');
        $indexerProperty = $reflection->getProperty('indexer');
        $indexerProperty->setValue($instance, $indexer);

        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('migration-plan.docx');
        $file->method('getSize')->willReturn(2048);
        $file->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $extract = $reflection->getMethod('searchFileContent');
        $extracted = 0;
        $snippet = $extract->invokeArgs($instance, [$file, 'october', &$extracted]);

        self::assertIsString($snippet);
        self::assertStringContainsString('October', $snippet);
        self::assertSame(1, $extracted);
    }

    /** MIME maps are not reliable for newly uploaded plain-text files. */
    public function testIssue70SearchFilesUsesSafeTextExtensionFallback(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('notes.md');
        $file->method('getSize')->willReturn(128);
        $file->method('getMimeType')->willReturn('application/octet-stream');
        $file->method('getContent')->willReturn('The direct search fallback is available.');
        $extract = $reflection->getMethod('searchFileContent');
        $extracted = 0;
        $snippet = $extract->invokeArgs($instance, [$file, 'fallback', &$extracted]);

        self::assertIsString($snippet);
        self::assertStringContainsString('fallback', $snippet);
        self::assertSame(0, $extracted);
    }

    /** Unknown octet-stream uploads are accepted only when they look like UTF-8 text. */
    public function testIssue70SearchFilesSniffsUnknownPlainTextSafely(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('export.data');
        $file->method('getSize')->willReturn(128);
        $file->method('getMimeType')->willReturn('application/octet-stream');
        $file->method('getContent')->willReturn('A plain text export contains the unique marker.');
        $extract = $reflection->getMethod('searchFileContent');
        $extracted = 0;
        $snippet = $extract->invokeArgs($instance, [$file, 'unique marker', &$extracted]);
        self::assertIsString($snippet);
        self::assertStringContainsString('unique marker', $snippet);

        $binary = $this->createMock(File::class);
        $binary->method('getName')->willReturn('blob.data');
        $binary->method('getSize')->willReturn(128);
        $binary->method('getMimeType')->willReturn('application/octet-stream');
        $binary->method('getContent')->willReturn("header\0binary");
        self::assertNull($extract->invokeArgs($instance, [$binary, 'binary', &$extracted]));
    }

    /** OpenAPI security schemes make first-time connector setup self-describing. */
    public function testConnectorAuthInferenceRecognizesApiKeyAndBasicSchemes(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $infer = $reflection->getMethod('inferConnectorAuth');
        self::assertSame(['auth_type' => 'api_key', 'api_key_header' => 'x-api-key'], $infer->invoke($instance, [
            'components' => ['securitySchemes' => ['immich' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'x-api-key']]],
        ]));
        self::assertSame(['auth_type' => 'basic'], $infer->invoke($instance, [
            'securityDefinitions' => ['auth' => ['type' => 'basic']],
        ]));
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString("\$currentRow['api_key_header'] = 'x-api-key'", $executor);
    }

    public function testConnectorRequestBodyRequiresOnlyLearnedRequiredFields(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validateConnectorRequestBody');
        $endpoint = ['request_body' => ['required' => true, 'fields' => [
            ['name' => 'query', 'required' => true], ['name' => 'limit', 'required' => false],
        ]]];
        self::assertStringContainsString('query', (string)$validate->invoke($instance, $endpoint, []));
        self::assertNull($validate->invoke($instance, $endpoint, ['query' => 'Lena', 'extra' => 'allowed']));
    }

    public function testConnectorRequestUsesLearnedContentType(): void {
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString('application/x-www-form-urlencoded', $executor);
        self::assertStringContainsString("'multipart/form-data' => \$params", $executor);
        self::assertStringContainsString('content_type', $executor);
    }

    public function testConnectorSchemaDecodesYamlWithoutExtYaml(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $decoded = $reflection->getMethod('decodeConnectorSchema')->invoke($instance, "openapi: 3.0.0\npaths:\n  /health:\n    get:\n      responses: {}\n");
        self::assertIsArray($decoded);
        self::assertArrayHasKey('/health', $decoded['paths']);
        self::assertArrayHasKey('get', $decoded['paths']['/health']);
    }

    /** A stale connector catalog is refreshed once before an exact route is rejected. */
    public function testConnectorCallsAutoRefreshStaleDiscoveryOnce(): void {
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString("\$args['_auto_discover'] ?? true", $executor);
        self::assertStringContainsString("discoverExternalConnector(['id' => \$id])", $executor);
        self::assertStringContainsString("\$args['_auto_discover'] = false", $executor);
        self::assertStringContainsString("This connector route was not discovered. Run discover_external_connector first.", $executor);
    }

    public function testPluginCatalogExposesSafetyMetadata(): void {
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString("'risk' => (string)(\$definition['risk']", $executor);
        self::assertStringContainsString("'surfaces' => array_values", $executor);
        self::assertStringContainsString("'requiresConfirmation' => (bool)", $executor);
        self::assertStringContainsString('Confirmation required', (string)file_get_contents(__DIR__ . '/../src/views/SettingsView.vue'));
    }

    public function testGraphqlConnectorDiscoveryLearnsValidatedPostBody(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $meta = $reflection->getMethod('graphqlEndpointMeta')->invoke($instance, '/api/graphql', 405);
        self::assertIsArray($meta);
        self::assertSame('POST', $meta['method']);
        self::assertSame('query', $meta['request_body']['fields'][0]['name']);
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString('runtime-graphql', $executor);
        self::assertLessThan(strpos($executor, "if (\$reachableRoutes !== [] &&"), strpos($executor, "if (\$graphqlEndpoints !== [])"));
    }

    public function testAppLoadsItsOptionalProductionComposerAutoloader(): void {
        $application = (string)file_get_contents(__DIR__ . '/../lib/AppInfo/Application.php');
        self::assertStringContainsString("__DIR__ . '/../../vendor/autoload.php'", $application);
        self::assertStringContainsString('require_once $autoload', $application);
    }

    /** Terminal prompts never get a shell parser and remain confirmation-gated. */
    public function testConfirmedTerminalCommandRejectsShellSyntax(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['terminal_commands_enabled', '1'],
            ['terminal_command_any', '0'],
            ['terminal_command_allowlist', 'date'],
        ]);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setValue($instance, $config);
        $run = $reflection->getMethod('runTerminalCommand');
        $result = $run->invoke($instance, ['command' => 'date; cat /etc/passwd']);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('shell syntax', strtolower((string)$result['error']));
    }

    public function testTerminalSequenceIsBoundedAndConfirmationGated(): void {
        $executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
        self::assertStringContainsString("'run_terminal_sequence' => ['commands']", $executor);
        self::assertStringContainsString("'maxItems' => 5", $executor);
        self::assertStringContainsString("\$name === 'run_terminal_sequence'", $executor);
    }

    public function testSafeCommandUsesBoundedNonBlockingProcessHandling(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([['safe_commands_enabled', '1']]);
        $reflection->getProperty('config')->setValue($instance, $config);
        $result = $reflection->getMethod('runSafeCommand')->invoke($instance, ['command' => 'date']);
        self::assertTrue($result['ok']);
        self::assertArrayHasKey('timed_out', $result['result']);
        self::assertStringContainsString('stream_set_blocking', (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php'));
    }

    /** Absolute executable paths must be explicitly allowlisted. */
    public function testConfirmedTerminalCommandDoesNotAllowPathAlias(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['terminal_commands_enabled', '1'],
            ['terminal_command_any', '0'],
            ['terminal_command_allowlist', 'date'],
        ]);
        $reflection->getProperty('config')->setValue($instance, $config);
        $result = $reflection->getMethod('runTerminalCommand')->invoke($instance, ['command' => '/tmp/date']);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('allowlist', strtolower((string)$result['error']));
    }

    /** Custom executable mode still uses argv execution and bounded output. */
    public function testCustomTerminalExecutableModeRunsExplicitPath(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['terminal_commands_enabled', '1'],
            ['terminal_command_any', '1'],
            ['terminal_command_allowlist', 'date'],
        ]);
        $reflection->getProperty('config')->setValue($instance, $config);
        $result = $reflection->getMethod('runTerminalCommand')->invoke($instance, ['command' => '/bin/echo eva-custom-terminal']);
        self::assertTrue($result['ok']);
        self::assertStringContainsString('eva-custom-terminal', (string)$result['result']['output']);
    }

    public function testConfirmedTerminalCommandCanProvideBoundedStdin(): void {
        $reflection = new \ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = $this->createMock(AppConfig::class);
        $config->method('get')->willReturnMap([
            ['terminal_commands_enabled', '1'],
            ['terminal_command_any', '1'],
            ['terminal_command_allowlist', 'cat'],
        ]);
        $reflection->getProperty('config')->setValue($instance, $config);
        $result = $reflection->getMethod('runTerminalCommand')->invoke($instance, ['command' => '/bin/cat', 'stdin' => "prompt-answer\n"]);
        self::assertTrue($result['ok']);
        self::assertStringContainsString('prompt-answer', (string)$result['result']['output']);
    }

    /** Live tool traces expose bounded output but never connector credentials. */
    public function testLiveToolResultIsRedactedAndBounded(): void {
        $reflection = new \ReflectionClass(RagService::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('safeToolResult');
        $result = $method->invoke($instance, [
            'command' => 'date',
            'output' => str_repeat('x', 5000),
            'api_key' => 'do-not-show',
            'nested' => ['password' => 'do-not-show-too', 'exit_code' => 0],
        ]);

        self::assertIsArray($result);
        self::assertSame('[redacted]', $result['api_key']);
        self::assertSame('[redacted]', $result['nested']['password']);
        self::assertLessThanOrEqual(4001, mb_strlen((string)$result['output']));
    }

    public function testBackgroundTraceSeparatesToolResultsFromArguments(): void {
        $queue = (string)file_get_contents(__DIR__ . '/../lib/Service/BackgroundChatQueue.php');
        self::assertStringContainsString("\$phase === 'tool_result'", $queue);
        self::assertStringContainsString("\$entry['result']", $queue);
        self::assertStringContainsString("\$entry['elapsed_ms']", $queue);
    }

    /**
     * Issue #93: knowledge trimming must preserve the automatic identity block
     * while dropping old non-profile lines.
     */
    public function testIssue93KnowledgeTrimPreservesIdentityBlock(): void {
        $executor = new \ReflectionClass(ActionExecutor::class);
        $instance = $executor->newInstanceWithoutConstructor();
        $method = $executor->getMethod('trimKnowledge');
        $content = implode("\n", [
            '# Old notes',
            str_repeat('old fact ', 8000),
            '<!-- eva_ai:profile-initialized -->',
            '## About me (from my Nextcloud profile)',
            '- Nextcloud user ID: alice',
            '- Name: Alice Example',
            '- Imported automatically on 2026-08-21.',
            '- 2026-08-21: newest fact',
        ]);
        [$trimmed, $wasTrimmed] = $method->invoke($instance, $content);
        self::assertTrue($wasTrimmed);
        self::assertLessThanOrEqual(45000, mb_strlen($trimmed));
        self::assertStringContainsString('eva_ai:profile-initialized', $trimmed);
        self::assertStringContainsString('Nextcloud user ID: alice', $trimmed);
        self::assertStringContainsString('Name: Alice Example', $trimmed);
        self::assertStringContainsString('newest fact', $trimmed);
        self::assertStringNotContainsString('# Old notes', $trimmed);
    }

    /**
     * Issue #99: recurring events (RRULE) must be expanded in

	 * list_calendar_events and find_free_slots.
	 */
	public function testIssue99RecurringEventsAreExpanded(): void {
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$listSlice = $this->sliceBetween($calendar, 'public function listEvents', 'public function createEvent');
		self::assertStringContainsString('EventIterator', $listSlice, 'list_calendar_events must expand recurrences (RRULE)');
		self::assertStringContainsString('including shared/read-only calendars', $listSlice, 'calendar reads without a hint must include shared calendars');
		$slotSlice = $this->sliceToEnd($calendar, 'public function findFreeSlots');
		self::assertStringContainsString('expandEvents', $slotSlice, 'find_free_slots must expand recurrences (RRULE)');
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
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$slice = $this->sliceBetween($calendar, 'public function listEvents', 'public function createEvent');
		self::assertStringContainsString(
			'formatEventDateTime',
			$slice,
			'list_calendar_events must use the timezone-safe serializer'
		);

		$reflection = new \ReflectionClass(CalendarService::class);
		$instance = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('formatEventDateTime');
		$local = new \DateTimeImmutable('2026-08-20 16:00:00', new \DateTimeZone('Europe/Berlin'));
		self::assertSame('2026-08-20T14:00:00Z', $method->invoke($instance, $local, false));
		self::assertSame('2026-08-20', $method->invoke($instance, $local, true));
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
		$calendar = (string)file_get_contents(__DIR__ . '/../lib/Service/CalendarService.php');
		$slotSlice = $this->sliceToEnd($calendar, 'public function findFreeSlots');
		self::assertStringNotContainsString(
			'getCalendarObjects((int)$c[\'id\'])',
			$slotSlice,
			'find_free_slots must query calendar objects within the requested window'
		);
		self::assertStringContainsString(
			'eventUrisInRange((int)$c[\'id\'], $rangeStart, $rangeEnd)',
			$slotSlice,
			'find_free_slots must pass its bounded date range to the backend query'
		);

		$querySlice = $this->sliceBetween($calendar, 'private function eventUrisInRange', 'public function createEvent');
		self::assertStringContainsString('calendarQuery($calendarId', $querySlice);
		self::assertStringContainsString("'name' => 'VEVENT'", $querySlice);
		self::assertStringContainsString("'time-range'", $querySlice);

		$rangeStart = new \DateTimeImmutable('2026-09-05T00:00:00+00:00');
		$rangeEnd = new \DateTimeImmutable('2026-09-12T00:00:00+00:00');
		$backend = $this->createMock(CalDavBackend::class);
		$backend->expects(self::once())
			->method('calendarQuery')
			->with(42, self::callback(static function (array $filter) use ($rangeStart, $rangeEnd): bool {
				$event = $filter['comp-filters'][0] ?? [];
				return ($filter['name'] ?? null) === 'VCALENDAR'
					&& ($event['name'] ?? null) === 'VEVENT'
					&& ($event['time-range']['start'] ?? null) === $rangeStart
					&& ($event['time-range']['end'] ?? null) === $rangeEnd;
			}))
			->willReturn(['first.ics', '', 'second.ics']);

		$service = new CalendarService($backend, $this->createMock(\OCP\IConfig::class));
		$method = new \ReflectionMethod($service, 'eventUrisInRange');
		self::assertSame(
			['first.ics', 'second.ics'],
			$method->invoke($service, 42, $rangeStart, $rangeEnd),
			'calendarQuery results must be normalized before calendar objects are loaded'
		);
	}

	/**
	 * Issue #110: exhausting one worker's pass budget must queue a follow-up
	 * job instead of reporting a false terminal error.
	 */
	public function testIssue110BackgroundIndexQueuesContinuation(): void {
		$job = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexRequestJob.php');
		self::assertStringContainsString('$this->jobList->add(self::class', $job);
		self::assertStringNotContainsString('Indexing stopped after the safety pass limit.', $job);
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
		$shares = (string)file_get_contents(__DIR__ . '/../lib/Service/SharesService.php');
		$slice = $this->sliceToEnd($shares, 'private function findOwnShare');
		self::assertStringContainsString(
			'getShareById',
			$slice,
			'findOwnShare must resolve shares by id before iterating'
		);
		self::assertStringContainsString(
			'\'ocinternal:\' . $id',
			$slice,
			'plain provider IDs must be resolved through the internal share provider'
		);
		self::assertStringNotContainsString(
			'getSharesBy($userId, $type, null, true, 500, 0)',
			$slice,
			'findOwnShare must not be limited to the first 500 shares'
		);
	}

	/** Long web pages are consumable in deterministic pages instead of silently
	 * dropping the tail of the source. */
	public function testOpenWebsiteSupportsFullSourcePagination(): void {
		$service = (string)file_get_contents(__DIR__ . '/../lib/Service/WebSearchService.php');
		$executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
		$rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
		self::assertStringContainsString('int $offset = 0, ?int $maxChars = null', $service);
		self::assertStringContainsString('$nextOffset', $service);
		self::assertStringContainsString("'has_more'", $service);
		self::assertStringContainsString('openPage($url, $query, $offset, $maxChars)', $executor);
		self::assertStringContainsString('continue until has_more=false', $rag);
	}

	/** Generic app learning records response structure, never response values. */
	public function testGenericAppLearningStoresOnlyResponseShape(): void {
		$executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
		self::assertStringContainsString('response_shape', $executor);
		self::assertStringContainsString('private function shapeOf', $executor);
		self::assertStringContainsString('array_slice($value, 0, 40, true)', $executor);
	}

	public function testBackgroundRunsSupportPauseAndResume(): void {
		$queue = (string)file_get_contents(__DIR__ . '/../lib/Service/BackgroundChatQueue.php');
		$routes = (string)file_get_contents(__DIR__ . '/../appinfo/routes.php');
		self::assertStringContainsString('public function pause(', $queue);
		self::assertStringContainsString('public function resume(', $queue);
		self::assertStringContainsString("'paused'", $queue);
		self::assertStringContainsString("backgroundChat/pause", $routes);
		self::assertStringContainsString("backgroundChat/resume", $routes);
		self::assertStringContainsString("backgroundChat/retry", $routes);
		self::assertStringContainsString('HISTORY_KEY', $queue);
		self::assertStringContainsString("'tool_result'", $queue);
		self::assertStringContainsString("'[redacted]'", $queue);
		self::assertStringContainsString('array_slice($value, 0, 20, true)', $queue);
		self::assertStringContainsString("'result' => \$this->safeToolResult", (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php'));
	}

	public function testExternalConnectorsAreBoundedAndConfirmationReady(): void {
		$executor = (string)file_get_contents(__DIR__ . '/../lib/Service/ActionExecutor.php');
		$policy = (string)file_get_contents(__DIR__ . '/../lib/Service/ToolPolicy.php');
		self::assertStringContainsString('safeConnectorUrl', $executor);
		self::assertStringContainsString("'https'", $executor);
		self::assertStringContainsString('count($params) > 50', $executor);
		self::assertStringContainsString("'call_external_connector'", $policy);
		self::assertStringContainsString("'diagnose_external_connector'", $policy);
		self::assertStringContainsString("'configure_external_connector'", $policy);
		self::assertStringContainsString('$ip !== $host', $executor);
		self::assertStringContainsString("(\$parameter['in'] ?? '') !== 'query'", $executor);
		self::assertStringContainsString('http_build_query($queryParams', $executor);
		self::assertStringContainsString('discoverExternalConnector', $executor);
		self::assertStringContainsString("'/openapi.json'", $executor);
		self::assertStringContainsString("'openapi'", $executor);
        self::assertStringContainsString('decodeConnectorSchema', $executor);
        self::assertStringContainsString("function_exists('yaml_parse')", $executor);
        self::assertStringContainsString('Symfony\\Component\\Yaml\\Yaml', $executor);
		self::assertStringContainsString("\$meta['parameters'] = \$params", $executor);
		self::assertStringContainsString('connectorRequestBodyMeta', $executor);
		self::assertStringContainsString("'request_body'", $executor);
		self::assertStringContainsString("'required' => !empty(\$parameter['required'])", $executor);
		self::assertStringContainsString('splitRequestPath', $executor);
		self::assertStringContainsString('JSON_THROW_ON_ERROR', $executor);
		self::assertStringContainsString('Learned routes belong to a specific service origin', $executor);
		self::assertStringContainsString('empty secret fields', $executor);
		self::assertStringContainsString('CONNECTOR_DISCOVERY_BUDGET', $executor);
		self::assertStringContainsString('same-origin JavaScript bundles', $executor);
		self::assertStringContainsString("'runtime_probe'", $executor);
		self::assertStringContainsString("'requires_auth'", $executor);
		self::assertStringContainsString('normalizeBearerToken', $executor);
		self::assertStringContainsString("trim((string)\$args['token']) !== ''", $executor);
		$webSearch = (string)file_get_contents(__DIR__ . '/../lib/Service/WebSearchService.php');
		self::assertStringContainsString('OPENVERSE_IMAGE_ENDPOINT', $webSearch);
		self::assertStringContainsString('searchOpenverseImages', $webSearch);
		self::assertStringContainsString("'create_files'", $executor);
		self::assertStringContainsString('private function createFiles', $executor);
		self::assertStringContainsString("'move_file'", $executor);
		self::assertStringContainsString('private function moveFile', $executor);
		self::assertStringContainsString("'copy_file'", $executor);
		self::assertStringContainsString('private function copyFile', $executor);
		self::assertStringContainsString("'file_checksum'", $executor);
		self::assertStringContainsString('private function fileChecksum', $executor);
		self::assertStringContainsString("'read_files'", $executor);
		$searcher = (string)file_get_contents(__DIR__ . '/../lib/Service/Searcher.php');
		self::assertStringContainsString('cloudChatWithLocalEmbedding', $searcher);
		self::assertStringContainsString("str_ends_with(\$chatModel, ':cloud')", $searcher);
		self::assertStringContainsString('if ($queryVector !== null)', $searcher);
		$indexer = (string)file_get_contents(__DIR__ . '/../lib/Service/Indexer.php');
		self::assertStringContainsString('yield from $this->collectFilesGenerator', $indexer);
		self::assertStringContainsString('gc_collect_cycles()', $indexer);
		self::assertStringContainsString("customValueConfigured(\$user, \$prefix, 'token')", $executor);
		self::assertStringContainsString("getCustomValue(\$user, \$prefix, 'token')", $executor);
		self::assertStringContainsString('private function readFiles', $executor);
		self::assertStringContainsString("'extension' => ['type' => 'string'", $executor);
		self::assertStringContainsString('$scopePath = $this->cleanPath', $executor);
		self::assertStringContainsString('SEARCH_CACHE_TTL', $executor);
		self::assertStringContainsString('createDistributed(\'eva_ai_search_\')', $executor);
		self::assertStringContainsString('bumpSearchRevision', $executor);
		self::assertStringContainsString("'search_revision'", (string)file_get_contents(__DIR__ . '/../lib/Service/AppConfig.php'));
		self::assertStringContainsString("'file_id' => (int)\$node->getId()", $executor);
		self::assertStringContainsString('recordToolMetric', $executor);
		self::assertStringContainsString('public function recordTool', (string)file_get_contents(__DIR__ . '/../lib/Service/UsageMetrics.php'));
		self::assertGreaterThanOrEqual(2, substr_count((string)file_get_contents(__DIR__ . '/../lib/Service/UsageMetrics.php'), "eq('operation', \$qb->createNamedParameter('chat')"));
		self::assertStringContainsString("if (!is_array(\$known[\$appId] ?? null)) \$known[\$appId]", $executor);
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
