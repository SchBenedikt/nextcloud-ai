<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\Chunk;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class Indexer {
    /**
     * Default embedding batch size (Issue #141). Users can tune it through
     * the settings; 24 keeps memory bounded for the default models.
     */
    private const DEFAULT_BATCH = 24;
    private const MAX_DEPTH = 30;
    private const MAX_DECOMPRESSED_BYTES = 104857600; // 100MB limit for decompressed content
    private const MAX_ZIP_ENTRIES = 1000; // Maximum number of ZIP entries to process

    /**
     * Liveness heartbeats are throttled to this interval. The index loop used
     * to write the per-user heartbeat value and take the global scheduler lock
     * for every single file - a database write plus a global lock per file on a
     * library of thousands of files. Ten seconds keeps stale-run detection and
     * the admin status view accurate while removing that per-file overhead.
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 10;
    /**
     * Upper bound on the text one extraction may produce.
     *
     * A mailbox or a very long export can hold more text than the index should
     * carry; the bound keeps one oversized file from filling the chunk table and
     * slowing every later search.
     */
    private const MAX_EXTRACT_CHARS = 2000000;

    /**
     * File-id base for indexed Talk rooms.
     *
     * A room is not a file, so it is stored under a synthetic negative file id,
     * exactly like a mail message. The two producers must not share numbers: a
     * mail message id and a Talk room id are unrelated sequences that both start
     * at 1, so mail keeps `-messageId` and Talk uses this offset, which no real
     * message id reaches.
     */
    private const TALK_FILE_ID_BASE = 1000000000;

    /** Wall-clock timestamp of the last heartbeat write for this pass. */
    private int $lastHeartbeatAt = 0;

    public function __construct(
        private AppConfig $config,
        private IRootFolder $rootFolder,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private Chunker $chunker,
        private Ollama $ollama,
        private EmbeddingCache $embeddingCache,
        private EmailService $email,
        private TalkTranscriptService $talkTranscripts,
        private LoggerInterface $logger,
        private ILockingProvider $lockingProvider,
        private LockGuard $lockGuard,
        private IndexScheduler $scheduler
    ) {
    }

    /**
     * Perform one bounded indexing pass for a user.
     * @return array{processed:int,changed:int,skipped:int,failed:int,total_seen:int,cache_hits:int,cache_misses:int,ollama_requests:int,error:?string}
     */
    public function run(string $userId, ?int $maxFiles = null, string $mode = 'all', bool $keepRunning = false, ?string $runId = null): array {
        $this->config->setUserId($userId);
        $mode = in_array($mode, ['all', 'files', 'mail', 'talk'], true) ? $mode : 'all';
        $maxFiles = $maxFiles ?? min(10000, max(1, $this->config->getInt('max_files_per_run', 40)));
        $result = [
            'processed' => 0,
            'changed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total_seen' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'ollama_requests' => 0,
            'error' => null,
        ];
        // Bounded key: the full sha256 would exceed the varchar(64) key column
        // of Nextcloud's file_locks table and break acquire/release.
        $lockPath = LockGuard::indexLockPath($userId);
        try {
            // The guard reclaims an expired lock row a crashed worker left
            // behind (never a live run's lock), so an abandoned run cannot
            // permanently block indexing until a cron cleanup job runs.
            $this->lockGuard->acquireIndexLock($userId, $lockPath);
        } catch (\Throwable $e) {
            $result['error'] = 'Indexing is already running for this user.';
            return $result;
        }
        if ($runId === null) {
            // OCC/manual runs and the periodic worker share the same per-user
            // claim. A second worker exits before any DB mutation - unless the
            // first one is gone: a worker killed mid-run leaves its claim
            // behind, and without this check every later manual run and every
            // cron pass would report "already running" forever.
            if ($this->config->get('index_running') === '1' && !$this->config->recoverAbandonedRun()) {
                $result['error'] = 'Indexing is already running for this user.';
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
                return $result;
            }
            if (!$this->config->tryClaimIndex($userId)) {
                $result['error'] = 'Indexing is already running for this user.';
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
                return $result;
            }
            $this->config->setUserId($userId);
        }
        if ($runId !== null && $this->config->get('index_run_id') !== $runId) {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            return $result;
        }

        // Fair multi-user scheduling (Issue #142): the per-user claim above
        // serializes work for one account; the global slot bounds how many
        // accounts may run at the same time. When the instance limit is
        // reached the user is queued FIFO and this call returns immediately
        // with their queue position instead of competing for resources.
        $slot = $this->scheduler->acquireSlot($userId);
        if ($slot['state'] === 'queued') {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            $result['queued'] = true;
            $result['queue_position'] = $slot['position'];
            $result['error'] = null;
            return $result;
        }

        $this->config->set('index_running', '1');
        $this->config->set('index_started', (string)time());
        $this->config->set('index_heartbeat', (string)time());
        // A new pass starts with a fresh heartbeat window, so the first file
        // always refreshes liveness instead of inheriting the previous pass.
        $this->lastHeartbeatAt = 0;
        $this->config->set('last_index_error', '');
        $this->config->set('last_index_cache_hits', '0');
        $this->config->set('last_index_cache_misses', '0');
        $this->config->set('last_index_ollama_requests', '0');
        
        // Calculate the file-index configuration hash only for file passes.
        // A mail- or Talk-only request must not invalidate the user's file index.
        if ($mode === 'all' || $mode === 'files') {
            $currentConfigHash = $this->calculateConfigHash();
            $storedConfigHash = $this->config->get('index_config_hash');

            // AppConfig::get() never returns null; treat empty string as "not yet set"
            if ($storedConfigHash === '') {
                $this->config->set('index_config_hash', $currentConfigHash);
            } elseif ($storedConfigHash !== $currentConfigHash) {
                $this->logger->info('eva_ai: Configuration changed, marking index for rebuild', [
                    'oldHash' => $storedConfigHash,
                    'newHash' => $currentConfigHash,
                    'userId' => $userId
                ]);
                $this->config->set('index_config_hash', $currentConfigHash);
                // Force re-index by clearing stored hashes
                $this->documentMapper->clearHashesForUser($userId);
            }
        }

        $cancelled = false;
        try {
            if ($mode === 'all' || $mode === 'files') {
                $userFolder = $this->rootFolder->getUserFolder($userId);
            $scope = $this->config->get('scope_path');
            $root = $userFolder;
            if ($scope !== '') {
                try {
                    $node = $userFolder->get($scope);
                    if ($node instanceof Folder) {
                        $root = $node;
                    } else {
                        // Path exists but is not a folder - this is a configuration error
                        $result['error'] = 'Scope path must be a folder, but it points to a file: /' . $scope;
                        $this->logger->error('eva_ai: Invalid scope path - not a folder', ['scope' => $scope, 'userId' => $userId]);
                        return $result;
                    }
                } catch (NotFoundException $e) {
                    $result['error'] = 'Scope path not found: /' . $scope;
                    return $result;
                }
            }

            $excludePaths = $this->parseExcludePaths();

            // One bulk read supplies both the change fingerprints and the
            // stored metadata, so a full scan no longer issues a query per file.
            $docState = $this->documentMapper->stateForUser($userId);
            $hashes = [];
            foreach ($docState as $stateFileId => $stateRow) {
                $hashes[$stateFileId] = $stateRow['content_hash'];
            }
            $seen = [];
            $stale = []; // Track files that should be removed from index
            $batch = [];
            $maxSize = $this->config->getInt('max_file_size', 20971520);
            // Configurable bounded embedding batch (Issue #141): memory stays
            // bounded independently of the library size, and operators can
            // tune throughput vs. memory on weak hardware.
            $batchSize = min(200, max(1, $this->config->getInt('embed_batch_size', self::DEFAULT_BATCH)));
            // Whether the filesystem walk completed fully. A bounded pass that
            // stops early (max_files_per_run reached) has an incomplete $seen
            // set and must NOT trigger deletion of 'missing' files (Issue #6).
            $completed = true;

            // Stream files via generator instead of loading all into memory
            foreach ($this->collectFilesGenerator($root, 0, $root === $userFolder ? '' : $scope, $excludePaths) as $fileData) {
                if ($this->cancellationRequested($runId)) {
                    $cancelled = true;
                    $completed = false;
                    break;
                }
                $this->maybeTouchHeartbeat($userId, $runId);
                $fileId = (int)$fileData['id'];
                $seen[$fileId] = true;
                $result['total_seen']++;

                // Create a lightweight file-like object for compatibility
                $file = new class($fileData) {
                    private array $data;
                    public function __construct(array $data) {
                        $this->data = $data;
                    }
                    public function getId() { return $this->data['id']; }
                    public function getPath() { return $this->data['path']; }
                    public function getName() { return $this->data['name']; }
                    public function getSize() { return $this->data['size']; }
                    public function getMimeType() { return $this->data['mime']; }
                    public function getContent() {
                        // This won't be used for the lightweight approach
                        // We'll get the actual file when needed
                        return '';
                    }
                };

                if (!$this->isIndexable($file, $maxSize)) {
                    $result['skipped']++;
                    // If this file was previously indexed, mark it as stale for removal
                    if (isset($hashes[$fileId])) {
                        $stale[$fileId] = true;
                    }
                    continue;
                }

                // The walk already carries path, name, mime, size and mtime,
                // so the stored fingerprint can be checked *before* the file
                // node is fetched. On a settled library nearly every file is
                // unchanged, and this removes one getById() database read per
                // file - the dominant per-file cost of a full scan.
                $path = $this->relativePath($userId, $file->getPath());
                $name = $file->getName();
                $mime = $file->getMimeType();
                $size = $file->getSize();
                $fileMtime = (int)($fileData['mtime'] ?? 0);

                // Stored state comes from the one bulk read that opened this
                // pass, so discovering "unchanged" costs no query at all.
                $state = $docState[$fileId] ?? null;
                // The entity is fetched only when something really changed and
                // the old row has to be replaced (preserving it on failure).
                $existingDoc = null;
                $oldDocId = $state !== null ? $state['id'] : null;

                // Fast path: a file whose mtime AND size match the stored
                // fingerprint is unchanged, so skip the file fetch, the content
                // read, the parser and embedding entirely. Renames and touches
                // keep mtime, so the metadata-only refresh below still keeps the
                // stored path/name current. The content hash stays the authority
                // for everything that changed. (A write within the same second
                // that leaves both values identical would be missed; the next
                // mtime-changing write repairs it.)
                if ($state !== null
                    && $state['content_hash'] !== ''
                    && $state['size'] === $size
                    && $state['file_mtime'] === $fileMtime
                    && $fileMtime > 0) {
                    // A settled library re-scans with zero writes. Only a rename
                    // or a mime change actually needs to reach the database.
                    if ($state['path'] !== $path || $state['name'] !== $name || $state['mime'] !== $mime) {
                        $existingDoc = $this->documentMapper->findByUserAndFile($userId, $fileId);
                        if ($existingDoc !== null) {
                            $existingDoc->setPath($path);
                            $existingDoc->setName($name);
                            $existingDoc->setMime($mime);
                            $existingDoc->setSize($size);
                            $existingDoc->setFileMtime($fileMtime);
                            $existingDoc->setIndexedAt(time());
                            $this->documentMapper->update($existingDoc);
                        }
                        $docState[$fileId]['path'] = $path;
                        $docState[$fileId]['name'] = $name;
                        $docState[$fileId]['mime'] = $mime;
                    }
                    $result['skipped']++;
                    continue;
                }

                try {
                    // Only a file that really changed is fetched for content.
                    $actualFile = $root->getById($fileId);
                    if (empty($actualFile) || !($actualFile[0] instanceof File)) {
                        $result['skipped']++;
                        // If this file was previously indexed, mark it as stale for removal
                        if (isset($hashes[$fileId])) {
                            $stale[$fileId] = true;
                        }
                        continue;
                    }
                    $actualFile = $actualFile[0];
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai: file access failed', ['file' => $file->getPath(), 'e' => $e->getMessage()]);
                    $result['skipped']++;
                    // Access loss is authoritative and must purge cached content.
                    if (isset($hashes[$fileId])) {
                        $stale[$fileId] = true;
                    }
                    continue;
                }

                try {
                    $content = $this->extractText($actualFile);
                } catch (\Throwable $e) {
                    // A single unreadable file must never end the pass. Count it
                    // as skipped (not as a run error) so the background index
                    // keeps going and the admin page does not report a failure;
                    // the last-good index entry is preserved and retried later.
                    $result['skipped']++;
                    $result['failed']++;
                    $this->logger->warning('eva_ai: extraction failed; preserving previous index', ['file' => $file->getPath(), 'e' => $e->getMessage()]);
                    continue;
                }
                if ($content === '') {
                    $result['skipped']++;
                    // A genuinely zero-byte file is authoritative empty input;
                    // parser failures on non-empty files preserve last-good data.
                    if ((int)$file->getSize() === 0 && isset($hashes[$fileId])) {
                        $stale[$fileId] = true;
                    }
                    continue;
                }

                $hash = md5($content);
                if (($hashes[$fileId] ?? null) === $hash) {
                    // Same content (e.g. a touch or a same-size edit): keep the
                    // stored chunks and refresh metadata so renames propagate.
                    $result['skipped']++;
                    $existingDoc ??= $this->documentMapper->findByUserAndFile($userId, $fileId);
                    if ($existingDoc !== null) {
                        $existingDoc->setPath($path);
                        $existingDoc->setName($name);
                        $existingDoc->setMime($mime);
                        $existingDoc->setSize($size);
                        $existingDoc->setFileMtime($fileMtime);
                        $existingDoc->setIndexedAt(time());
                        $this->documentMapper->update($existingDoc);
                    }
                    continue;
                }

                $chunks = $this->chunker->chunk($content);
                if (empty($chunks)) {
                    $result['skipped']++;
                    continue;
                }

                $doc = new Document();
                $doc->setUserId($userId);
                $doc->setFileId($fileId);
                $doc->setPath($path);
                $doc->setName($name);
                $doc->setMime($mime);
                $doc->setSize($size);
                $doc->setFileMtime($fileMtime);
                $doc->setContentHash($hash);
                $doc->setChunkCount(count($chunks));
                $doc->setIndexedAt(time());
                $this->documentMapper->insert($doc);

                foreach ($chunks as $i => $c) {
                    $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens'], 'provenance' => $c['provenance'] ?? [], 'oldDocId' => $oldDocId, 'path' => $path];
                }

                $result['processed']++;
                $result['changed']++;

                if (count($batch) >= $batchSize || $result['processed'] >= $maxFiles) {
                    $this->flushBatch($batch, $result, $runId, $userId);
                }
                if ($result['processed'] >= $maxFiles) {
                    $completed = false;
                    break;
                }
            }

            if ($this->cancellationRequested($runId)) {
                $cancelled = true;
            }
            $this->flushBatch($batch, $result, $runId, $userId);
            // A stop may have arrived during embedding; re-check before any
            // cleanup or mail work so cancellation cannot trigger more writes.
            if ($this->cancellationRequested($runId)) {
                $cancelled = true;
            }
            if (!$cancelled && ($mode === 'all' || $mode === 'files')) {
                if ($completed) {
                    // Full traversal: safe to remove files that are no longer
                    // present. With a bounded pass that ended early the seen set
                    // is incomplete and must not trigger deletions (Issue #6).
                    $this->cleanupRemoved($userId, $seen, $stale);
                } else {
                    // Config-based exclusions are still safe on partial passes.
                    $this->cleanupExcluded($userId);
                }
            }
            }
            if (!$cancelled && ($mode === 'all' || $mode === 'mail')) {
                $this->indexEmails($userId, $result, $maxFiles, $mode === 'mail', $runId);
            }
            if (!$cancelled && ($mode === 'all' || $mode === 'talk')) {
                $this->indexTalkRooms($userId, $result, $runId, $mode === 'talk');
            }

            if ($result['error'] === null && $result['processed'] === 0 && $result['total_seen'] > 0) {
                $result['error'] = null; // up to date
            }
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai index run failed', ['exception' => $e]);
            $result['error'] = $e->getMessage();
        } finally {
            try {
                // Persist the terminal state while the per-user lock is still
                // held. Otherwise a new run could start between lock release
                // and these writes and be overwritten by the older worker.
                $ownsRun = $runId === null || $this->config->get('index_run_id') === $runId;
                if ($ownsRun) {
                    if (!$keepRunning) {
                        $this->config->set('index_running', '0');
                        $this->config->set('index_finished', (string)time());
                        $this->config->set('index_heartbeat', '');
                    }
                    $this->config->set('last_index_processed', (string)$result['processed']);
                    $this->config->set('last_index_total', (string)$result['total_seen']);
                    $this->config->set('last_index_failed', (string)($result['failed'] ?? 0));
                    $this->config->set('last_index_cache_hits', (string)$result['cache_hits']);
                    $this->config->set('last_index_cache_misses', (string)$result['cache_misses']);
                    $this->config->set('last_index_ollama_requests', (string)$result['ollama_requests']);
                    if ($result['error'] !== null) {
                        $this->config->set('last_index_error', $result['error']);
                    }
                }
            } finally {
                $this->scheduler->releaseSlot($userId);
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            }
        }

        return $result;
    }

    /**
     * Incremental single-file update triggered by a filesystem hook (Issue #79).
     * Reuses the same extraction/chunking/embedding path as run(), but only
     * touches the one file. Applies the user's scope, exclusions and the size
     * cap; a file that is gone, out of scope or no longer indexable purges its
     * stale index rows. Skips without error when a full index run holds the
     * per-user lock (the periodic job reconciles that file later).
     *
     * @return array{processed:int,changed:int,skipped:int,deleted:int,error:?string}
     */
    public function reindexFile(string $userId, int $fileId): array {
        $result = [
            'processed' => 0,
            'changed' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'ollama_requests' => 0,
            'error' => null,
        ];
        $lockPath = LockGuard::indexLockPath($userId);
        try {
            $this->lockGuard->acquireIndexLock($userId, $lockPath);
        } catch (\Throwable $e) {
            // A full index run is in progress; it will pick this file up.
            $result['error'] = 'Indexing is already running for this user.';
            return $result;
        }
        try {
            $this->config->setUserId($userId);
            // Respect the per-user enrollment opt-out (Issue #49).
            if ($this->config->hasIndexEnrollment($userId) && !$this->config->isIndexEnrolled($userId)) {
                return $result;
            }
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes) || !($nodes[0] instanceof File)) {
                // The file was deleted or moved out of reach: purge cached rows.
                $this->purgeUserFile($userId, $fileId);
                $result['deleted'] = 1;
                return $result;
            }
            $file = $nodes[0];
            $rel = $this->relativePath($userId, $file->getPath());

            $scope = $this->config->get('scope_path');
            if ($scope !== '' && $rel !== $scope && !str_starts_with($rel, $scope . '/')) {
                $this->purgeUserFile($userId, $fileId);
                $result['deleted'] = 1;
                return $result;
            }
            if ($this->isPathExcluded($rel, $this->parseExcludePaths())) {
                $this->purgeUserFile($userId, $fileId);
                $result['deleted'] = 1;
                return $result;
            }

            $maxSize = $this->config->getInt('max_file_size', 20971520);
            if (!$this->isIndexable($file, $maxSize)) {
                // Oversized/unsupported: drop a previously cached version.
                if ($this->documentMapper->findByUserAndFile($userId, $fileId) !== null) {
                    $this->purgeUserFile($userId, $fileId);
                    $result['deleted'] = 1;
                } else {
                    $result['skipped']++;
                }
                return $result;
            }

            try {
                $content = $this->extractText($file);
            } catch (\Throwable $e) {
                // Transient parser failure: keep the last-good version.
                $result['error'] = 'Transient extraction failure';
                $result['skipped']++;
                return $result;
            }
            if ($content === '') {
                if ((int)$file->getSize() === 0 && $this->documentMapper->findByUserAndFile($userId, $fileId) !== null) {
                    $this->purgeUserFile($userId, $fileId);
                    $result['deleted'] = 1;
                } else {
                    $result['skipped']++;
                }
                return $result;
            }

            $hash = md5($content);
            $existing = $this->documentMapper->findByUserAndFile($userId, $fileId);
            if ($existing !== null && (string)$existing->getContentHash() === $hash) {
                // Same content (e.g. a rename or touch): refresh metadata only.
                $existing->setPath($rel);
                $existing->setName($file->getName());
                $existing->setMime($file->getMimeType());
                $existing->setSize($file->getSize());
                $existing->setFileMtime((int)$file->getMTime());
                $existing->setIndexedAt(time());
                $this->documentMapper->update($existing);
                $result['skipped']++;
                return $result;
            }

            $chunks = $this->chunker->chunk($content);
            if (empty($chunks)) {
                $result['skipped']++;
                return $result;
            }

            $oldDocId = $existing !== null ? (int)$existing->getId() : null;
            $doc = new Document();
            $doc->setUserId($userId);
            $doc->setFileId($fileId);
            $doc->setPath($rel);
            $doc->setName($file->getName());
            $doc->setMime($file->getMimeType());
            $doc->setSize($file->getSize());
            $doc->setFileMtime((int)$file->getMTime());
            $doc->setContentHash($hash);
            $doc->setChunkCount(count($chunks));
            $doc->setIndexedAt(time());
            $this->documentMapper->insert($doc);

            $batch = [];
            foreach ($chunks as $i => $c) {
                $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens'], 'provenance' => $c['provenance'] ?? [], 'oldDocId' => $oldDocId];
            }
            $this->flushBatch($batch, $result);
            $result['processed']++;
            $result['changed']++;
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai incremental reindex failed', [
                'userId' => $userId,
                'fileId' => $fileId,
                'exception' => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
            return $result;
        } finally {
            try {
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // Lock may already be gone; nothing to recover here.
            }
        }
    }

    /**
     * Purge every indexed document at/under a deleted folder path (Issue #79).
     */
    public function deleteByPathPrefix(string $userId, string $path): int {
        $docs = $this->documentMapper->findByUserAndPathPrefix($userId, $path);
        if ($docs === []) {
            return 0;
        }
        $ids = array_map(static fn($d) => (int)$d->getId(), $docs);
        $this->chunkMapper->deleteByDocumentIds($ids);
        $this->documentMapper->deleteByIds($ids);
        return count($ids);
    }

    /**
     * Re-point stored document paths after a folder rename (Issue #79).
     * Runs in PHP (not SQL string surgery) so path separators and LIKE
     * wildcards in folder names are handled safely.
     */
    public function updatePathPrefix(string $userId, string $oldPrefix, string $newPrefix): int {
        $docs = $this->documentMapper->findByUserAndPathPrefix($userId, $oldPrefix);
        $updated = 0;
        foreach ($docs as $doc) {
            $old = (string)$doc->getPath();
            $doc->setPath($newPrefix . substr($old, strlen($oldPrefix)));
            $this->documentMapper->update($doc);
            $updated++;
        }
        return $updated;
    }

    /**
     * Remove the document row and all of its chunks for one user+file.
     */
    private function purgeUserFile(string $userId, int $fileId): void {
        $doc = $this->documentMapper->findByUserAndFile($userId, $fileId);
        if ($doc === null) {
            return;
        }
        $this->chunkMapper->deleteByDocument((int)$doc->getId());
        $this->documentMapper->deleteByUserAndFile($userId, $fileId);
    }

    /**
     * Generator-based file discovery: yields lightweight file metadata arrays
     * one at a time instead of loading the complete tree into memory.
     * Enables processing large file trees without materializing all File objects.
     */
    private function collectFilesGenerator(Folder $folder, int $depth, string $relativePath, array $excludePaths): \Generator {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        // Directory listing order is provider-dependent and sorting each
        // folder separately is not enough: an old root folder could hide a
        // newer file in a later subtree. Collect only lightweight metadata,
        // then sort the complete candidate set globally by modification time.
        // File contents are still read and embedded one at a time below.
        $files = [];
        $collect = function (Folder $current, int $currentDepth, string $currentPath) use (&$collect, &$files, $excludePaths): void {
            if ($currentDepth > self::MAX_DEPTH) {
                return;
            }
            $nodes = $current->getDirectoryListing();
            foreach ($nodes as $node) {
                if ($node instanceof Folder) {
                    $name = $node->getName();
                    if (in_array($name, ['Thumbnails', '.appdata'], true) || str_starts_with($name, '.')) {
                        continue;
                    }
                    $childPath = $currentPath === '' ? $name : $currentPath . '/' . $name;
                    if ($this->isPathExcluded($childPath, $excludePaths)) {
                        continue;
                    }
                    $collect($node, $currentDepth + 1, $childPath);
                } elseif ($node instanceof File && !str_starts_with($node->getName(), '.')) {
                    $files[] = [
                        'id' => $node->getId(),
                        'path' => $node->getPath(),
                        'name' => $node->getName(),
                        'size' => $node->getSize(),
                        'mime' => $node->getMimeType(),
                        'mtime' => method_exists($node, 'getMTime') ? (int)$node->getMTime() : 0,
                    ];
                }
            }
        };
        $collect($folder, $depth, $relativePath);
        usort($files, static function (array $a, array $b): int {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcasecmp((string)$a['path'], (string)$b['path']);
        });
        foreach ($files as $file) {
            yield $file;
        }
        return;
    }

    /**
     * Parse the comma-separated exclude_paths config into an array of
     * normalized lower-case paths (without leading/trailing slashes).
     * @return string[]
     */
    private function parseExcludePaths(): array {
        $raw = trim($this->config->get('exclude_paths'));
        if ($raw === '') {
            return [];
        }
        $paths = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p, " \t\n\r\0\x0B/");
            if ($p !== '') {
                // Normalize to lowercase for consistent comparison
                $paths[] = strtolower($p);
            }
        }
        return $paths;
    }

    /**
     * Check whether a relative file/folder path should be excluded.
     * Matches exact path and prefix (children of excluded folders).
     */
    private function isPathExcluded(string $relativePath, array $excludePaths): bool {
        if ($excludePaths === []) {
            return false;
        }
        $lower = strtolower($relativePath);
        foreach ($excludePaths as $excluded) {
            if ($lower === $excluded || str_starts_with($lower, $excluded . '/')) {
                return true;
            }
        }
        return false;
    }

    private function isIndexable($file, int $maxSize): bool {
        if ($file->getSize() > $maxSize) {
            return false;
        }
        $mime = $file->getMimeType();
        return $this->isTextMime($mime, $file->getName());
    }

    private function isTextMime(?string $mime, string $name = ''): bool {
        if ($mime === null) {
            return false;
        }
        if (in_array($mime, ['image/png', 'image/jpeg', 'image/tiff', 'image/webp'], true) && $this->config->get('ocr_enabled') === '1') return true;
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        $allowed = [
            'application/json', 'application/xml', 'application/x-empty',
            // Mail and notebook exports: text lives inside a container, so the
            // MIME type alone would exclude them.
            'message/rfc822', 'application/mbox', 'application/x-mimearchive',
            'multipart/related', 'application/x-ipynb+json', 'application/vnd.jupyter',
            'application/javascript', 'application/x-javascript', 'application/x-httpd-php',
            'application/sql', 'application/x-sql', 'application/yaml', 'application/x-yaml',
            'application/csv', 'application/rtf', 'application/x-latex', 'application/toml',
            'application/x-subrip', 'application/x-ndjson', 'application/x-toml',
            'application/x-httpd-php-source', 'application/x-python', 'application/x-shellscript',
            'application/x-tex', 'application/x-perl', 'application/x-ruby',
            // Office-/Dokument-Formate (Text wird on the fly extrahiert)
            'application/pdf',
            'application/msword',
            'application/vnd.ms-word',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.presentationml.template',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'application/epub+zip',
        ];
        if (in_array($mime, $allowed, true)) {
            return true;
        }
        // Fallback: bekannte Text-Erweiterungen, deren MIME nicht text/* ist
        // (z.B. svg -> image/svg+xml, md -> application/octet-stream, ...)
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::TEXT_EXT, true);
    }

    /** Erweiterungen, die bedenkenlos als Text gelesen werden dürfen. */
    private const TEXT_EXT = [
        'md', 'markdown', 'txt', 'text', 'log',
        'mdx', 'patch', 'diff', 'rss', 'atom', 'kml', 'gpx', 'json5', 'hjson',
        // Mail, web archive and mailbox containers.
        'eml', 'mht', 'mhtml', 'mbox',
        'tf', 'tfvars', 'hcl', 'nix', 'proto', 'graphql', 'gql', 'ipynb',
        'svelte', 'astro', 'dart', 'v', 'sv', 'vhdl', 'pas', 'd', 'jl',
        'cmake', 'gradle', 'properties', 'bak', 'old', 'sample', 'example',
        'html', 'htm', 'xhtml', 'xml', 'json', 'jsonl', 'yaml', 'yml', 'csv', 'tsv',
        'rtf', 'tex', 'bib', 'rst', 'adoc', 'org', 'vtt', 'srt', 'toml', 'ini', 'cfg',
        'conf', 'properties', 'env', 'webmanifest', 'svg', 'css', 'scss', 'less', 'sass',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'py', 'pyw', 'rb', 'php', 'phtml',
        'sh', 'bash', 'zsh', 'fish', 'expect', 'ps1', 'bat', 'cmd', 'sql',
        'c', 'h', 'cpp', 'hpp', 'cc', 'cxx', 'cs', 'java', 'kt', 'kts', 'go', 'rs',
        'swift', 'pl', 'pm', 'lua', 'r', 'm', 'mm', 'scala', 'groovy', 'shader',
        'erl', 'ex', 'exs', 'clj', 'cljs', 'hs', 'fs', 'fsx', 'ml', 'nim', 'zig',
        'dockerfile', 'makefile', 'gemfile', 'rakefile', 'procfile',
    ];


    /**
     * Liefert den durchsuchbaren Text zu einem File – auch aus PDF/DOCX/ODT/RTF/HTML.
     */
    public function extractTextForAgent(File $file, int $maxChars = 100000): string {
        $maxChars = max(1, min(100000, $maxChars));
        return mb_substr($this->extractText($file), 0, $maxChars);
    }

    private function extractText(File $file): string {
        $mime = $file->getMimeType() ?? '';
        $name = strtolower($file->getName());

        if (str_starts_with($mime, 'text/')) {
            $raw = (string)$file->getContent();
            if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || str_ends_with($name, '.html') || str_ends_with($name, '.htm')) {
                return $this->htmlText($raw);
            }
            // text/* bytes can still be a non-UTF-8 encoding in practice.
            return $this->normalize($this->toUtf8($raw ?? ''));
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Saved mail (.eml), a web archive (.mhtml/.mht) and a mailbox (.mbox).
        // All three are MIME containers, so one reader covers them.
        if (in_array($ext, ['eml', 'mht', 'mhtml', 'mbox'], true)
            || in_array($mime, ['message/rfc822', 'application/mbox', 'application/x-mimearchive', 'multipart/related'], true)) {
            return $ext === 'mbox' || $mime === 'application/mbox'
                ? $this->mboxText((string)$file->getContent())
                : $this->emlText((string)$file->getContent());
        }

        // Jupyter notebooks: cells in JSON, markdown and code kept apart.
        if ($ext === 'ipynb' || in_array($mime, ['application/x-ipynb+json', 'application/vnd.jupyter'], true)) {
            $text = $this->notebookText((string)$file->getContent());
            if (trim($text) !== '') {
                return $this->normalize($text);
            }
            // A notebook that does not parse is not worth a second attempt.
            return '';
        }

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/tiff', 'image/webp'], true) && $this->config->get('ocr_enabled') === '1') {
            return (new OcrService())->extract((string)$file->getContent(), $mime, $this->config->get('ocr_language'));
        }
        // DOCX + Varianten (DOTX/DOCM) / ODT / EPUB / ODS / ODP: Zip-Container.
        if (in_array($ext, ['docx', 'docm', 'dotx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.template') {
            return $this->zipWebText($file, 'docx');
        }
        if (in_array($ext, ['odt', 'ods', 'odp'], true) || $mime === 'application/vnd.oasis.opendocument.text' || $mime === 'application/vnd.oasis.opendocument.spreadsheet' || $mime === 'application/vnd.oasis.opendocument.presentation') {
            return $this->zipWebText($file, 'odf');
        }
        if ($ext === 'epub' || $mime === 'application/epub+zip') {
            return $this->zipWebText($file, 'epub');
        }

        // Excel (+ XLSM/XLTX): Text steht in xl/sharedStrings.xml (alle Zellinhalte).
        if (in_array($ext, ['xlsx', 'xlsm', 'xltx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.template') {
            return $this->zipWebText($file, 'xlsx');
        }

        // PowerPoint (+ PPTM/PPSX/POTX): Text aus den Folien (a:t-Tags).
        if (in_array($ext, ['pptx', 'pptm', 'ppsx', 'potx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || $mime === 'application/vnd.openxmlformats-officedocument.presentationml.template') {
            return $this->zipWebText($file, 'pptx');
        }

        // RTF: Steuerwörter entfernen / Binary-Steuerzeichen.
        if ($ext === 'rtf' || $mime === 'application/rtf') {
            $raw = (string)$file->getContent();
            $txt = preg_replace('/\\\\[a-z]+\d* ?/', ' ', $raw);
            $txt = preg_replace('/[{}]/', ' ', $txt ?? '');
            $txt = preg_replace('/\\u(\d+)/', '', $txt ?? '');
            return $this->normalize($txt ?? '');
        }

        // Legacy binary Office (.doc/.xls/.ppt): these have no embedded XML
        // text. Convert headless with LibreOffice when it is available;
        // otherwise skip with a logged reason so the gap is never silent.
        if (in_array($ext, ['doc', 'dot', 'xls', 'xla', 'ppt', 'pps', 'pot'], true)
            || $mime === 'application/msword'
            || $mime === 'application/vnd.ms-excel'
            || $mime === 'application/vnd.ms-powerpoint') {
            $txt = $this->legacyOfficeText($file, $ext);
            if ($txt !== '') {
                return $this->normalize($txt);
            }
            return '';
        }

        // Nach Office: weitere bekannte Text-Erweiterungen direkt lesen (Binary-Guard).
        if (in_array($ext, self::TEXT_EXT, true)) {
            $raw = (string)$file->getContent();
            if (strpos($raw, "\0") !== false) {
                return '';
            }
            return $this->normalize($raw);
        }

        // PDF via pdftotext, wenn Poppler verfügbar ist.
        if ($ext === 'pdf' || $mime === 'application/pdf') {
            $txt = $this->pdfToText($file);
            if (trim((string)$txt) === '' && $this->config->get('ocr_enabled') === '1') {
                $txt = (new OcrService())->extract((string)$file->getContent(), 'application/pdf', $this->config->get('ocr_language'));
            }
            return $this->normalize((string)$txt);
        }

        // Unbekannte Formate: nur lesen, wenn offensichtlich Text (kein \0).
        $raw = (string)$file->getContent();
        if (strpos($raw, "\0") !== false) {
            return '';
        }
        return $this->normalize($raw);
    }

    /**
     * Extract text from a raw content blob (Issue #64: mail attachments).
     * Handles plain text and PDF; other formats are skipped gracefully.
     */
    private function extractTextFromBlob(string $blob, string $mime): string {
        $lower = strtolower($mime);

        // Plain text types — return directly.
        if (str_starts_with($lower, 'text/')) {
            $raw = $blob;
            if ($lower === 'text/html' || $lower === 'application/xhtml+xml') {
                return $this->htmlText($raw);
            }
            return $this->normalize($raw ?? '');
        }

        // PDF via pdftotext.
        if ($lower === 'application/pdf') {
            $txt = $this->pdfToTextFromBlob($blob);
            if ($txt !== null) {
                return $this->normalize($txt);
            }
            return '';
        }

        // Other binary formats: skip (office docs, images, etc.).
        return '';
    }

    /**
     * Extract text from a PDF content blob via pdftotext.
     */
    private function pdfToTextFromBlob(string $blob): ?string {
        $bin = trim((string)(shell_exec('command -v pdftotext 2>/dev/null') ?: ''));
        if ($bin === '') {
            return null;
        }
        $tmpIn = tempnam(sys_get_temp_dir(), 'eva_pdf_');
        if ($tmpIn === false) {
            return null;
        }
        try {
            file_put_contents($tmpIn, $blob);
            // -layout keeps column/table structure instead of one
            // undifferentiated text run, which measurably improves
            // retrieval on tabular PDFs.
            $output = shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($tmpIn) . ' - 2>/dev/null');
            return (is_string($output) && trim($output) !== '') ? $output : null;
        } finally {
            @unlink($tmpIn);
        }
    }

    /**
     * Shared, bounded reader for one ZIP entry: guards the raw size of each
     * entry and the total decompressed budget across a whole container, so
     * extraction stays memory-bounded even for hostile archives.
     */
    private function zipReadEntry(\ZipArchive $zip, int $index, int &$total, bool &$aborted): string {
        if ($aborted) {
            return '';
        }
        $stat = $zip->statIndex($index);
        if ($stat === false) {
            return '';
        }
        if ($stat['size'] > self::MAX_DECOMPRESSED_BYTES) {
            $this->logWarning('eva_ai: ZIP entry too large, skipping', ['entry' => (string)($stat['name'] ?? $index), 'size' => $stat['size']]);
            return '';
        }
        $data = $zip->getFromIndex($index);
        if ($data === false) {
            return '';
        }
        $total += strlen($data);
        if ($total > self::MAX_DECOMPRESSED_BYTES) {
            $this->logWarning('eva_ai: Decompressed content exceeds limit', ['bytes' => $total]);
            $aborted = true;
            return '';
        }
        return $data;
    }

    private function logWarning(string $message, array $context = []): void {
        if (isset($this->logger)) {
            $this->logger->warning($message, $context);
        }
    }

    /**
     * Splits one XML part on its paragraph element and collects the inline
     * text runs, one line per paragraph. Text boxes and table cells live
     * inside those paragraphs, so their content is captured as well.
     */
    private function paragraphText(string $xml, string $pTag, string $tTag): string {
        $lines = [];
        $parts = preg_split('/<' . preg_quote($pTag, '/') . '(?:\s[^>]*)?>/', $xml);
        if (!is_array($parts)) {
            return '';
        }
        foreach ($parts as $i => $segment) {
            if ($i === 0) {
                continue;
            }
            if (preg_match_all('/<' . preg_quote($tTag, '/') . '(?:[^>]*)>(.*?)<\/' . preg_quote($tTag, '/') . '>/s', $segment, $m)) {
                $line = trim(html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        return implode("\n", $lines);
    }

    /**
     * DOCX: the body first, then comments, endnotes, footnotes, headers and
     * footers. Section labels keep the extracted structure clear, so chunks
     * never silently mix header, footnote and body text.
     */
    private function docxZipText(\ZipArchive $zip, int &$total, bool &$aborted): string {
        $sections = [
            'word/document.xml' => '',
            'word/comments.xml' => "[Comments]\n",
            'word/endnotes.xml' => "[Endnotes]\n",
            'word/footnotes.xml' => "[Footnotes]\n",
        ];
        $headers = [];
        $footers = [];
        $count = $zip->numFiles;
        for ($i = 0; $i < $count && !$aborted; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $lower = strtolower($name);
            if (preg_match('~^word/header\d+\.xml$~', $lower)) {
                $headers[$lower] = $i;
            } elseif (preg_match('~^word/footer\d+\.xml$~', $lower)) {
                $footers[$lower] = $i;
            }
        }
        $parts = [];
        foreach ($sections as $entry => $label) {
            $idx = $zip->locateName($entry);
            if ($idx === false) {
                continue;
            }
            $text = $this->paragraphText($this->zipReadEntry($zip, $idx, $total, $aborted), 'w:p', 'w:t');
            if ($text !== '') {
                $parts[] = $label . $text;
            }
        }
        ksort($headers);
        foreach ($headers as $i) {
            $text = $this->paragraphText($this->zipReadEntry($zip, $i, $total, $aborted), 'w:p', 'w:t');
            if ($text !== '') {
                $parts[] = "[Header]\n" . $text;
            }
        }
        ksort($footers);
        foreach ($footers as $i) {
            $text = $this->paragraphText($this->zipReadEntry($zip, $i, $total, $aborted), 'w:p', 'w:t');
            if ($text !== '') {
                $parts[] = "[Footer]\n" . $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Sheet name resolution for XLSX: workbook.xml lists the sheets and the
     * rels file maps each relationship id to its worksheet part.
     *
     * @return array<string, string> worksheet path (lower-case) => sheet name
     */
    private function xlsxSheetNames(\ZipArchive $zip, int &$total, bool &$aborted): array {
        $rels = [];
        $idx = $zip->locateName('xl/_rels/workbook.xml.rels');
        if ($idx !== false) {
            $xml = $this->zipReadEntry($zip, $idx, $total, $aborted);
            if (preg_match_all('/<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"[^>]*>/s', $xml, $rm, PREG_SET_ORDER)) {
                foreach ($rm as $r) {
                    if (str_contains($r[2], 'worksheets/')) {
                        $rels[$r[1]] = $r[2];
                    }
                }
            }
        }
        $names = [];
        $idx = $zip->locateName('xl/workbook.xml');
        if ($idx !== false) {
            $xml = $this->zipReadEntry($zip, $idx, $total, $aborted);
            if (preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"[^>]*\br:id="([^"]+)"[^>]*>/s', $xml, $sm, PREG_SET_ORDER)) {
                foreach ($sm as $s) {
                    $target = ltrim($rels[$s[2]] ?? '', '/');
                    if ($target === '') {
                        continue;
                    }
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    $names[strtolower($target)] = html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
        return $names;
    }

    /**
     * XLSX: every non-empty cell (shared strings and inline strings alike)
     * with its reference, grouped by sheet, so spreadsheets keep their
     * structure instead of one flat string list.
     */
    private function xlsxZipText(\ZipArchive $zip, int &$total, bool &$aborted): string {
        $shared = [];
        $idx = $zip->locateName('xl/sharedStrings.xml');
        if ($idx !== false) {
            $xml = $this->zipReadEntry($zip, $idx, $total, $aborted);
            if (preg_match_all('/<si>(.*?)<\/si>/s', $xml, $si)) {
                foreach ($si[1] as $cell) {
                    preg_match_all('/<t(?:[^>]*)>(.*?)<\/t>/s', $cell, $tm);
                    $shared[] = trim(html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
        }
        $names = $this->xlsxSheetNames($zip, $total, $aborted);
        $sheets = [];
        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('~^xl/worksheets/sheet(\d+)\.xml$~', $name, $nm)) {
                $sheets[(int)$nm[1]] = [$name, $i];
            }
        }
        ksort($sheets);
        $out = [];
        foreach ($sheets as $num => $pair) {
            $path = $pair[0];
            $xml = $this->zipReadEntry($zip, $pair[1], $total, $aborted);
            if ($xml === '') {
                continue;
            }
            $cellLines = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $xml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cell) {
                    $attrs = $cell[1];
                    $ref = '';
                    if (preg_match('/\br="([A-Z]+\d+)"/', $attrs, $rm)) {
                        $ref = $rm[1];
                    }
                    if ($ref === '') {
                        continue;
                    }
                    $val = '';
                    if (preg_match('/\bt="inlineStr"/', $attrs)) {
                        preg_match_all('/<t(?:[^>]*)>(.*?)<\/t>/s', $cell[2], $tm);
                        $val = trim(html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    } elseif (preg_match('/\bt="s"/', $attrs) && preg_match('/<v>(.*?)<\/v>/s', $cell[2], $vm)) {
                        $val = $shared[(int)trim($vm[1])] ?? '';
                    } elseif (preg_match('/<v>(.*?)<\/v>/s', $cell[2], $vm)) {
                        $val = trim($vm[1]);
                    }
                    if ($val !== '') {
                        $cellLines[] = $ref . ': ' . $val;
                    }
                }
            }
            if (!empty($cellLines)) {
                $sheetName = $names[strtolower($path)] ?? '';
                $label = $sheetName !== '' ? '[Sheet: ' . $sheetName . ']' : '[Sheet ' . $num . ']';
                $out[] = $label . "\n" . implode("\n", $cellLines);
            }
        }
        return implode("\n\n", $out);
    }

    /**
     * PPTX: every slide followed by its speaker notes, numbered so the
     * per-slide structure survives chunking.
     */
    private function pptxZipText(\ZipArchive $zip, int &$total, bool &$aborted): string {
        $slides = [];
        $notes = [];
        $count = $zip->numFiles;
        for ($i = 0; $i < $count && !$aborted; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $m)) {
                $slides[(int)$m[1]] = $i;
            } elseif (preg_match('~^ppt/notesSlides/notesSlide(\d+)\.xml$~', $name, $m)) {
                $notes[(int)$m[1]] = $i;
            }
        }
        ksort($slides);
        ksort($notes);
        $out = [];
        foreach ($slides as $num => $index) {
            $text = $this->paragraphText($this->zipReadEntry($zip, $index, $total, $aborted), 'a:p', 'a:t');
            if ($text !== '') {
                $out[] = '[Slide ' . $num . "]\n" . $text;
            }
        }
        foreach ($notes as $num => $index) {
            $text = $this->paragraphText($this->zipReadEntry($zip, $index, $total, $aborted), 'a:p', 'a:t');
            if ($text !== '') {
                $out[] = '[Speaker notes (slide ' . $num . ")]\n" . $text;
            }
        }
        return implode("\n\n", $out);
    }

    /**
     * ODF (text/spreadsheet/presentation): full content.xml, with every
     * table/sheet prefixed by a boundary marker carrying the sheet name.
     */
    private function odfZipText(\ZipArchive $zip, int &$total, bool &$aborted): string {
        $idx = $zip->locateName('content.xml');
        if ($idx === false) {
            return '';
        }
        $xml = $this->zipReadEntry($zip, $idx, $total, $aborted);
        if ($xml === '') {
            return '';
        }
        $xml = preg_replace_callback('/<table:table\b([^>]*)>/', static function (array $m): string {
            $name = '';
            if (preg_match('/\btable:name="([^"]*)"/', $m[1], $nm)) {
                $name = html_entity_decode($nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            return "\n\n" . ($name !== '' ? '[Sheet: ' . $name . ']' : '[Sheet]') . "\n";
        }, $xml);
        $plain = preg_replace('/<[^>]+>/', ' ', $xml ?? '');
        return html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** EPUB: full text of every XHTML chapter, in file order. */
    private function epubZipText(\ZipArchive $zip, int &$total, bool &$aborted): string {
        $out = '';
        $entriesProcessed = 0;
        $count = $zip->numFiles;
        for ($i = 0; $i < $count && !$aborted && $entriesProcessed < self::MAX_ZIP_ENTRIES; $i++) {
            $entriesProcessed++;
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            if (!str_ends_with($entry, '.htm') && !str_ends_with($entry, '.html') && !str_ends_with($entry, '.xhtml')) {
                continue;
            }
            $html = $this->zipReadEntry($zip, $i, $total, $aborted);
            if ($html === '') {
                continue;
            }
            $out .= ' ' . $this->htmlText($html);
        }
        return $out;
    }

    /**
     * Extracts searchable text from a ZIP-based office container. Each
     * format is read through zipReadEntry(), which enforces the entry and
     * total decompression budget, so extraction stays memory-bounded.
     */
    private function zipWebText(File $file, string $kind): string {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rg_');
        $out = '';
        try {
            if ($tmp === false) {
                return '';
            }
            file_put_contents($tmp, $file->getContent());
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return '';
            }
            try {
                $total = 0;
                $aborted = false;
                if ($kind === 'docx') {
                    $out = $this->docxZipText($zip, $total, $aborted);
                } elseif ($kind === 'xlsx') {
                    $out = $this->xlsxZipText($zip, $total, $aborted);
                } elseif ($kind === 'pptx') {
                    $out = $this->pptxZipText($zip, $total, $aborted);
                } elseif ($kind === 'odf') {
                    $out = $this->odfZipText($zip, $total, $aborted);
                } elseif ($kind === 'epub') {
                    $out = $this->epubZipText($zip, $total, $aborted);
                }
            } finally {
                $zip->close();
            }
        } finally {
            if ($tmp !== null && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
        return $this->normalize($out ?? '');
    }

    private function pdfToText(File $file): ?string {
        // Nur wenn das poppler-utils Binary vorhanden ist.
        $bin = trim((string)(shell_exec('command -v pdftotext 2>/dev/null') ?: ''));
        if ($bin === '') {
            return null;
        }
        $tmpIn = tempnam(sys_get_temp_dir(), 'rag_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'rag_');
        if ($tmpIn === false || $tmpOut === false) {
            return null;
        }
        try {
            file_put_contents($tmpIn, $file->getContent());
            // -layout preserves the physical layout (columns, tables) so
            // tabular PDFs stay structured instead of flowing into one run.
            shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' 2>/dev/null');
            $txt = file_exists($tmpOut) ? (string)file_get_contents($tmpOut) : '';
            @unlink($tmpOut);
            if ($txt === '') return null;
            $pages = explode("\f", $txt);
            if (trim(end($pages)) === '') array_pop($pages);
            return implode("\n\n", array_map(static fn($page, $index) => '[Page ' . ($index + 1) . "]\n" . trim($page), $pages, array_keys($pages)));
        } finally {
            if (file_exists($tmpIn)) {
                @unlink($tmpIn);
            }
        }
    }

    /**
     * Legacy binary Office formats (.doc/.xls/.ppt): convert to plain text
     * headless with LibreOffice (soffice) when available, otherwise skip with
     * a logged reason. A private profile dir keeps concurrent conversions
     * from fighting over the global LibreOffice profile lock.
     */
    private function legacyOfficeText(File $file, string $ext): string {
        $bin = trim((string)(shell_exec('command -v soffice 2>/dev/null || command -v libreoffice 2>/dev/null') ?: ''));
        if ($bin === '') {
            $this->logWarning('eva_ai: legacy office file skipped - LibreOffice (soffice) is not installed', ['file' => $file->getPath(), 'ext' => $ext]);
            return '';
        }
        $tmpDir = sys_get_temp_dir() . '/eva_lo_' . bin2hex(random_bytes(5));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return '';
        }
        try {
            $in = $tmpDir . '/input.' . $ext;
            $outDir = $tmpDir . '/out';
            $profile = $tmpDir . '/profile';
            @mkdir($outDir, 0700, true);
            file_put_contents($in, $file->getContent());
            $cmd = escapeshellarg($bin) . ' --headless -env:UserInstallation=file://' . escapeshellarg($profile)
                . ' --convert-to "txt:Text (encoded):UTF8" --outdir ' . escapeshellarg($outDir)
                . ' ' . escapeshellarg($in) . ' 2>/dev/null';
            shell_exec($cmd);
            $txt = '';
            foreach ((array)glob($outDir . '/*.txt') as $f) {
                $txt .= (string)file_get_contents($f);
            }
            return $txt;
        } finally {
            @exec('rm -rf ' . escapeshellarg($tmpDir));
        }
    }

    /**
     * HTML/EPUB chapter -> searchable text with structure preserved: the
     * document <title> becomes a top-level markdown heading and every
     * <h1>-<h6> becomes a #..###### heading line. The Chunker treats those
     * lines as section anchors, so retrieved chunks keep their section
     * context instead of an undifferentiated text wall.
     */
    private function htmlText(string $html): string {
        $title = '';
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html ?? '');
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html ?? '');
        // Turn headings into markdown anchors BEFORE stripping tags.
        $html = preg_replace_callback('/<h([1-6])\b[^>]*>/i', static function (array $m): string {
            return "\n\n" . str_repeat('#', (int)$m[1]) . ' ';
        }, $html ?? '');
        $html = preg_replace('/<\/h[1-6]>/i', "\n\n", $html ?? '');
        $html = preg_replace('/<[^>]+>/', ' ', $html ?? '');
        $text = $this->normalize(html_entity_decode($html ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title !== '') {
            $text = '# ' . $title . ($text !== '' ? "\n\n" . $text : '');
        }
        return $text;
    }

    /**
     * Text of a saved mail message (.eml), a web archive (.mhtml/.mht) or one
     * message inside a mailbox.
     *
     * A mail is a MIME tree: the real text sits in a leaf part, often encoded
     * as base64 or quoted-printable, and the envelope fields carry the details
     * people actually search for ("the invoice from May"). Both are read here.
     */
    private function emlText(string $raw): string {
        return $this->normalize($this->mimeMessageText($raw));
    }

    /**
     * Text of a mailbox file (.mbox).
     *
     * A mailbox is many messages concatenated, each introduced by a line that
     * starts with "From ". Splitting on those separators keeps every message
     * instead of indexing one blob in which the first envelope hides the rest.
     * The separator line is put back before each chunk so the chunk parses as a
     * message.
     */
    private function mboxText(string $raw): string {
        $separators = [];
        if (preg_match_all('/^From .*$/m', $raw, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return $this->emlText($raw);
        }
        foreach ($matches[0] as $match) {
            $separators[] = (int)$match[1];
        }
        $separators[] = strlen($raw);
        $messages = [];
        for ($i = 0; $i < count($separators) - 1; $i++) {
            $messages[] = substr($raw, $separators[$i], $separators[$i + 1] - $separators[$i]);
        }
        $out = [];
        foreach ($messages as $message) {
            $text = $this->mimeMessageText($message);
            if (trim($text) !== '') {
                $out[] = $text;
            }
            // A mailbox can be large; stop once the extract is long enough to
            // answer questions from instead of indexing the whole archive.
            if (strlen(implode("\n\n", $out)) > self::MAX_EXTRACT_CHARS) {
                break;
            }
        }
        return $this->normalize(implode("\n\n", $out));
    }

    /**
     * Text of a Jupyter notebook: markdown and code cells, in order.
     *
     * The cell kind is kept as a label so a code answer does not read as prose.
     */
    private function notebookText(string $raw): string {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['cells']) || !is_array($data['cells'])) {
            return '';
        }
        $out = [];
        foreach ($data['cells'] as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $source = $cell['source'] ?? '';
            $text = is_array($source) ? implode('', array_map('strval', $source)) : (string)$source;
            if (trim($text) === '') {
                continue;
            }
            $kind = (string)($cell['cell_type'] ?? '');
            $label = $kind === 'markdown' ? 'Markdown' : ($kind === 'code' ? 'Code' : '');
            $out[] = ($label !== '' ? '[' . $label . "]\n" : '') . $text;
        }
        return implode("\n\n", $out);
    }

    /**
     * The envelope plus the readable body of one MIME message.
     *
     * A multipart message usually carries the same text twice, once as plain
     * text and once as HTML. The plain part is preferred when it exists, so an
     * answer does not contain every sentence twice.
     */
    private function mimeMessageText(string $raw): string {
        [$envelope, $parts] = $this->walkMime($raw);
        $plain = '';
        $html = '';
        foreach ($parts as $part) {
            if ($part['type'] === 'text/plain') {
                $plain .= "\n" . $part['text'];
            } elseif ($part['type'] === 'text/html') {
                $html .= "\n" . $this->htmlText($part['text']);
            }
        }
        $body = trim($plain) !== '' ? $plain : $html;
        return $envelope . "\n" . $body;
    }

    /**
     * Walk a MIME entity and return its envelope text and every textual leaf.
     *
     * @return array{0:string,1:list<array{type:string,text:string}>}
     */
    private function walkMime(string $raw, int $depth = 0): array {
        if ($depth > 5) {
            // A message that nests deeper than this is either broken or built
            // to exhaust the parser, so the walk stops rather than recursing.
            return ['', []];
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $split = $this->splitMimeHeaders($raw);
        $headers = $split['headers'];
        $body = $split['body'];

        $envelope = [];
        foreach (['subject' => 'Subject', 'from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'date' => 'Date'] as $key => $label) {
            if (isset($headers[$key]) && trim($headers[$key]) !== '') {
                $envelope[] = $label . ': ' . $this->decodeMimeWords($headers[$key]);
            }
        }
        $envelopeText = implode("\n", $envelope);

        $contentType = trim((string)($headers['content-type'] ?? 'text/plain'));
        $type = strtolower(trim(explode(';', $contentType)[0]));
        // A MIME entity without a Content-Type header is plain text (RFC 2045).
        if ($type === '') {
            $type = 'text/plain';
        }
        $encoding = strtolower(trim((string)($headers['content-transfer-encoding'] ?? '')));

        if (str_starts_with($type, 'multipart/')) {
            // The boundary is taken from the header as written: it is a
            // case-sensitive delimiter, so comparing it against a lowercased
            // header silently drops every part of the message.
            if (preg_match('~boundary\s*=\s*"?([^";\n]+)"?~i', $contentType, $m) !== 1) {
                return [$envelopeText, []];
            }
            $boundary = '--' . trim($m[1]);
            $chunks = explode($boundary, $body);
            // The first chunk is the preamble, the last starts with "--".
            array_shift($chunks);
            $parts = [];
            foreach ($chunks as $chunk) {
                if (str_starts_with(ltrim($chunk), '--')) {
                    break;
                }
                [, $subParts] = $this->walkMime($chunk, $depth + 1);
                foreach ($subParts as $subPart) {
                    $parts[] = $subPart;
                }
            }
            return [$envelopeText, $parts];
        }

        $decoded = $this->decodeMimeBody($body, $encoding);
        if ($type === 'text/html' || str_ends_with($type, '+xml') || $type === 'application/xhtml+xml') {
            return [$envelopeText, [['type' => 'text/html', 'text' => $decoded]]];
        }
        if (str_starts_with($type, 'text/')) {
            return [$envelopeText, [['type' => 'text/plain', 'text' => $decoded]]];
        }
        // Anything else (an image, a PDF part) is not read here; attachments are
        // indexed from their own file when the user stores them separately.
        return [$envelopeText, []];
    }

    /**
     * Split an entity into its header block and its body, unfolding headers.
     *
     * @return array{headers:array<string,string>,body:string}
     */
    private function splitMimeHeaders(string $raw): array {
        $head = $raw;
        $body = '';
        $blank = strpos($raw, "\n\n");
        if ($blank !== false) {
            $head = substr($raw, 0, $blank);
            $body = substr($raw, $blank + 2);
        }
        // A continuation line starts with a space or tab and belongs to the
        // header above it; without unfolding, a long Subject is truncated.
        $head = (string)preg_replace("/\n[ \t]/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $pos)));
            if ($key === '' || isset($headers[$key])) {
                continue;
            }
            $headers[$key] = trim(substr($line, $pos + 1));
        }
        return ['headers' => $headers, 'body' => $body];
    }

    /** Undo the transfer encoding a MIME part declares. */
    private function decodeMimeBody(string $body, string $encoding): string {
        if ($encoding === 'base64') {
            $compact = (string)preg_replace('/\s+/', '', $body);
            $decoded = base64_decode($compact, true);
            if ($decoded === false) {
                // A malformed base64 part must not empty the whole message;
                // the header block above still carries searchable text.
                return '';
            }
            return $this->toUtf8($decoded);
        }
        if ($encoding === 'quoted-printable') {
            return $this->toUtf8(quoted_printable_decode($body));
        }
        return $this->toUtf8($body);
    }

    /**
     * Mail bodies are frequently ISO-8859-1 or Windows-1252. Postgres and the
     * JSON payload both require valid UTF-8, so a non-UTF-8 body is converted
     * instead of being stored as broken bytes.
     */
    private function toUtf8(string $text): string {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252, ISO-8859-15, ISO-8859-1');
        if ($converted === false || $converted === '') {
            // Last resort: drop the invalid bytes rather than fail the file.
            return (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return $converted;
    }

    /** Decode MIME "encoded-words" (=?UTF-8?B?...?=) in a header value. */
    private function decodeMimeWords(string $value): string {
        if (!str_contains($value, '=?')) {
            return $this->toUtf8($value);
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        return $this->toUtf8($value);
    }

    private function normalize(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Überflüssige Leerzeichen/Zeilenumbrüche glätten, Blockgrenzen behalten.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text ?? '');
    }

    private function relativePath(string $userId, string $path): string {
        $prefix = '/' . $userId . '/files';
        if (str_starts_with($path, $prefix)) {
            $rel = substr($path, strlen($prefix));
            return ltrim($rel, '/');
        }
        return ltrim($path, '/');
    }

    private function removeStaleDocument(string $userId, int $fileId): void {
        $existing = $this->documentMapper->findByUserAndFile($userId, $fileId);
        if ($existing !== null) {
            $this->chunkMapper->deleteByDocument((int)$existing->getId());
            $this->documentMapper->delete($existing);
        }
    }

    private function flushBatch(array &$batch, array &$result, ?string $runId = null, ?string $userId = null): void {
        if (empty($batch)) {
            return;
        }
        // Do not start expensive work after a stop request has been observed.
        if ($this->cancellationRequested($runId)) {
            $this->discardStagedBatch($batch);
            $batch = [];
            return;
        }
        $this->touchHeartbeat($runId);
        $texts = [];
        foreach ($batch as $b) {
            $texts[] = $b['content'];
        }

        [$vecs, $err] = $this->ollama->embedBatch($texts, $userId);
        $stats = $this->ollama->lastEmbeddingStats();
        $result['cache_hits'] += $stats['cache_hits'];
        $result['cache_misses'] += $stats['cache_misses'];
        $result['ollama_requests'] += $stats['ollama_requests'];
        $this->touchHeartbeat($runId);
        // The HTTP client uses a bounded read timeout, but cancellation can
        // still arrive while a response is completing. Never publish vectors
        // from a batch after the stop flag was set.
        if ($this->cancellationRequested($runId)) {
            $this->discardStagedBatch($batch);
            $batch = [];
            return;
        }
        if ($err !== null || $vecs === null) {
            $result['error'] = $err ?? 'Embedding error';
            $this->logger->error('eva_ai embedding failed', ['error' => $err]);
            $this->discardStagedBatch($batch);
            // Note: Old documents are NOT deleted yet, so they remain available for search.
            $this->logger->info('eva_ai: Preserved old documents after embedding failure', ['docCount' => count(array_unique(array_column($batch, 'docId')))]);
            $batch = [];
            return;
        }

        $perDoc = [];
        $failedDocs = [];
        $failedFiles = [];
        foreach ($batch as $i => $b) {
            // A null vector means the model could not embed this document even
            // when it was sent alone. Skipping just that document keeps the rest
            // of the batch - and therefore the whole background pass - running;
            // the previous version of the file stays searchable. This is the
            // difference between one unreadable file and an index that stalls.
            if (($vecs[$i] ?? null) === null) {
                $failedDocs[(int)$b['docId']] = true;
                $failedFiles[(string)($b['path'] ?? $b['docId'])] = true;
                continue;
            }
            $perDoc[$b['docId']][] = ['index' => $b['index'], 'content' => $b['content'], 'tokens' => $b['tokens'], 'provenance' => $b['provenance'] ?? [], 'vec' => $vecs[$i], 'oldDocId' => $b['oldDocId']];
        }
        if ($failedDocs !== []) {
            // Drop only the staged replacements; the old documents are untouched
            // and remain available to search.
            $ids = array_keys($failedDocs);
            $this->chunkMapper->deleteByDocumentIds($ids);
            $this->documentMapper->deleteByIds($ids);
            // The counters describe what the index actually contains: a rolled
            // back document is not "processed", it is "failed". Reporting it as
            // both would overstate progress on every pass.
            $dropped = count($failedFiles);
            $result['failed'] = ($result['failed'] ?? 0) + $dropped;
            $result['processed'] = max(0, $result['processed'] - $dropped);
            $result['changed'] = max(0, $result['changed'] - $dropped);
            $this->logger->warning('eva_ai: skipped documents the embedding model could not process', [
                'documents' => count($failedDocs),
            ]);
        }
        if ($perDoc === []) {
            $batch = [];
            return;
        }
        foreach ($perDoc as $docId => $chunks) {
            if ($this->cancellationRequested($runId)) {
                $this->discardStagedBatch($batch);
                $batch = [];
                return;
            }
            foreach ($chunks as $c) {
                $chunk = new Chunk();
                $chunk->setDocumentId($docId);
                $chunk->setChunkIndex($c['index']);
                $chunk->setContent($c['content']);
                $chunk->setEmbeddingArray($c['vec']);
                $chunk->setTokenCount($c['tokens']);
                $chunk->setProvenanceArray($c['provenance'] ?? []);
                $this->chunkMapper->insert($chunk);
            }
            $doc = $this->documentMapper->findById($docId);
            if ($doc !== null) {
                $doc->setChunkCount(count($chunks));
                $this->documentMapper->update($doc);
            }
            
            // Only remove old document after successful embedding of new version.
            // If cancellation arrived while publishing this document, remove
            // the staged replacement and leave the old version intact.
            if ($this->cancellationRequested($runId)) {
                $this->discardStagedBatch($batch);
                $batch = [];
                return;
            }
            $oldDocId = $chunks[0]['oldDocId'];
            if ($oldDocId !== null) {
                try {
                    $oldDoc = $this->documentMapper->findById($oldDocId);
                    if ($oldDoc !== null) {
                        $this->chunkMapper->deleteByDocument($oldDocId);
                        $this->documentMapper->delete($oldDoc);
                        $this->logger->debug('eva_ai: Removed old document after successful re-index', ['oldDocId' => $oldDocId]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai: Failed to remove old document after re-index', ['error' => $e->getMessage(), 'oldDocId' => $oldDocId]);
                }
            }
        }
        $batch = [];
    }

    /** Remove staged replacement documents without touching their old versions. */
    private function discardStagedBatch(array $batch): void {
        $docIds = [];
        foreach ($batch as $entry) {
            if (isset($entry['docId'])) {
                $docIds[(int)$entry['docId']] = true;
            }
        }
        if ($docIds === []) {
            return;
        }
        $ids = array_keys($docIds);
        $this->chunkMapper->deleteByDocumentIds($ids);
        $this->documentMapper->deleteByIds($ids);
    }


    /**
     * Index emails from the Mail app (bounded pass, hash-skipped like files).
     * Mail docs use NEGATIVE file ids (-messageId) so the file-scan cleanup
     * never removes them. Runs only when the config 'mail_index_enabled' = 1
     * and the Mail app tables exist.
     */
    private function indexEmails(string $userId, array &$result, int $maxFiles, bool $force = false, ?string $runId = null): void {
        if (!$force && $this->config->get('mail_index_enabled') !== '1') {
            return;
        }
        $limit = max(1, min(500, $this->config->getInt('mail_index_max', 25)));
        try {
            $mails = $this->email->listMessages($userId, $limit, false);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: mail index list failed', ['e' => $e->getMessage()]);
            return;
        }
        if ($mails === []) {
            // An empty list is ambiguous: the account may hold no mail, or the Mail
            // app may be unavailable (EmailService returns [] for both).
            // Reconciliation is therefore skipped rather than run against an empty
            // list, which would delete every indexed message - including when Mail
            // is only temporarily unreachable. A deleted message is cleaned up on
            // any pass that does list messages.
            return;
        }
        $hashes = $this->documentMapper->hashesForUser($userId);
        $batch = [];
        $processedThisPass = 0;
        $maxTotal = max($maxFiles, 10);
        foreach ($mails as $mail) {
            $this->touchHeartbeat($runId);
            if ($this->cancellationRequested($runId)) {
                break;
            }
            if ($processedThisPass >= $maxTotal) {
                break;
            }
            $msgId = (int)$mail['id'];
            $mailFileId = -$msgId;
            $body = '';
            try {
                $body = $this->email->bodyText($msgId, $userId);
            } catch (\Throwable $e) {
            }
            $this->touchHeartbeat($runId);
            $from = $mail['from'];
            $to = implode(', ', (array)($mail['to'] ?? []));
            $content = "EMAIL\nFrom: " . $from . "\nTo: " . $to . "\nDate: " . date('Y-m-d H:i', (int)$mail['sent'])
                . "\nSubject: " . $mail['subject'] . "\n\n" . trim($body);

            // Append indexable attachment text (Issue #64).
            try {
                $attachments = $this->email->fetchAttachments($userId, $msgId);
                foreach ($attachments as $att) {
                    $attText = $this->extractTextFromBlob($att['content'], $att['mime']);
                    if (trim($attText) !== '') {
                        $content .= "\n\nATTACHMENT " . $att['name'] . "\n" . $attText;
                    }
                }
            } catch (\Throwable $e) {
                // Attachment indexing is best-effort; never block the email index.
            }

            $content = $this->normalize($content);
            if ($content === '' || trim($mail['subject']) === '') {
                continue;
            }
            if (mb_strlen($content) > 30000) {
                $content = mb_substr($content, 0, 30000);
            }
            $hash = md5($content);
            if (($hashes[$mailFileId] ?? null) === $hash) {
                continue; // unchanged since last run
            }
            $chunks = $this->chunker->chunk($content);
            if (empty($chunks)) {
                continue;
            }
            // Stage the replacement. The previous mail document remains
            // searchable until flushBatch successfully embeds the new chunks.
            $existingDoc = $this->documentMapper->findByUserAndFile($userId, $mailFileId);
            $oldDocId = $existingDoc !== null ? (int)$existingDoc->getId() : null;
            $doc = new Document();
            $doc->setUserId($userId);
            $doc->setFileId($mailFileId);
            $doc->setPath('mail://' . $msgId);
            $doc->setName('mail ' . $msgId . ' - ' . ($mail['subject'] ?? ''));
            $doc->setMime('message/rfc822');
            $doc->setSource(Document::SOURCE_MAIL);
            $doc->setSize(mb_strlen($content));
            $doc->setContentHash($hash);
            $doc->setChunkCount(count($chunks));
            $doc->setIndexedAt(time());
            $this->documentMapper->insert($doc);
            foreach ($chunks as $i => $c) {
                $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens'], 'provenance' => $c['provenance'] ?? [], 'oldDocId' => $oldDocId];
            }
            $result['processed']++;
            $processedThisPass++;
            if (count($batch) >= min(200, max(1, $this->config->getInt('embed_batch_size', self::DEFAULT_BATCH)))) {
                $this->flushBatch($batch, $result, $runId, $userId);
            }
        }
        $this->flushBatch($batch, $result, $runId, $userId);

        if ($this->cancellationRequested($runId)) {
            return;
        }

        // Reconciliation (Issue #15): remove indexed mail documents whose
        // underlying message no longer exists in the Mail account.
        $this->reconcileMailIndex($userId);
    }

    /**
     * Remove indexed mail documents whose message was deleted from the Mail
     * account. Privacy fix: deleted emails must stop being searchable through
     * the RAG index even without a full reindex.
     *
     * Only rows with source=mail are considered: the mail and Talk indexes share
     * the negative file-id space, so reconciliation must never touch another
     * producer's rows.
     */
    private function reconcileMailIndex(string $userId): void {
        try {
            $current = $this->email->allMessageIds($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: mail reconciliation failed', ['e' => $e->getMessage()]);
            return;
        }
        $currentSet = array_flip($current);
        $stored = $this->documentMapper->fileIdsForSource($userId, Document::SOURCE_MAIL);
        foreach ($stored as $fileId) {
            $msgId = -$fileId;
            if (!isset($currentSet[$msgId])) {
                $this->removeStaleDocument($userId, $fileId);
                $this->logger->info('eva_ai: Removed stale mail document (message deleted)', [
                    'msgId' => $msgId,
                    'userId' => $userId,
                ]);
            }
        }
    }

    /**
     * Index the user's Nextcloud Talk chat histories.
     *
     * One room becomes one document, because a conversation is only meaningful as
     * a whole: chunking happens afterwards like for any other text. The pass is
     * opt-in (`talk_index_enabled`) and bounded by `talk_index_max_rooms`, so a
     * user in hundreds of rooms cannot turn one click into an unbounded job.
     *
     * Membership is decided by Talk at the moment of indexing, so only rooms the
     * user is currently in are ever written for that user.
     */
    private function indexTalkRooms(string $userId, array &$result, ?string $runId = null, bool $force = false): void {
        if (!$force && $this->config->get('talk_index_enabled') !== '1') {
            return;
        }
        if (!$this->talkTranscripts->isAvailable()) {
            return;
        }
        $maxRooms = max(1, min(200, $this->config->getInt('talk_index_max_rooms', 20)));
        $rooms = $this->talkTranscripts->roomsForUser($userId, $maxRooms);
        if ($rooms === []) {
            // Nothing to do; reconciliation still runs so rooms the user left
            // stop being searchable.
            $this->reconcileTalkIndex($userId, []);
            return;
        }
        $hashes = $this->documentMapper->hashesForUser($userId);
        $batch = [];
        $currentRoomIds = [];
        foreach ($rooms as $room) {
            $this->touchHeartbeat($runId);
            if ($this->cancellationRequested($runId)) {
                return;
            }
            $roomId = (int)$room['id'];
            $currentRoomIds[] = $roomId;
            $fileId = self::talkFileId($roomId);
            try {
                $transcript = $this->talkTranscripts->transcript($userId, $roomId);
            } catch (\Throwable $e) {
                // One unreadable room must never stop the pass: skip it and keep
                // indexing the rest.
                $this->logger->warning('eva_ai: skipped Talk room ' . $roomId . ': ' . $e->getMessage());
                continue;
            }
            if ($transcript === null) {
                continue;
            }
            $content = $this->normalize($transcript['text']);
            if (trim($content) === '') {
                continue;
            }
            if (mb_strlen($content) > self::MAX_EXTRACT_CHARS) {
                $content = mb_substr($content, 0, self::MAX_EXTRACT_CHARS);
            }
            $hash = md5($content);
            if (($hashes[$fileId] ?? null) === $hash) {
                $result['skipped']++;
                continue;
            }
            $chunks = $this->chunker->chunk($content);
            if ($chunks === []) {
                continue;
            }
            $existingDoc = $this->documentMapper->findByUserAndFile($userId, $fileId);
            $oldDocId = $existingDoc !== null ? (int)$existingDoc->getId() : null;
            $doc = new Document();
            $doc->setUserId($userId);
            $doc->setFileId($fileId);
            $doc->setPath('talk://' . $roomId);
            $doc->setName('Talk: ' . $transcript['name']);
            $doc->setMime('text/x-talk');
            $doc->setSize(mb_strlen($content));
            $doc->setFileMtime(time());
            $doc->setContentHash($hash);
            $doc->setChunkCount(count($chunks));
            $doc->setIndexedAt(time());
            $doc->setSource(Document::SOURCE_TALK);
            $this->documentMapper->insert($doc);
            foreach ($chunks as $i => $c) {
                $batch[] = [
                    'docId' => (int)$doc->getId(),
                    'index' => $i,
                    'content' => $c['content'],
                    'tokens' => $c['tokens'],
                    'provenance' => $c['provenance'] ?? [],
                    'oldDocId' => $oldDocId,
                ];
            }
            $result['processed']++;
            if (count($batch) >= min(200, max(1, $this->config->getInt('embed_batch_size', self::DEFAULT_BATCH)))) {
                $this->flushBatch($batch, $result, $runId, $userId);
            }
        }
        $result['total_seen'] += count($currentRoomIds);
        $this->flushBatch($batch, $result, $runId, $userId);

        if ($this->cancellationRequested($runId)) {
            return;
        }
        $this->reconcileTalkIndex($userId, $currentRoomIds);
    }

    /**
     * Drop indexed rooms the user is no longer a member of.
     *
     * A conversation the user left must stop being searchable for them, and
     * leaving a room is not a file event, so nothing else would clean it up.
     *
     * @param int[] $currentRoomIds rooms present in this pass
     */
    private function reconcileTalkIndex(string $userId, array $currentRoomIds): void {
        $current = array_flip(array_map('intval', $currentRoomIds));
        try {
            $stored = $this->documentMapper->fileIdsForSource($userId, Document::SOURCE_TALK);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: Talk reconciliation failed: ' . $e->getMessage());
            return;
        }
        foreach ($stored as $fileId) {
            $roomId = self::talkRoomId($fileId);
            if ($roomId <= 0 || isset($current[$roomId])) {
                continue;
            }
            // A stored room that the user is still in but that was not indexed in
            // this pass is kept: the pass may have been bounded by maxRooms.
            if ($this->talkTranscripts->isMember($userId, $roomId)) {
                continue;
            }
            $this->removeStaleDocument($userId, $fileId);
        }
    }

    /** The synthetic file id of an indexed room. */
    public static function talkFileId(int $roomId): int
    {
        return -(self::TALK_FILE_ID_BASE + $roomId);
    }

    /** The room id behind a synthetic file id; 0 when it is not a Talk row. */
    public static function talkRoomId(int $fileId): int
    {
        if ($fileId > -self::TALK_FILE_ID_BASE) {
            return 0;
        }
        return -$fileId - self::TALK_FILE_ID_BASE;
    }

    private function cleanupRemoved(string $userId, array $seen, array $stale): void {
        $stored = $this->documentMapper->findFileIdsForUser($userId);
        $removed = array_filter(array_diff($stored, array_keys($seen)), static fn($f) => $f > 0);
        
        // Also add stale files to removal list
        foreach ($stale as $fileId => $flag) {
            if (!in_array($fileId, $removed, true)) {
                $removed[] = $fileId;
            }
        }
        
        if (empty($removed)) {
            // Still reconcile exclusions even if no documents were removed this pass
            $this->cleanupExcluded($userId);
            return;
        }
        $docIds = [];
        foreach ($removed as $fileId) {
            $doc = $this->documentMapper->findByUserAndFile($userId, (int)$fileId);
            if ($doc !== null) {
                $docIds[] = (int)$doc->getId();
                $this->documentMapper->delete($doc);
                $this->logger->info('eva_ai: Removed stale document', ['fileId' => $fileId, 'userId' => $userId]);
            }
        }
        $this->chunkMapper->deleteByDocumentIds($docIds);
        
        // Also remove documents that are now excluded by exclude_paths configuration
        $this->cleanupExcluded($userId);
    }
    
    private function cleanupExcluded(string $userId): void {
        $excludePaths = $this->parseExcludePaths();
        if (empty($excludePaths)) {
            return;
        }
        
        $allDocs = $this->documentMapper->findByUser($userId);
        $excludedDocIds = [];
        $offset = 0;
        $limit = 500;

        do {
            $docs = $this->documentMapper->findByUser($userId, null, $limit, $offset);
            foreach ($docs as $doc) {
                $docPath = $doc->getPath();
                // Normalize path for comparison (match parseExcludePaths normalization)
                $normalizedPath = trim(strtolower($docPath), '/');

                // Check if this document's path is now excluded
                foreach ($excludePaths as $excluded) {
                    if ($normalizedPath === $excluded || str_starts_with($normalizedPath, $excluded . '/')) {
                        $excludedDocIds[] = (int)$doc->getId();
                        $this->logger->info('eva_ai: Removing document due to exclusion rule', [
                            'userId' => $userId,
                            'path' => $docPath,
                            'excludedRule' => $excluded
                        ]);
                        break;
                    }
                }
            }
            $offset += $limit;
        } while (count($docs) === $limit);
        
        if (!empty($excludedDocIds)) {
            $this->chunkMapper->deleteByDocumentIds($excludedDocIds);
            foreach ($excludedDocIds as $docId) {
                $doc = $this->documentMapper->findById($docId);
                if ($doc !== null) {
                    $this->documentMapper->delete($doc);
                }
            }
            $this->logger->info('eva_ai: Cleaned up excluded documents', [
                'userId' => $userId,
                'count' => count($excludedDocIds)
            ]);
        }
    }

    /**
     * Calculate a hash of the current indexing configuration to detect changes
     * that require index rebuilds (embedding model, chunking settings, etc.)
     */    private function touchHeartbeat(?string $runId = null): void
    {
        if ($runId !== null && $this->config->get('index_run_id') !== $runId) {
            return;
        }
        $this->config->set('index_heartbeat', (string)time());
    }

    /**
     * Refresh liveness at most once per HEARTBEAT_INTERVAL_SECONDS. Both the
     * per-user heartbeat and the scheduler slot are refreshed so a crashed
     * worker is still reclaimed, but a large library no longer pays a database
     * write and a global scheduler lock for every single file.
     */
    private function maybeTouchHeartbeat(string $userId, ?string $runId): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatAt < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }
        $this->lastHeartbeatAt = $now;
        $this->touchHeartbeat($runId);
        $this->scheduler->touchHeartbeat($userId);
    }

    private function cancellationRequested(?string $runId = null): bool {
        return $this->config->get('index_cancel_requested') === '1'
            || ($runId !== null && $this->config->get('index_run_id') !== $runId);
    }

    private function calculateConfigHash(): string {
        $configKey = implode('|', [
            'provenance-v1',
            $this->config->get('embedding_model', 'default'),
            $this->config->get('ocr_enabled', '0'),
            $this->config->get('ocr_language', 'eng'),
            $this->config->get('embedding_model_fallback', ''),
            $this->config->get('chunk_size', '1000'),
            $this->config->get('chunk_overlap', '200'),
            $this->config->get('max_file_size', '20971520'),
            $this->config->get('exclude_paths', ''),
            $this->config->get('scope_path', ''),
        ]);
        return md5($configKey);
    }

    /**
     * Delete the complete RAG index (documents + chunks) for one user
     * or for ALL users. Resets the index state flags as well.
     * @return array{documents:int,chunks:int}
     */
    public function reset(?string $userId = null): array {
        if ($userId !== null && $userId !== '') {
            $this->config->setUserId($userId);
            $docs = $this->documentMapper->deleteByUser($userId);
            $chunks = $this->chunkMapper->deleteForUser($userId);
            $this->embeddingCache->clearUser($userId);
        } else {
            // A null reset is the explicit all-users/maintenance path. Clear
            // any stale request context before writing the global state.
            $this->config->setUserId(null);
            $docs = $this->documentMapper->deleteAll();
            $chunks = $this->chunkMapper->deleteAll();
            $this->embeddingCache->clear();
        }
        $this->config->set('index_running', '0');
        $this->config->set('index_finished', '0');
        $this->config->set('index_started', '');
        $this->config->set('last_index_total', '0');
        $this->config->set('last_index_processed', '0');
        $this->config->set('last_index_error', '');
        $this->config->set('last_index_cache_hits', '0');
        $this->config->set('last_index_cache_misses', '0');
        $this->config->set('last_index_ollama_requests', '0');
        $this->config->set('index_config_hash', ''); // Reset config hash on full reset
        $this->config->set('index_mode', 'idle');
        $this->config->set('index_cancel_requested', '0');
        $this->config->set('index_run_id', '');
        $this->config->set('index_heartbeat', '');
        return ['documents' => $docs, 'chunks' => $chunks];
    }
}
