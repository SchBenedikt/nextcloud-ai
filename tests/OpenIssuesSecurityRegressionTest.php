<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\Ollama;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class OpenIssuesSecurityRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testRevokedFileIsPurgedBeforeCachedContentCanBeUsed(): void {
        $document = new Document();
        $document->setId(7);
        $document->setFileId(42);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->expects(self::once())
            ->method('getById')
            ->with(42)
            ->willReturn([]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::once())
            ->method('getUserFolder')
            ->with('alice')
            ->willReturn($userFolder);

        $documentMapper = $this->createMock(DocumentMapper::class);
        $documentMapper->expects(self::once())->method('delete')->with($document);
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunkMapper->expects(self::once())->method('deleteByDocument')->with(7);

        $service = new FileContextChatService(
            $this->createMock(Ollama::class),
            $this->createMock(AppConfig::class),
            $documentMapper,
            $chunkMapper,
            $rootFolder,
            $this->createMock(IURLGenerator::class),
        );

        self::assertSame([], $service->accessibleDocuments('alice', [$document]));
    }

    public function testAccessibleDocumentsRejectFoldersReturnedForAFileId(): void {
        $document = new Document();
        $document->setId(9);
        $document->setFileId(44);

        $folderNode = $this->createMock(Folder::class);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->expects(self::once())
            ->method('getById')
            ->with(44)
            ->willReturn([$folderNode]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $documentMapper = $this->createMock(DocumentMapper::class);
        $documentMapper->expects(self::once())->method('delete')->with($document);
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunkMapper->expects(self::once())->method('deleteByDocument')->with(9);

        $service = new FileContextChatService(
            $this->createMock(Ollama::class),
            $this->createMock(AppConfig::class),
            $documentMapper,
            $chunkMapper,
            $rootFolder,
            $this->createMock(IURLGenerator::class),
        );

        self::assertSame([], $service->accessibleDocuments('alice', [$document]));
    }

    public function testCurrentlyAccessibleFileRemainsAvailableFromTheCache(): void {
        $document = new Document();
        $document->setId(8);
        $document->setFileId(43);

        $file = $this->createMock(File::class);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->expects(self::once())
            ->method('getById')
            ->with(43)
            ->willReturn([$file]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $documentMapper = $this->createMock(DocumentMapper::class);
        $documentMapper->expects(self::never())->method('delete');
        $chunkMapper = $this->createMock(ChunkMapper::class);
        $chunkMapper->expects(self::never())->method('deleteByDocument');

        $service = new FileContextChatService(
            $this->createMock(Ollama::class),
            $this->createMock(AppConfig::class),
            $documentMapper,
            $chunkMapper,
            $rootFolder,
            $this->createMock(IURLGenerator::class),
        );

        self::assertSame([$document], $service->accessibleDocuments('alice', [$document]));
    }

    public function testStopIndexLeavesTerminalStateToTheWorker(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $start = strpos($controller, 'public function stopIndex');
        self::assertNotFalse($start);
        $end = strpos($controller, 'private function queueIndex', $start);
        self::assertNotFalse($end);
        $stop = substr($controller, $start, $end - $start);
        self::assertStringContainsString("set('index_cancel_requested', '1')", $stop);
        self::assertStringContainsString("'stopping' => true", $stop);
        self::assertStringNotContainsString("set('index_running', '0')", $stop);
        self::assertStringNotContainsString("set('index_run_id', '')", $stop);
        self::assertStringNotContainsString("set('index_finished', (string)time())", $stop);
    }

    public function testEmbeddingCancellationIsBoundedAndDiscardsStagedRows(): void {
        $ollama = (string)file_get_contents(__DIR__ . '/../lib/Service/Ollama.php');
        // The total embedding deadline may now grow with the batch (a cold model
        // needs the time), but it must stay bounded and every embedding request
        // must still carry a read timeout, or "stop indexing" would hang on a
        // silent server.
        self::assertMatchesRegularExpression('/EMBED_READ_TIMEOUT\\s*=\\s*(\\d+)/', $ollama);
        preg_match('/EMBED_READ_TIMEOUT\\s*=\\s*(\\d+)/', $ollama, $matches);
        self::assertGreaterThanOrEqual(1, (int)$matches[1]);
        self::assertLessThanOrEqual(60, (int)$matches[1]);
        self::assertGreaterThanOrEqual(2, substr_count($ollama, "'read_timeout' => self::EMBED_READ_TIMEOUT"));
        self::assertStringContainsString('EMBED_TIMEOUT_CEILING', $ollama);

        $indexer = (string)file_get_contents(__DIR__ . '/../lib/Service/Indexer.php');
        self::assertStringContainsString('private function discardStagedBatch', $indexer);
        self::assertStringContainsString('Never publish vectors', $indexer);
        self::assertStringContainsString('$this->discardStagedBatch($batch);', $indexer);
    }

    public function testIndexStartTreatsDuplicateRunsAsIdempotent(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $start = strpos($controller, 'private function queueIndex');
        self::assertNotFalse($start);
        $end = strpos($controller, 'private function recoverStaleIndex', $start);
        self::assertNotFalse($end);
        $queue = substr($controller, $start, $end - $start);
        self::assertStringContainsString("'alreadyRunning' => true", $queue);
        self::assertStringContainsString("'waitingForStop' => true", $queue);
        self::assertStringContainsString("'message' => 'Indexing is already running for this user.'", $queue);
        $requestJob = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexRequestJob.php');
        self::assertStringContainsString("'waitForCancellation'", $controller);
        self::assertStringContainsString('$waitForCancellation', $requestJob);
        self::assertStringContainsString('sleep(1)', $requestJob);
        self::assertStringContainsString('private function requestParam', $controller);
        self::assertStringContainsString('private function requestBody', $controller);
        self::assertStringContainsString('$this->request->getParam(', $controller);
        self::assertStringNotContainsString('private function param', $controller);
        self::assertStringNotContainsString('function jsonBody', $controller);
        self::assertStringContainsString('if ($age > 900 || ($cancelRequested && $age > 300))', $controller);
        self::assertStringContainsString('if ($age > 900 || ($cancelRequested && $age > 300))', (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php'));
        self::assertStringContainsString('if ($age > 900 || ($cancelRequested && $age > 300))', (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexJob.php'));
    }

    public function testIndexingContractUsesOneExclusivePerUserClaimPath(): void {
        $indexer = (string)file_get_contents(__DIR__ . '/../lib/Service/Indexer.php');
        self::assertStringContainsString('ILockingProvider::LOCK_EXCLUSIVE', $indexer);
        self::assertStringContainsString('LockGuard::indexLockPath($userId)', $indexer);
        self::assertStringContainsString('tryClaimIndex($userId)', $indexer);
        self::assertStringContainsString('releaseLock($lockPath', $indexer);
        // The per-user lock key must stay below the varchar(64) key column of
        // Nextcloud's file_locks table; the full sha256 would overflow it and
        // make acquire/release silently fail (stale locks block indexing).
        $guard = (string)file_get_contents(__DIR__ . '/../lib/Service/LockGuard.php');
        self::assertStringContainsString("'eva_ai/index/'", $guard);
        self::assertStringContainsString('substr(hash(\'sha256\', $userId), 0, 40)', $guard);

        $requestJob = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexRequestJob.php');
        self::assertStringContainsString('$this->indexer->run($userId', $requestJob);
    }

    public function testFileContextSharesTheContextBudgetFairlyAcrossInterleavedDocuments(): void {
        $service = new FileContextChatService(
            $this->createMock(Ollama::class),
            $this->createMock(AppConfig::class),
            $this->createMock(DocumentMapper::class),
            $this->createMock(ChunkMapper::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IURLGenerator::class),
        );
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('groupChunksWithinBudget');
        $chunks = [
            ['document_id' => 1, 'content' => str_repeat('a', 8000)],
            ['document_id' => 2, 'content' => str_repeat('b', 8000)],
            ['document_id' => 1, 'content' => str_repeat('c', 8000)],
            ['document_id' => 2, 'content' => str_repeat('d', 8000)],
        ];

        // A 24000 character budget is shared equally: each document may use
        // 12000 characters and never more than the whole budget together.
        $grouped = $method->invoke($service, $chunks, 24000);
        self::assertSame(12000, mb_strlen(implode('', $grouped[1])));
        self::assertSame(12000, mb_strlen(implode('', $grouped[2])));
        self::assertSame(str_repeat('a', 8000) . str_repeat('c', 4000), implode('', $grouped[1]));
        self::assertSame(str_repeat('b', 8000) . str_repeat('d', 4000), implode('', $grouped[2]));
    }

    public function testFileContextLargeDocumentCanUseMostOfTheBudgetInsteadOfA12000Cap(): void {
        $config = $this->createMock(AppConfig::class);
        // Default model context of 12288 tokens leaves room for far more than
        // the old fixed 12000 characters when only one large document is asked.
        $config->method('get')->with('context_size')->willReturn('12288');
        $service = new FileContextChatService(
            $this->createMock(Ollama::class),
            $config,
            $this->createMock(DocumentMapper::class),
            $this->createMock(ChunkMapper::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IURLGenerator::class),
        );
        $reflection = new \ReflectionClass($service);
        $budget = $reflection->getMethod('contextBudgetChars');
        $budgetChars = $budget->invoke($service, 'system', [], 'Frage?');
        self::assertGreaterThan(12000, $budgetChars, 'a single document should not be stuck at the old 12000 cap');

        $group = $reflection->getMethod('groupChunksWithinBudget');
        $chunks = [
            ['document_id' => 1, 'content' => str_repeat('a', 10000)],
            ['document_id' => 1, 'content' => str_repeat('b', 10000)],
            ['document_id' => 1, 'content' => str_repeat('c', 10000)],
            ['document_id' => 1, 'content' => str_repeat('d', 10000)],
        ];
        $grouped = $group->invoke($service, $chunks, $budgetChars);
        self::assertLessThanOrEqual($budgetChars, mb_strlen(implode('', $grouped[1])));
        self::assertGreaterThan(12000, mb_strlen(implode('', $grouped[1])));
        // Excerpt order stays contiguous per document.
        self::assertStringStartsWith(str_repeat('a', 10000), implode('', $grouped[1]));
    }

    public function testAuditFixesKeepSearchAndPromptBoundariesExplicit(): void {
        $email = (string)file_get_contents(__DIR__ . '/../lib/Service/EmailService.php');
        self::assertStringContainsString("ESCAPE '!'", $email);
        self::assertStringContainsString("['!', '%', '_']", $email);
        self::assertStringContainsString("['!!', '!%', '!_']", $email);
        self::assertStringContainsString('$limit = max(1, min(100, $limit));', $email);

        $rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        self::assertStringContainsString('<personal_knowledge>', $rag);
        self::assertStringContainsString('untrusted data; use only to personalise', $rag);
        self::assertStringNotContainsString('Knowledge so far:', $rag);

        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $modelsStart = strpos($controller, 'public function models');
        self::assertNotFalse($modelsStart);
        $models = substr($controller, $modelsStart);
        self::assertStringContainsString('if ($user === null)', $models);
        self::assertStringContainsString("'Not logged in'", $models);
    }

    public function testEnrollmentStreamingAndFileContextContracts(): void {
        $config = (string)file_get_contents(__DIR__ . '/../lib/Service/AppConfig.php');
        $job = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexJob.php');
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $ollama = (string)file_get_contents(__DIR__ . '/../lib/Service/Ollama.php');
        $rag = (string)file_get_contents(__DIR__ . '/../lib/Service/RagService.php');
        $fileContext = (string)file_get_contents(__DIR__ . '/../lib/Service/FileContextChatService.php');
        self::assertStringContainsString("'index_enrolled'", $config);
        self::assertStringContainsString('enrolledUserIds', $config);
        self::assertStringContainsString('setIndexEnrolled', $config);
        self::assertStringContainsString('enrolledUserIds()', $job);
        self::assertStringContainsString('hasIndexEnrollment', $job);
        self::assertStringContainsString('isIndexEnrolled', $config);
        self::assertStringContainsString('setIndexEnrolled($user, true)', $controller);
        self::assertStringContainsString('clientDisconnected', $controller);
        self::assertStringContainsString('clientDisconnected', $ollama);
        self::assertStringContainsString('clientDisconnected', $rag);
        self::assertStringContainsString('knowledgeFor', $fileContext);
        self::assertStringContainsString('KNOWLEDGE.md', $fileContext);
        self::assertStringContainsString('selected file excerpts remain the only document evidence', $fileContext);
        self::assertStringContainsString('selected_file_excerpts', $fileContext);
    }

}
