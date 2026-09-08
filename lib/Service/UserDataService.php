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
 *   per-user config value when the account is deleted. KNOWLEDGE.md and the
 *   ai-marks json live inside the user's own home folder / AppData, so those
 *   are also removed here where the account deletion leaves them behind.
 *
 * Both paths are defensive: cleanup must never abort the account deletion.
 */
class UserDataService {
    public function __construct(
        private AppConfig $config,
        private ChatStore $chatStore,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private EmbeddingCache $embeddingCache,
        private IRootFolder $rootFolder,
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Build the user's complete EVA data export.
     *
     * @return array{user:string,exported_at:int,chats:array,knowledge:string,index_meta:array<int,array<string,mixed>>}
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

        return [
            'user' => $userId,
            'exported_at' => time(),
            'chats' => $this->chatStore->exportAll($userId),
            'knowledge' => $knowledge,
            'index_meta' => $indexMeta,
        ];
    }

    /** Remove every eva_ai trace of a deleted account (Issue #83). */
    public function cleanupDeletedAccount(string $userId): void {
        if ($userId === '') {
            return;
        }
        // DB rows and cached vectors.
        try {
            $this->documentMapper->deleteByUser($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: document cleanup failed on account deletion', ['user' => $userId]);
        }
        try {
            $this->chunkMapper->deleteForUser($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: chunk cleanup failed on account deletion', ['user' => $userId]);
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
        $this->deleteAiMarksFolder($userId);
        // KNOWLEDGE.md inside the home folder (if the folder still exists).
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            if ($home->nodeExists('KNOWLEDGE.md')) {
                $home->get('KNOWLEDGE.md')->delete();
            }
        } catch (\Throwable $e) {
            // The home folder is often already gone at this point.
        }
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
