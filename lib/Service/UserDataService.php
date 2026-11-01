<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * GDPR data-export and account-deletion cleanup (Issue #83).
 *
 * - export(): one JSON payload with the user's chats, KNOWLEDGE.md content and
 *   a metadata list of their indexed documents (no chunk text, no file bytes).
 * - cleanupDeletedAccount(): removes every eva_ai row, AppData folder and
 *   per-user config value when the account is deleted. Nextcloud owns erasure
 *   of the user's home, including KNOWLEDGE.md; EVA removes its AppData marks.
 *
 * Both paths are defensive: cleanup must never abort the account deletion.
 */
class UserDataService {
    public function __construct(
        private AppConfig $config,
        private ChatStore $chatStore,
        private DirtyIndexStore $dirtyIndexStore,
        private AgentStore $agentStore,
        private BackgroundChatQueue $backgroundChatQueue,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private EmbeddingCache $embeddingCache,
        private IRootFolder $rootFolder,
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger,
        private ?\OCA\EvaAi\Db\VectorGenerationRepository $vectorGenerations = null,
        private ?\OCA\EvaAi\Db\GenerationBuildRepository $generationBuilds = null,
        private ?\OCA\EvaAi\Db\GenerationVerificationRepository $generationVerifications = null,
        private ?\OCA\EvaAi\Db\AccountCleanupRepository $accountCleanup = null,
        private ?UsageMetrics $usageMetrics = null
    ) {
    }

    /**
     * Build the user's complete EVA data export.
     *
     * @return array{user:string,exported_at:int,chats:array,knowledge:string,index_meta:array,vector_generations:array}
     */
    public function export(string $userId): array {
        $knowledge = '';
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            if ($home->nodeExists('KNOWLEDGE.md')) {
                $node = $home->get('KNOWLEDGE.md');
                if ($node instanceof \OCP\Files\File) {
                    $knowledge = (string)$node->getContent();
                }
            }
        } catch (\Throwable $e) {
            // Knowledge is optional in the export.
        }

        $indexMeta = [];
        try {
            foreach ($this->documentMapper->findByUser($userId, null, 10000, 0) as $doc) {
                \assert($doc instanceof Document);
                $indexMeta[] = [
                    'fileId' => (int)$doc->getFileId(),
                    'path' => (string)$doc->getPath(),
                    'name' => (string)$doc->getName(),
                    'mime' => $doc->getMime(),
                    'size' => (int)$doc->getSize(),
                    'chunks' => (int)$doc->getChunkCount(),
                    'indexedAt' => $doc->getIndexedAt(),
                ];
            }
        } catch (\Throwable $e) {
            // Metadata listing must not fail the whole export.
        }

        $generationMeta = [];
        if ($this->vectorGenerations !== null) {
            $cursor = '';
            do {
                $page = $this->vectorGenerations->page($userId, $cursor);
                foreach ($page as $row) $generationMeta[] = $row;
                if ($page !== []) $cursor = $page[count($page) - 1]['id'];
            } while (count($page) === 50);
        }

        return [
            'user' => $userId,
            'exported_at' => time(),
            'chats' => $this->chatStore->exportAll($userId),
            'knowledge' => $knowledge,
            'index_meta' => $indexMeta,
            'vector_generations' => $generationMeta,
            'vector_owner_fence' => $this->vectorGenerations?->ownerMetadata($userId),
            'vector_builds' => $this->generationBuilds?->exportMetadata($userId) ?? ['builds'=>[],'documents'=>[]],
            'vector_verifications' => $this->generationVerifications?->exportMetadata($userId) ?? [],
        ];
    }

    /** Remove every eva_ai trace of a deleted account (Issue #83). */
    public function cleanupDeletedAccount(string $userId): void {
        if ($userId === '') return;
        if ($this->accountCleanup === null) {
            $this->runCleanupSteps($userId);
            return;
        }
        try {
            $owner = $this->accountCleanup->enqueue($userId, time());
            $claim = $this->accountCleanup->claimForUser($userId, time());
            if ($claim !== null) $this->processCleanupClaim($claim);
            else $this->logger->debug('eva_ai: account erasure queued for retry', ['owner_hash' => $owner]);
        } catch (\Throwable) {
            // Core account deletion must continue. Fall back to immediate
            // best-effort cleanup if the durable queue itself is unavailable.
            $this->logger->warning('eva_ai: durable account erasure could not be queued');
            $this->runCleanupSteps($userId);
        }
    }

    /** Process one durable erasure request; called by the immediate listener and cron worker. */
    public function retryPendingAccountCleanup(): bool {
        if ($this->accountCleanup === null) return false;
        $claim = $this->accountCleanup->claimNext(time());
        if ($claim === null) return false;
        $this->processCleanupClaim($claim);
        return true;
    }

    private function processCleanupClaim(array $claim): void {
        try {
            $userId = $this->accountCleanup?->decryptOwner($claim);
            if (!is_string($userId) || $userId === '') throw new \UnexpectedValueException('Invalid cleanup owner.');
            $failedStep = null;
            foreach ($this->cleanupSteps($userId) as $name => $step) {
                if (!$this->accountCleanup?->renew($claim, time())) throw new \RuntimeException('Cleanup lease was lost.');
                try { $step(); }
                catch (\Throwable $e) {
                    $failedStep ??= $name;
                }
            }
            if ($failedStep !== null) {
                $this->accountCleanup?->retry($claim, 'step_' . $failedStep, time());
                $this->logger->warning('eva_ai: account erasure steps will retry', [
                    'owner_hash' => $claim['owner_hash'] ?? '', 'step' => $failedStep,
                ]);
                return;
            }
            if (!$this->accountCleanup?->complete($claim)) throw new \RuntimeException('Cleanup lease was lost before completion.');
        } catch (\Throwable) {
            try { $this->accountCleanup?->retry($claim, 'cleanup_record_unavailable', time()); } catch (\Throwable) {}
            $this->logger->warning('eva_ai: account erasure remains queued for retry', ['owner_hash' => $claim['owner_hash'] ?? '']);
        }
    }

    /** @return array<string,callable():void> Every operation is idempotent and safe to replay. */
    private function cleanupSteps(string $userId): array {
        return [
            'index' => function () use ($userId): void {
                $deleteIndex = function () use ($userId): void {
                    $this->chunkMapper->deleteForUser($userId);
                    $this->documentMapper->deleteByUser($userId);
                };
                if ($this->vectorGenerations !== null) $this->vectorGenerations->deleteUser($userId, $deleteIndex);
                else $deleteIndex();
            },
            'embedding_cache' => fn() => $this->embeddingCache->clearUserForErasure($userId),
            'chats' => fn() => $this->chatStore->deleteUserData($userId),
            'dirty_index' => fn() => $this->dirtyIndexStore->deleteUserData($userId),
            'agent_state' => fn() => $this->agentStore->deleteUserData($userId),
            'background_chat' => fn() => $this->backgroundChatQueue->deleteUserData($userId),
            'ai_marks' => fn() => $this->deleteAiMarksFolder($userId),
            'usage_metrics' => fn() => $this->usageMetrics?->deleteUserData($userId),
            'user_config' => fn() => $this->config->deleteUserValues($userId),
        ];
    }

    /** Run every idempotent step even if one fails; the durable worker replays the full list. */
    private function runCleanupSteps(string $userId): void {
        foreach ($this->cleanupSteps($userId) as $name => $step) {
            try { $step(); }
            catch (\Throwable) { $this->logger->warning('eva_ai: account erasure step failed', ['step' => $name]); }
        }
    }

    private function deleteAiMarksFolder(string $userId): void {
        $ns = substr(hash('sha256', $userId), 0, 40);
        $legacy = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
        try { $appdata = $this->appDataFactory->get('eva_ai'); }
        catch (NotFoundException) { return; }
        try {
            $dir = $appdata->getFolder('ai-marks');
        } catch (NotFoundException) {
            return;
        }
        foreach ([$ns, $legacy] as $candidate) {
            try {
                $dir->getFolder($candidate)->delete();
            } catch (NotFoundException) {
                // No marker folder for this candidate - fine.
            }
        }
    }
}
