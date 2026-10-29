<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\Chunk;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\VectorSourceDeletion;
use OCA\EvaAi\Vector\DefaultSqlGenerationProvisioner;
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

    /**
     * Liveness heartbeats are throttled to this interval. The index loop used
     * to write the per-user heartbeat value and take the global scheduler lock
     * for every single file - a database write plus a global lock per file on a
     * library of thousands of files. Ten seconds keeps stale-run detection and
     * the admin status view accurate while removing that per-file overhead.
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 10;
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
        private EmbeddingProvider $ollama,
        private EmbeddingCache $embeddingCache,
        private EmailService $email,
        private TalkTranscriptService $talkTranscripts,
        private LoggerInterface $logger,
        private ILockingProvider $lockingProvider,
        private LockGuard $lockGuard,
        private IndexScheduler $scheduler,
        private ?VectorSourceDeletion $vectorSourceDeletion = null,
        private ?DefaultSqlGenerationProvisioner $sqlGenerationProvisioner = null,
        private ?UsageMetrics $usageMetrics = null
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
			'file_chunks_indexed' => 0,
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
		$runStartedAt = hrtime(true);
		$filePassStartedAt = null;
		$filePassDurationMs = 0;
        
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
				$filePassStartedAt = hrtime(true);
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
            // Keep the fast bulk path for normal libraries, but avoid holding
            // a huge PHP map when an index contains hundreds of thousands of
            // documents. Large indexes fall back to one-row lookups.
            $stateCache = count($docState) > 10000 ? null : $docState;
            unset($docState);
            $stateForFile = function (int $fileId) use (&$stateCache, $userId): ?array {
                if ($stateCache !== null) {
                    return $stateCache[$fileId] ?? null;
                }
                return $this->documentMapper->stateForFile($userId, $fileId);
            };
            $quota = max(0, $this->config->getInt('index_storage_quota', 0));
            $indexedBytes = 0;
            if ($stateCache !== null && $quota > 0) {
                foreach ($stateCache as $stored) {
                    $indexedBytes += max(0, (int)($stored['size'] ?? 0));
                }
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
                $state = $stateForFile($fileId);
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
                    if ($state !== null) {
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

                if ($quota > 0) {
                    $oldSize = $state !== null ? max(0, (int)($state['size'] ?? 0)) : 0;
                    $projected = $indexedBytes - $oldSize + max(0, (int)$size);
                    if ($projected > $quota) {
                        $result['skipped']++;
                        $this->logger->notice('eva_ai: indexing quota reached; file skipped', [
                            'user' => $userId, 'file' => $path, 'quota' => $quota,
                        ]);
                        continue;
                    }
                }

                // Stored state comes from the one bulk read that opened this
                // pass, so discovering "unchanged" costs no query at all.
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
                        if ($state !== null) {
                            $stale[$fileId] = true;
                        }
                        continue;
                    }
                    $actualFile = $actualFile[0];
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai: file access failed', ['file' => $file->getPath(), 'e' => $e->getMessage()]);
                    $result['skipped']++;
                    // Access loss is authoritative and must purge cached content.
                    if ($state !== null) {
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
                    if ((int)$file->getSize() === 0 && $state !== null) {
                        $stale[$fileId] = true;
                    }
                    continue;
                }

                $hash = md5($content);
                if (($state['content_hash'] ?? null) === $hash) {
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
                if ($quota > 0) {
                    $indexedBytes = $indexedBytes - ($state !== null ? max(0, (int)($state['size'] ?? 0)) : 0) + max(0, (int)$size);
                }

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
            // The bulk fingerprint map is only needed during file traversal;
            // release it before cleanup/mail/Talk work so large indexes do not
            // keep a second full document-state graph alive for the rest of
            // the worker request.
            unset($stateCache, $stateForFile);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
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
			if ($filePassStartedAt !== null) {
				$filePassDurationMs = max(0, (int)round((hrtime(true) - $filePassStartedAt) / 1_000_000));
				$filePassStartedAt = null;
			}
            if (!$cancelled && ($mode === 'all' || $mode === 'mail')) {
                $this->indexEmails($userId, $result, $maxFiles, $mode === 'mail', $runId);
            }
            if (!$cancelled && ($mode === 'all' || $mode === 'talk')) {
                $this->indexTalkRooms($userId, $result, $runId, $mode === 'talk');
            }
			if ($this->cancellationRequested($runId)) {
				$cancelled = true;
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
				if ($filePassStartedAt !== null) {
					$filePassDurationMs = max(0, (int)round((hrtime(true) - $filePassStartedAt) / 1_000_000));
				}
                if ($ownsRun) {
					$outcome = $result['error'] !== null ? 'error' : ($cancelled ? 'incomplete' : 'complete');
					$this->usageMetrics?->recordIndexRun(
						$userId,
						$mode,
						max(0, (int)round((hrtime(true) - $runStartedAt) / 1_000_000)),
						$filePassDurationMs,
						(int)$result['processed'],
						(int)$result['file_chunks_indexed'],
						$outcome,
					);
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
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches();
                }
            }
        }

        // Older enrolled accounts may have a complete legacy index but no
        // changed files, so flushBatch() never gets a chance to provision the
        // default SQL generation. Bootstrap only after a healthy file pass;
        // it probes with public text and leaves document content to the
        // bounded background generation builder.
        if (($mode === 'all' || $mode === 'files') && $result['error'] === null
            && $this->sqlGenerationProvisioner !== null
            && $this->documentMapper->countForUser($userId) > 0) {
            $this->sqlGenerationProvisioner->bootstrap($userId, $this->ollama);
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
        $this->deleteIndexedDocuments($ids);
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
        $this->deleteIndexedDocuments([(int)$doc->getId()]);
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
        // Never materialize the complete tree: the previous implementation
        // recursively collected every file into one array before yielding the
        // first item. On large Nextcloud homes that retained thousands of
        // node objects and metadata for the entire run, appearing as a memory
        // leak. Depth-first yielding keeps memory proportional to recursion
        // depth and the bounded embedding batch.
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                $name = $node->getName();
                if (in_array($name, ['Thumbnails', '.appdata'], true) || str_starts_with($name, '.')) {
                    continue;
                }
                $childPath = $relativePath === '' ? $name : $relativePath . '/' . $name;
                if ($this->isPathExcluded($childPath, $excludePaths)) {
                    continue;
                }
                yield from $this->collectFilesGenerator($node, $depth + 1, $childPath, $excludePaths);
            } elseif ($node instanceof File && !str_starts_with($node->getName(), '.')) {
                yield [
                    'id' => $node->getId(),
                    'path' => $node->getPath(),
                    'name' => $node->getName(),
                    'size' => $node->getSize(),
                    'mime' => $node->getMimeType(),
                    'mtime' => method_exists($node, 'getMTime') ? (int)$node->getMTime() : 0,
                ];
            }
        }
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
        return $this->documentText()->isSupported($mime, $name);
    }



    /**
     * Liefert den durchsuchbaren Text zu einem File – auch aus PDF/DOCX/ODT/RTF/HTML.
     */
    public function extractTextForAgent(File $file, int $maxChars = 100000): string {
        $maxChars = max(1, min(100000, $maxChars));
        return mb_substr($this->extractText($file), 0, $maxChars);
    }

    private ?IndexDocumentText $documentText = null;

    private function documentText(): IndexDocumentText {
        return $this->documentText ??= new IndexDocumentText(new IndexTextExtractor($this->config, $this->logger), $this->email);
    }

    private function extractText(File $file): string {
        return $this->documentText()->file($file);
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
            $this->deleteIndexedDocuments([(int)$existing->getId()]);
        }
    }

    /** Keep optional vector generations and their remote tombstones in sync with canonical source deletion. */
    private function deleteIndexedDocuments(array $documentIds): void {
        if ($documentIds === []) return;
        if ($this->vectorSourceDeletion !== null) {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $documentIds))), 500) as $batch) {
                $this->vectorSourceDeletion->deleteDocuments($batch, true);
            }
            return;
        }
        // Older isolated unit fixtures construct Indexer without the vector lifecycle service.
        $this->chunkMapper->deleteByDocumentIds($documentIds);
        $this->documentMapper->deleteByIds($documentIds);
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

        $embeddingStartedAt = hrtime(true);
        [$vecs, $err] = $this->ollama->embedBatch($texts, $userId);
        $embeddingElapsedMs = max(0, (int)round((hrtime(true) - $embeddingStartedAt) / 1_000_000));
        $stats = $this->ollama->lastEmbeddingStats();
        $this->usageMetrics?->recordEmbeddingBatch(
            $userId,
            'ollama',
            ($stats['ollama_requests'] ?? 0) > 0 ? $embeddingElapsedMs : 0,
            $stats['cache_hits'] ?? 0,
            $stats['cache_misses'] ?? 0,
            $err === null && $vecs !== null,
        );
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
		$publishedChunks = 0;
        foreach ($perDoc as $docId => $chunks) {
            if ($this->cancellationRequested($runId)) {
                $this->discardStagedBatch($batch);
                $batch = [];
                return;
            }
            $entities=[];
            foreach ($chunks as $c) {
                $chunk = new Chunk();
                $chunk->setDocumentId($docId);
                $chunk->setChunkIndex($c['index']);
                $chunk->setContent($c['content']);
                $chunk->setEmbeddingArray($c['vec']);
                $chunk->setTokenCount($c['tokens']);
                $chunk->setProvenanceArray($c['provenance'] ?? []);
                $entities[]=$chunk;
            }
            $this->chunkMapper->insertMany($entities);
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
                        $this->deleteIndexedDocuments([$oldDocId]);
                        $this->logger->debug('eva_ai: Removed old document after successful re-index', ['oldDocId' => $oldDocId]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('eva_ai: Failed to remove old document after re-index', ['error' => $e->getMessage(), 'oldDocId' => $oldDocId]);
                }
            }
			$publishedChunks += count($chunks);
        }
		$result['file_chunks_indexed'] = (int)($result['file_chunks_indexed'] ?? 0) + $publishedChunks;
        if ($this->sqlGenerationProvisioner !== null && $userId !== null) {
            $model = $this->ollama->lastEmbeddingModel();
            $provenVectors = [];
            foreach ($vecs as $index => $vector) {
                if ($model !== null && is_array($vector) && $vector !== [] && isset($batch[$index]['content'])) {
                    // These exact text/vector pairs have just been validated
                    // by the configured provider. The provisioner may reuse
                    // them only under a matching immutable generation identity.
                    $provenVectors[] = ['text' => $batch[$index]['content'], 'vector' => $vector];
                }
            }
            if ($model !== null && $provenVectors !== []) {
                $dimension = count($provenVectors[0]['vector']);
                $this->sqlGenerationProvisioner->ensure($userId, $model, $dimension, $provenVectors);
            }
        }
        $batch = [];
        // Office/PDF parsers and HTTP JSON decoding can create cyclic
        // temporary graphs. PHP's reference-counting normally reclaims them,
        // but a long-lived Nextcloud worker benefits from an explicit cycle
        // collection at the same bounded batch boundary.
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
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
            $content = $this->documentText()->mail($userId, $mail);
            $this->touchHeartbeat($runId);
            if ($content === '' || trim($mail['subject']) === '') {
                continue;
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
        $stored = $this->documentMapper->fileIdsForSource($userId, Document::SOURCE_MAIL);
        foreach (self::staleMailFileIds($stored, $current) as $fileId) {
            $this->removeStaleDocument($userId, $fileId);
            $this->logger->info('eva_ai: Removed stale mail document (message deleted)', [
                'msgId' => -$fileId,
                'userId' => $userId,
            ]);
        }
    }

    /** @param list<int> $indexedFileIds Negative synthetic IDs for mail rows. @param list<int> $currentMessageIds Positive Mail message IDs. @return list<int> */
    public static function staleMailFileIds(array $indexedFileIds, array $currentMessageIds): array {
        $currentFileIds = [];
        foreach ($currentMessageIds as $messageId) {
            $messageId = (int)$messageId;
            if ($messageId > 0) {
                $currentFileIds[-$messageId] = true;
            }
        }
        $stale = [];
        foreach ($indexedFileIds as $fileId) {
            $fileId = (int)$fileId;
            if ($fileId < 0 && !isset($currentFileIds[$fileId])) {
                $stale[$fileId] = $fileId;
            }
        }
        return array_values($stale);
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
            $content = $this->documentText()->talk($transcript['text']);
            if (trim($content) === '') {
                continue;
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
                $this->logger->info('eva_ai: Removed stale document', ['fileId' => $fileId, 'userId' => $userId]);
            }
        }
        $this->deleteIndexedDocuments($docIds);
        
        // Also remove documents that are now excluded by exclude_paths configuration
        $this->cleanupExcluded($userId);
    }
    
    private function cleanupExcluded(string $userId): void {
        $excludePaths = $this->parseExcludePaths();
        if (empty($excludePaths)) {
            return;
        }
        
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
            $this->deleteIndexedDocuments($excludedDocIds);
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
            $chunks = $this->chunkMapper->deleteForUser($userId);
            $docs = $this->documentMapper->deleteByUser($userId);
            $this->embeddingCache->clearUser($userId);
        } else {
            // A null reset is the explicit all-users/maintenance path. Clear
            // any stale request context before writing the global state.
            $this->config->setUserId(null);
            $chunks = $this->chunkMapper->deleteAll();
            $docs = $this->documentMapper->deleteAll();
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
