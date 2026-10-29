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
        private ?\OCA\EvaAi\Db\GenerationVerificationRepository $generationVerifications = null
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
        if ($userId === '') {
            return;
        }
        // Chunk ownership is derived through the document join. Keep those
        // parent rows until chunk erasure succeeds, including on failure so
        // a retry can still identify every affected chunk.
        try {
            $deleteIndex = function () use ($userId): void {
                $this->chunkMapper->deleteForUser($userId);
                $this->documentMapper->deleteByUser($userId);
            };
            if ($this->vectorGenerations !== null) $this->vectorGenerations->deleteUser($userId, $deleteIndex);
            else $deleteIndex();
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: index cleanup failed on account deletion; document ownership retained for retry', ['user' => $userId]);
        }
        try {
            $this->embeddingCache->clearUser($userId);
        } catch (\Throwable $e) {
            // Non-fatal.
        }
        // AppData folders.
        try {
            $this->chatStore->deleteUserData($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: chat cleanup failed on account deletion', ['user' => $userId]);
        }
        try {
            $this->dirtyIndexStore->deleteUserData($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: dirty index cleanup failed on account deletion', ['user' => $userId]);
        }
        try {
            $this->agentStore->deleteUserData($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: agent state cleanup failed on account deletion', ['user' => $userId]);
        }
        try {
            $this->backgroundChatQueue->deleteUserData($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: background queue cleanup failed on account deletion', ['user' => $userId]);
        }
        $this->deleteAiMarksFolder($userId);
        // Do not reopen the deleted account's home. getUserFolder() can set up
        // storage or a lazy home; core account deletion owns its removal.
        // Per-user config values.
        try {
            $this->config->deleteUserValues($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: config cleanup failed on account deletion', ['user' => $userId]);
        }
    }

    private function deleteAiMarksFolder(string $userId): void {
        $ns = substr(hash('sha256', $userId), 0, 40);
        $legacy = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
        try {
            $appdata = $this->appDataFactory->get('eva_ai');
            try {
                $dir = $appdata->getFolder('ai-marks');
            } catch (NotFoundException $e) {
                return;
            }
            foreach ([$ns, $legacy] as $candidate) {
                try {
                    $dir->getFolder($candidate)->delete();
                } catch (NotFoundException $e) {
                    // No marker folder for this candidate - fine.
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: ai-marks cleanup failed on account deletion', ['user' => $userId]);
        }
    }
}
