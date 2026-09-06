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
    private const BATCH = 24;
    private const MAX_DEPTH = 30;
    private const MAX_DECOMPRESSED_BYTES = 104857600; // 100MB limit for decompressed content
    private const MAX_ZIP_ENTRIES = 1000; // Maximum number of ZIP entries to process

    public function __construct(
        private AppConfig $config,
        private IRootFolder $rootFolder,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private Chunker $chunker,
        private Ollama $ollama,
        private EmbeddingCache $embeddingCache,
        private EmailService $email,
        private LoggerInterface $logger,
        private ILockingProvider $lockingProvider
    ) {
    }

    /**
     * Perform one bounded indexing pass for a user.
     * @return array{processed:int,changed:int,skipped:int,total_seen:int,cache_hits:int,cache_misses:int,ollama_requests:int,error:?string}
     */
    public function run(string $userId, ?int $maxFiles = null, string $mode = 'all', bool $keepRunning = false, ?string $runId = null): array {
        $this->config->setUserId($userId);
        $mode = in_array($mode, ['all', 'files', 'mail'], true) ? $mode : 'all';
        $maxFiles = $maxFiles ?? min(10000, max(1, $this->config->getInt('max_files_per_run', 40)));
        $result = [
            'processed' => 0,
            'changed' => 0,
            'skipped' => 0,
            'total_seen' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'ollama_requests' => 0,
            'error' => null,
        ];
        $lockPath = 'eva_ai/index/' . hash('sha256', $userId);
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'EVA index for ' . $userId);
        } catch (\Throwable $e) {
            $result['error'] = 'Indexing is already running for this user.';
            return $result;
        }
        if ($runId === null) {
            // OCC/manual runs and the periodic worker share the same
            // per-user claim. A second worker exits before any DB mutation.
            if ($this->config->get('index_running') === '1') {
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

        $this->config->set('index_running', '1');
        $this->config->set('index_started', (string)time());
        $this->config->set('index_heartbeat', (string)time());
        $this->config->set('last_index_error', '');
        $this->config->set('last_index_cache_hits', '0');
        $this->config->set('last_index_cache_misses', '0');
        $this->config->set('last_index_ollama_requests', '0');
        
        // Calculate the file-index configuration hash only for file passes.
        // A mail-only request must not invalidate the user's file index.
        if ($mode !== 'mail') {
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
            if ($mode !== 'mail') {
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

            $hashes = $this->documentMapper->hashesForUser($userId);
            $seen = [];
            $stale = []; // Track files that should be removed from index
            $batch = [];
            $maxSize = $this->config->getInt('max_file_size', 20971520);
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
                $this->config->set('index_heartbeat', (string)time());
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

                try {
                    // Get the actual file for content extraction
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
                    // Parser/decompressor/OCR failures are transient. Preserve
                    // the last-good version and retry on a later pass.
                    $result['skipped']++;
                    $result['error'] ??= 'Transient extraction failure for ' . $file->getPath();
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
                    $result['skipped']++;
                    continue;
                }

                $chunks = $this->chunker->chunk($content);
                unset($content);
                if (empty($chunks)) {
                    $result['skipped']++;
                    continue;
                }

                $path = $this->relativePath($userId, $file->getPath());
                $name = $file->getName();
                $mime = $file->getMimeType();
                $size = $file->getSize();

                // Check if document already exists to preserve old version on failure
                $existingDoc = $this->documentMapper->findByUserAndFile($userId, $fileId);
                $oldDocId = $existingDoc !== null ? (int)$existingDoc->getId() : null;
                
                $doc = new Document();
                $doc->setUserId($userId);
                $doc->setFileId($fileId);
                $doc->setPath($path);
                $doc->setName($name);
                $doc->setMime($mime);
                $doc->setSize($size);
                $doc->setContentHash($hash);
                $doc->setChunkCount(0); // Publish only after every embedding batch succeeds.
                $doc->setIndexedAt(time());
                $this->documentMapper->insert($doc);

                foreach ($chunks as $i => $c) {
                    $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens'], 'provenance' => $c['provenance'] ?? [], 'oldDocId' => $oldDocId];
                }

                $result['processed']++;
                $result['changed']++;

                if (count($batch) >= self::BATCH || $result['processed'] >= $maxFiles) {
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
            if (!$cancelled && $mode !== 'mail') {
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
            if (!$cancelled && $mode !== 'files') {
                $this->indexEmails($userId, $result, $maxFiles, $mode === 'mail', $runId);
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
                    $this->config->set('last_index_cache_hits', (string)$result['cache_hits']);
                    $this->config->set('last_index_cache_misses', (string)$result['cache_misses']);
                    $this->config->set('last_index_ollama_requests', (string)$result['ollama_requests']);
                    if ($result['error'] !== null) {
                        $this->config->set('last_index_error', $result['error']);
                    }
                }
            } finally {
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            }
        }

        return $result;
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
            } elseif ($node instanceof File) {
                if (str_starts_with($node->getName(), '.')) {
                    continue;
                }
                yield [
                    'id' => $node->getId(),
                    'path' => $node->getPath(),
                    'name' => $node->getName(),
                    'size' => $node->getSize(),
                    'mime' => $node->getMimeType()
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
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, DocumentExtractor::OFFICE, true)
            || ($this->config->get('ocr_enabled') === '1' && in_array($extension, ['png', 'jpg', 'jpeg', 'tif', 'tiff'], true))) { return true; }
        if ($mime === null) {
            return false;
        }
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        $allowed = [
            'application/json', 'application/xml', 'application/x-empty',
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
    private function extractText(File $file): string {
        $mime = $file->getMimeType() ?? '';
        $name = strtolower($file->getName());
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (in_array($extension, DocumentExtractor::OFFICE, true)) { return (new DocumentExtractor())->extract($file); }
        if ($extension === 'pdf' || ($this->config->get('ocr_enabled') === '1' && in_array($extension, ['png','jpg','jpeg','tif','tiff'], true))) {
            $tmp = tempnam(sys_get_temp_dir(), 'eva_source_');
            if ($tmp === false) { throw new \RuntimeException('Cannot create extraction file'); }
            try {
                $in = $file->fopen('r'); $out = fopen($tmp, 'wb');
                if (!is_resource($in) || !is_resource($out)) { throw new \RuntimeException('Cannot read extraction source'); }
                try { stream_copy_to_stream($in, $out, 33554433); } finally { fclose($in); fclose($out); }
                if (filesize($tmp) > 33554432) { throw new \RuntimeException('Extraction source exceeds 32 MiB'); }
                return $extension === 'pdf' ? SystemExtractor::pdf($tmp, $this->config->get('ocr_enabled') === '1') : SystemExtractor::image($tmp);
            } finally { @unlink($tmp); }
        }

        if (str_starts_with($mime, 'text/')) {
            $raw = (string)$file->getContent();
            if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || str_ends_with($name, '.html') || str_ends_with($name, '.htm')) {
                $raw = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $raw ?? '');
                $raw = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $raw ?? '');
                $raw = preg_replace('/<[^>]+>/', ' ', $raw ?? '');
                $raw = html_entity_decode($raw ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $this->normalize($raw ?? '');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // DOCX + Varianten (DOTX/DOCM) / ODT / EPUB / ODS / ODP: Zip-Container.
        if (in_array($ext, ['docx', 'docm', 'dotx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.template') {
            return $this->zipWebText($file, 'docx');
        }
        if ($ext === 'odt' || $mime === 'application/vnd.oasis.opendocument.text' || $mime === 'application/vnd.oasis.opendocument.spreadsheet' || $mime === 'application/vnd.oasis.opendocument.presentation') {
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
            if ($txt !== null) {
                return $this->normalize($txt);
            }
        }

        // Unbekannte Formate: nur lesen, wenn offensichtlich Text (kein \0).
        $raw = (string)$file->getContent();
        if (strpos($raw, "\0") !== false) {
            return '';
        }
        return $this->normalize($raw);
    }

    private function zipWebText(File $file, string $kind): string {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rg_');
        $out = '';
        $totalDecompressed = 0;
        $entriesProcessed = 0;
        
        try {
            if ($tmp === false) {
                return '';
            }
            file_put_contents($tmp, $file->getContent());
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return '';
            }
            
            $target = $kind === 'docx' ? 'word/document.xml' : ($kind === 'odf' ? 'content.xml' : '');
            if ($target !== '') {
                // Check entry size before reading
                $stat = $zip->statName($target);
                if ($stat !== false && $stat['size'] > self::MAX_DECOMPRESSED_BYTES) {
                    $this->logger->warning('eva_ai: ZIP entry too large', ['file' => $file->getPath(), 'entry' => $target, 'size' => $stat['size']]);
                    return '';
                }
                
                $xml = $zip->getFromName($target);
                if ($xml !== false) {
                    $totalDecompressed += strlen($xml);
                    if ($totalDecompressed > self::MAX_DECOMPRESSED_BYTES) {
                        $this->logger->warning('eva_ai: Decompressed content exceeds limit', ['file' => $file->getPath(), 'bytes' => $totalDecompressed]);
                        return '';
                    }
                    
                    if ($kind === 'docx') {
                        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/is', $xml, $m);
                        $out = implode(' ', $m[1]);
                    } else {
                        preg_match_all('/<text:p[^>]*>|<text:h[^>]*>(.*?)/is', $xml, $m);
                        $plain = preg_replace('/<[^>]+>/', ' ', $xml);
                        $out = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
            } elseif ($kind === 'epub') {
                $i = 0;
                while (($entry = $zip->getNameIndex($i)) !== false && $entriesProcessed < self::MAX_ZIP_ENTRIES) {
                    $entriesProcessed++;
                    if (str_ends_with($entry, '.htm') || str_ends_with($entry, '.html') || str_ends_with($entry, '.xhtml')) {
                        // Check entry size before reading
                        $stat = $zip->statIndex($i);
                        if ($stat !== false && $stat['size'] > self::MAX_DECOMPRESSED_BYTES) {
                            $this->logger->warning('eva_ai: EPUB entry too large, skipping', ['file' => $file->getPath(), 'entry' => $entry, 'size' => $stat['size']]);
                            $i++;
                            continue;
                        }
                        
                        $html = $zip->getFromName($entry);
                        if ($html !== false) {
                            $totalDecompressed += strlen($html);
                            if ($totalDecompressed > self::MAX_DECOMPRESSED_BYTES) {
                                $this->logger->warning('eva_ai: EPUB decompressed content exceeds limit', ['file' => $file->getPath(), 'bytes' => $totalDecompressed]);
                                break;
                            }
                            
                            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html ?? '');
                            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html ?? '');
                            $html = preg_replace('/<[^>]+>/', ' ', $html ?? '');
                            $out .= ' ' . html_entity_decode($html ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }
                    }
                    $i++;
                }
            } elseif ($kind === 'xlsx') {
                // Check entry size before reading
                $stat = $zip->statName('xl/sharedStrings.xml');
                if ($stat !== false && $stat['size'] > self::MAX_DECOMPRESSED_BYTES) {
                    $this->logger->warning('eva_ai: XLSX sharedStrings too large', ['file' => $file->getPath(), 'size' => $stat['size']]);
                    return '';
                }
                
                $xml = $zip->getFromName('xl/sharedStrings.xml');
                if ($xml !== false) {
                    $totalDecompressed += strlen($xml);
                    if ($totalDecompressed > self::MAX_DECOMPRESSED_BYTES) {
                        $this->logger->warning('eva_ai: XLSX decompressed content exceeds limit', ['file' => $file->getPath(), 'bytes' => $totalDecompressed]);
                        return '';
                    }
                    
                    preg_match_all('/<si>(.*?)<\/si>/is', $xml, $si);
                    foreach ($si[1] as $cell) {
                        preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $cell, $tm);
                        $out .= ' ' . implode(' ', $tm[1]);
                    }
                }
            } elseif ($kind === 'pptx') {
                $i = 0;
                while (($entry = $zip->getNameIndex($i)) !== false && $entriesProcessed < self::MAX_ZIP_ENTRIES) {
                    $entriesProcessed++;
                    if (preg_match('~^ppt/slides/slide\d+\.xml$~', $entry)) {
                        // Check entry size before reading
                        $stat = $zip->statIndex($i);
                        if ($stat !== false && $stat['size'] > self::MAX_DECOMPRESSED_BYTES) {
                            $this->logger->warning('eva_ai: PPTX slide too large, skipping', ['file' => $file->getPath(), 'entry' => $entry, 'size' => $stat['size']]);
                            $i++;
                            continue;
                        }
                        
                        $xml = $zip->getFromName($entry);
                        if ($xml !== false) {
                            $totalDecompressed += strlen($xml);
                            if ($totalDecompressed > self::MAX_DECOMPRESSED_BYTES) {
                                $this->logger->warning('eva_ai: PPTX decompressed content exceeds limit', ['file' => $file->getPath(), 'bytes' => $totalDecompressed]);
                                break;
                            }
                            
                            preg_match_all('/<a:t(?:\s[^>]*)?>(.*?)<\/a:t>/is', $xml ?? '', $tm);
                            $out .= ' ' . implode(' ', $tm[1]);
                        }
                    }
                    $i++;
                }
            }
            $zip->close();
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
            shell_exec(escapeshellarg($bin) . ' -enc UTF-8 ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' 2>/dev/null');
            $txt = file_exists($tmpOut) ? (string)file_get_contents($tmpOut) : '';
            @unlink($tmpOut);
            return $txt === '' ? NULL : $txt;
        } finally {
            if (file_exists($tmpIn)) {
                @unlink($tmpIn);
            }
        }
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
        $perDoc = [];
        try {
            // Keep vectors/request payloads bounded even for a single very large document.
            for ($offset = 0; $offset < count($batch); $offset += self::BATCH) {
                if ($this->cancellationRequested($runId)) { throw new \RuntimeException('Indexing cancelled'); }
                $this->touchHeartbeat($runId);
                $slice = array_slice($batch, $offset, self::BATCH);
                [$vecs, $err] = $this->ollama->embedBatch(array_column($slice, 'content'), $userId);
                $stats = $this->ollama->lastEmbeddingStats();
                foreach (['cache_hits', 'cache_misses', 'ollama_requests'] as $key) { $result[$key] += $stats[$key]; }
                if ($err !== null || !is_array($vecs) || count($vecs) !== count($slice)) { throw new \RuntimeException($err ?? 'Incomplete embedding batch'); }
                if ($this->cancellationRequested($runId)) { throw new \RuntimeException('Indexing cancelled'); }
                foreach ($slice as $i => $b) {
                    $chunk = new Chunk();
                    $chunk->setDocumentId($b['docId']);
                    $chunk->setChunkIndex($b['index']);
                    $chunk->setContent($b['content']);
                    $chunk->setEmbeddingArray($vecs[$i]);
                    $chunk->setTokenCount($b['tokens']);
                    $chunk->setProvenance(json_encode($b['provenance'] ?? [], JSON_THROW_ON_ERROR));
                    $this->chunkMapper->insert($chunk);
                    $perDoc[$b['docId']] = $b['oldDocId'];
                }
                unset($vecs, $slice);
                $this->touchHeartbeat($runId);
            }
            if ($this->cancellationRequested($runId)) { throw new \RuntimeException('Indexing cancelled'); }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->discardStagedBatch($batch);
            $batch = [];
            return;
        }
        foreach ($perDoc as $docId => $oldDocId) {
            if ($this->cancellationRequested($runId)) {
                // Retain already published documents; discard only the remaining staging rows.
                $remaining = array_filter($batch, static fn($b) => isset($perDoc[$b['docId']]));
                $this->discardStagedBatch($remaining);
                $batch = [];
                return;
            }
            $doc = $this->documentMapper->findById($docId);
            if ($doc === null) { unset($perDoc[$docId]); continue; }
            $doc->setChunkCount($this->chunkMapper->countForDocument($docId));
            $this->documentMapper->update($doc);
            unset($perDoc[$docId]);
            if ($oldDocId !== null) {
                try {
                    $oldDoc = $this->documentMapper->findById($oldDocId);
                    if ($oldDoc !== null) { $this->chunkMapper->deleteByDocument($oldDocId); $this->documentMapper->delete($oldDoc); }
                } catch (\Throwable $e) { $this->logger->warning('EVA old index version cleanup failed', ['exception' => $e]); }
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
                $body = $this->email->bodyText($msgId);
            } catch (\Throwable $e) {
            }
            $this->touchHeartbeat($runId);
            $from = $mail['from'];
            $to = implode(', ', (array)($mail['to'] ?? []));
            $content = "EMAIL\nFrom: " . $from . "\nTo: " . $to . "\nDate: " . date('Y-m-d H:i', (int)$mail['sent'])
                . "\nSubject: " . $mail['subject'] . "\n\n" . trim($body);
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
            $contentSize = strlen($content);
            $chunks = $this->chunker->chunk($content);
            unset($content);
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
            $doc->setSize($contentSize);
            $doc->setContentHash($hash);
            $doc->setChunkCount(0); // Publish only after every embedding batch succeeds.
            $doc->setIndexedAt(time());
            $this->documentMapper->insert($doc);
            foreach ($chunks as $i => $c) {
                $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens'], 'provenance' => $c['provenance'] ?? [], 'oldDocId' => $oldDocId];
            }
            $result['processed']++;
            $processedThisPass++;
            if (count($batch) >= self::BATCH) {
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
     * Remove indexed mail documents (negative file ids) whose message was
     * deleted from the Mail account. Privacy fix: deleted emails must stop
     * being searchable through the RAG index even without a full reindex.
     */
    private function reconcileMailIndex(string $userId): void {
        try {
            $current = $this->email->allMessageIds($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: mail reconciliation failed', ['e' => $e->getMessage()]);
            return;
        }
        $currentSet = array_flip($current);
        $stored = $this->documentMapper->mailFileIdsForUser($userId);
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
     */
    private function touchHeartbeat(?string $runId = null): void {
        if ($runId !== null && $this->config->get('index_run_id') !== $runId) {
            return;
        }
        $this->config->set('index_heartbeat', (string)time());
    }

    private function cancellationRequested(?string $runId = null): bool {
        return $this->config->get('index_cancel_requested') === '1'
            || ($runId !== null && $this->config->get('index_run_id') !== $runId);
    }

    private function calculateConfigHash(): string {
        $configKey = implode('|', [
            'extraction-v2', $this->config->get('ocr_enabled'),
            $this->config->get('embedding_model', 'default'),
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