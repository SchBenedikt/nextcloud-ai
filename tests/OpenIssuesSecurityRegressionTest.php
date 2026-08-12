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

    public function testStopIndexDetachesTheRunImmediately(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $start = strpos($controller, 'public function stopIndex');
        self::assertNotFalse($start);
        $end = strpos($controller, 'private function recoverStaleIndex', $start);
        self::assertNotFalse($end);
        $stop = substr($controller, $start, $end - $start);
        self::assertStringContainsString("set('index_running', '0')", $stop);
        self::assertStringContainsString("set('index_mode', 'idle')", $stop);
        self::assertStringContainsString("set('index_run_id', '')", $stop);
        self::assertStringContainsString("set('index_heartbeat', '')", $stop);
        self::assertStringContainsString("set('index_cancel_requested', '0')", $stop);
    }

    public function testIndexStartTreatsDuplicateRunsAsIdempotent(): void {
        $controller = (string)file_get_contents(__DIR__ . '/../lib/Controller/ApiController.php');
        $start = strpos($controller, 'private function queueIndex');
        self::assertNotFalse($start);
        $end = strpos($controller, 'private function recoverStaleIndex', $start);
        self::assertNotFalse($end);
        $queue = substr($controller, $start, $end - $start);
        self::assertStringContainsString("'alreadyRunning' => true", $queue);
        self::assertStringContainsString("'message' => 'Indexing is already running for this user.'", $queue);
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
        self::assertStringContainsString("'eva_ai/index/'", $indexer);
        self::assertStringContainsString('tryClaimIndex($userId)', $indexer);
        self::assertStringContainsString('releaseLock($lockPath', $indexer);

        $requestJob = (string)file_get_contents(__DIR__ . '/../lib/BackgroundJob/IndexRequestJob.php');
        self::assertStringContainsString('$this->indexer->run($userId', $requestJob);
    }
}
