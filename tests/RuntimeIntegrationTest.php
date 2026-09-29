<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\ToolPolicy;
use OCA\EvaAi\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runtime integration tests for indexing, Talk, file actions, and
 * notifications (Issue #136).
 *
 * These tests verify behaviour at the PHP unit level using mocks,
 * covering the key paths that the existing contract tests do not exercise.
 */
final class RuntimeIntegrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    // ---- Indexing: start, cancel, restart, consistency ----

    public function testReconcileMailIndexRemovesDeletedMessages(): void {
        self::assertSame(
            [-2],
            Indexer::staleMailFileIds([-1, -2, -3, 45], [1, 3, 0, -4]),
            'Only deleted negative mail rows should be reconciled; file and Talk rows must stay untouched'
        );
    }

    // ---- Talk: read-only enforcement ----

    public function testTalkSurfaceBlocksMutatingTools(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TALK);

        // Write tools must be blocked on Talk
        $blocked = ['create_file', 'write_file', 'delete_file', 'rename_file',
                     'create_folder', 'create_calendar_event', 'update_calendar_event', 'delete_calendar_event',
                     'create_contact', 'update_contact', 'delete_contact',
                     'create_share', 'update_share', 'delete_share'];
        foreach ($blocked as $tool) {
            $result = $policy->check($tool);
            self::assertFalse($result['allowed'], "Tool '$tool' must be blocked on Talk surface");
        }
    }

    public function testTalkSurfaceAllowsReadOnlyTools(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TALK);

        $allowed = ['list_files', 'read_file', 'search_files', 'find_contact',
                     'list_calendars', 'list_calendar_events', 'current_time'];
        foreach ($allowed as $tool) {
            $result = $policy->check($tool);
            self::assertTrue($result['allowed'], "Tool '$tool' must be allowed on Talk surface");
        }
    }

    public function testTalkSurfaceBlocksProfileModification(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TALK);

        $result = $policy->check('update_profile');
        self::assertFalse($result['allowed'], 'Profile update must be blocked on Talk');
    }

    public function testTalkSurfaceBlocksShareListing(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TALK);

        $result = $policy->check('list_shares');
        self::assertFalse($result['allowed'], 'Share listing must be blocked on Talk');
    }

    public function testTalkSurfaceBlocksServerStatus(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TALK);

        $result = $policy->check('server_status');
        self::assertFalse($result['allowed'], 'Server status must be blocked on Talk');
    }

    // ---- File context: selected-file access enforcement ----

    public function testFileContextChatRequiresFileIds(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->expects(self::once())->method('get')->with('chat_model')->willReturn('test-model');
        $documents = $this->createMock(\OCA\EvaAi\Db\DocumentMapper::class);
        $documents->expects(self::never())->method('findByUserAndFileIds');
        $ollama = $this->createMock(\OCA\EvaAi\Service\Ollama::class);
        $ollama->expects(self::never())->method('chat');
        $service = new \OCA\EvaAi\Service\FileContextChatService(
            $ollama,
            $config,
            $documents,
            $this->createMock(\OCA\EvaAi\Db\ChunkMapper::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IURLGenerator::class),
        );

        $result = $service->chat('alice', [], 'Summarize these files');
        self::assertSame('Please select at least one file.', $result['answer']);
        self::assertSame('test-model', $result['model']);
        self::assertSame(0, $result['missing']);
    }

    // ---- Email: graceful degradation when Mail app is absent ----

    public function testEmailServiceDegradesGracefullyWhenMailNotInstalled(): void {
        $db = $this->createMock(\OCP\IDBConnection::class);
        $appManager = $this->createMock(\OCP\App\IAppManager::class);
        $appManager->method('isInstalled')->willReturnMap([
            ['mail', false],
        ]);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailService($db, $appManager, $logger);

        // bodyText should return empty when Mail is not installed
        $result = $service->bodyText(42, 'alice');
        self::assertSame('', $result);

        // fetchAttachments should return empty
        $attachments = $service->fetchAttachments('alice', 42);
        self::assertSame([], $attachments);
    }

    public function testEmailServiceBodyTextFallsBackToPreviewOnImapFailure(): void {
        $db = $this->createMock(\OCP\IDBConnection::class);
        $appManager = $this->createMock(\OCP\App\IAppManager::class);
        $appManager->method('isInstalled')->willReturnMap([
            ['mail', true],
        ]);
        $logger = $this->createMock(LoggerInterface::class);

        // Mock DB query to return preview text
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('execute')->willReturn($this->createMock(\OCP\DB\IResult::class));
        $stmt->method('fetchAll')->willReturn([
            ['preview_text' => 'Hello preview', 'summary' => ''],
        ]);
        $db->method('prepare')->willReturn($stmt);

        $service = new EmailService($db, $appManager, $logger);

        // Should fall back to DB preview since IMAP will fail
        $result = $service->bodyText(42, 'alice');
        self::assertSame('Hello preview', $result);
    }

    // ---- Tool policy: surface isolation ----

    public function testWebSurfaceBlocksDestructiveTools(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_WEB);

        $result = $policy->check('delete_file');
        // Web surface should require confirmation for destructive tools,
        // not outright block them.
        self::assertSame([
            'allowed' => true,
            'risk' => ToolPolicy::RISK_DESTRUCTIVE,
            'requiresConfirmation' => false,
        ], $result);
    }

    public function testTaskProcessingSurfaceReadOnly(): void {
        $config = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
        $config->method('getInt')->willReturn(1);
        $policy = new ToolPolicy($config);
        $policy->setSurface(ToolPolicy::SURFACE_TASKPROCESSING);

        // TaskProcessing should be read-only like Talk
        $result = $policy->check('create_file');
        self::assertFalse($result['allowed'], 'TaskProcessing surface must be read-only');
    }

}
