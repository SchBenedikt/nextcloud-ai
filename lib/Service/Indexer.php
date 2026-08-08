<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCA\RagChat\Db\Chunk;
use OCA\RagChat\Db\ChunkMapper;
use OCA\RagChat\Db\Document;
use OCA\RagChat\Db\DocumentMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class Indexer {
    private const BATCH = 24;
    private const MAX_DEPTH = 30;

    public function __construct(
        private AppConfig $config,
        private IRootFolder $rootFolder,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private Chunker $chunker,
        private Ollama $ollama,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Perform one bounded indexing pass for a user.
     * @return array{processed:int,changed:int,skipped:int,total_seen:int,error:?string}
     */
    public function run(string $userId, ?int $maxFiles = null): array {
        $maxFiles = $maxFiles ?? $this->config->getInt('max_files_per_run', 40);
        $result = ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'total_seen' => 0, 'error' => null];

        $this->config->set('index_running', '1');
        $this->config->set('index_started', (string)time());

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $scope = $this->config->get('scope_path');
            $root = $userFolder;
            if ($scope !== '') {
                try {
                    $node = $userFolder->get($scope);
                    if ($node instanceof Folder) {
                        $root = $node;
                    }
                } catch (NotFoundException $e) {
                    $result['error'] = 'Scope path not found: /' . $scope;
                    return $result;
                }
            }

            $files = [];
            $this->collectFiles($root, $files, 0);

            $hashes = $this->documentMapper->hashesForUser($userId);
            $seen = [];
            $batch = [];
            $maxSize = $this->config->getInt('max_file_size', 20971520);

            foreach ($files as $file) {
                $fileId = (int)$file->getId();
                $seen[$fileId] = true;
                $result['total_seen']++;

                if (!$this->isIndexable($file, $maxSize)) {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $content = $this->extractText($file);
                } catch (\Throwable $e) {
                    $this->logger->warning('ragchat: read failed', ['file' => $file->getPath(), 'e' => $e->getMessage()]);
                    $result['skipped']++;
                    continue;
                }
                if ($content === '') {
                    $result['skipped']++;
                    continue;
                }

                $hash = md5($content);
                if (($hashes[$fileId] ?? null) === $hash) {
                    $result['skipped']++;
                    continue;
                }

                $chunks = $this->chunker->chunk($content);
                if (empty($chunks)) {
                    $result['skipped']++;
                    continue;
                }

                $path = $this->relativePath($userId, $file->getPath());
                $name = $file->getName();
                $mime = $file->getMimeType();
                $size = $file->getSize();

                $this->removeStaleDocument($userId, $fileId);
                $doc = new Document();
                $doc->setUserId($userId);
                $doc->setFileId($fileId);
                $doc->setPath($path);
                $doc->setName($name);
                $doc->setMime($mime);
                $doc->setSize($size);
                $doc->setContentHash($hash);
                $doc->setChunkCount(count($chunks));
                $doc->setIndexedAt(time());
                $this->documentMapper->insert($doc);

                foreach ($chunks as $i => $c) {
                    $batch[] = ['docId' => (int)$doc->getId(), 'index' => $i, 'content' => $c['content'], 'tokens' => $c['tokens']];
                }

                $result['processed']++;
                $result['changed']++;

                if (count($batch) >= self::BATCH || $result['processed'] >= $maxFiles) {
                    $this->flushBatch($batch, $result);
                }
                if ($result['processed'] >= $maxFiles) {
                    break;
                }
            }

            $this->flushBatch($batch, $result);
            $this->cleanupRemoved($userId, $seen);

            if ($result['error'] === null && $result['processed'] === 0 && $result['total_seen'] > 0) {
                $result['error'] = null; // up to date
            }
        } catch (\Throwable $e) {
            $this->logger->error('ragchat index run failed', ['exception' => $e]);
            $result['error'] = $e->getMessage();
        } finally {
            $this->config->set('index_running', '0');
            $this->config->set('index_finished', (string)time());
            $this->config->set('last_index_processed', (string)$result['processed']);
            $this->config->set('last_index_total', (string)$result['total_seen']);
            if ($result['error'] !== null) {
                $this->config->set('last_index_error', $result['error']);
            }
        }

        return $result;
    }

    private function collectFiles(Folder $folder, array &$out, int $depth): void {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                $name = $node->getName();
                if (in_array($name, ['Thumbnails', '.appdata'], true) || str_starts_with($name, '.')) {
                    continue;
                }
                $this->collectFiles($node, $out, $depth + 1);
            } elseif ($node instanceof File) {
                if (str_starts_with($node->getName(), '.')) {
                    continue;
                }
                $out[] = $node;
            }
        }
    }

    private function isIndexable(File $file, int $maxSize): bool {
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

        // Unbekannte Formate: nur lesen, wenn offensichtlich Text (kein  ).
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
                $xml = $zip->getFromName($target);
                if ($xml !== false) {
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
                while (($entry = $zip->getNameIndex($i)) !== false) {
                    if (str_ends_with($entry, '.htm') || str_ends_with($entry, '.html') || str_ends_with($entry, '.xhtml')) {
                        $html = $zip->getFromName($entry);
                        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html ?? '');
                        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html ?? '');
                        $html = preg_replace('/<[^>]+>/', ' ', $html ?? '');
                        $out .= ' ' . html_entity_decode($html ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    $i++;
                }
            } elseif ($kind === 'xlsx') {
                $xml = $zip->getFromName('xl/sharedStrings.xml');
                if ($xml !== false) {
                    preg_match_all('/<si>(.*?)<\/si>/is', $xml, $si);
                    foreach ($si[1] as $cell) {
                        preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $cell, $tm);
                        $out .= ' ' . implode(' ', $tm[1]);
                    }
                }
            } elseif ($kind === 'pptx') {
                $i = 0;
                while (($entry = $zip->getNameIndex($i)) !== false) {
                    if (preg_match('~^ppt/slides/slide\d+\.xml$~', $entry)) {
                        $xml = $zip->getFromName($entry);
                        preg_match_all('/<a:t(?:\s[^>]*)?>(.*?)<\/a:t>/is', $xml ?? '', $tm);
                        $out .= ' ' . implode(' ', $tm[1]);
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

    private function flushBatch(array &$batch, array &$result): void {
        if (empty($batch)) {
            return;
        }
        $texts = [];
        foreach ($batch as $b) {
            $texts[] = $b['content'];
        }
        [$vecs, $err] = $this->ollama->embedBatch($texts);
        if ($err !== null || $vecs === null) {
            $result['error'] = $err ?? 'Embedding error';
            $this->logger->error('ragchat embedding failed', ['error' => $err]);
            // Remove docs created this batch so a later run retries them.
            $docIds = [];
            foreach ($batch as $b) {
                $docIds[$b['docId']] = true;
            }
            $this->chunkMapper->deleteByDocumentIds(array_keys($docIds));
            $this->documentMapper->deleteByIds(array_keys($docIds));
            $batch = [];
            return;
        }

        $perDoc = [];
        foreach ($batch as $i => $b) {
            $perDoc[$b['docId']][] = ['index' => $b['index'], 'content' => $b['content'], 'tokens' => $b['tokens'], 'vec' => $vecs[$i]];
        }
        foreach ($perDoc as $docId => $chunks) {
            foreach ($chunks as $c) {
                $chunk = new Chunk();
                $chunk->setDocumentId($docId);
                $chunk->setChunkIndex($c['index']);
                $chunk->setContent($c['content']);
                $chunk->setEmbeddingArray($c['vec']);
                $chunk->setTokenCount($c['tokens']);
                $this->chunkMapper->insert($chunk);
            }
            $doc = $this->documentMapper->findById($docId);
            if ($doc !== null) {
                $doc->setChunkCount(count($chunks));
                $this->documentMapper->update($doc);
            }
        }
        $batch = [];
    }

    private function cleanupRemoved(string $userId, array $seen): void {
        $stored = $this->documentMapper->findFileIdsForUser($userId);
        $removed = array_diff($stored, array_keys($seen));
        if (empty($removed)) {
            return;
        }
        $docIds = [];
        foreach ($removed as $fileId) {
            $doc = $this->documentMapper->findByUserAndFile($userId, (int)$fileId);
            if ($doc !== null) {
                $docIds[] = (int)$doc->getId();
                $this->documentMapper->delete($doc);
            }
        }
        $this->chunkMapper->deleteByDocumentIds($docIds);
    }

    /**
     * Delete the complete RAG index (documents + chunks) for one user
     * or for ALL users. Resets the index state flags as well.
     * @return array{documents:int,chunks:int}
     */
    public function reset(?string $userId = null): array {
        if ($userId !== null && $userId !== '') {
            $docs = $this->documentMapper->deleteByUser($userId);
            $chunks = $this->chunkMapper->deleteForUser($userId);
        } else {
            $docs = $this->documentMapper->deleteAll();
            $chunks = $this->chunkMapper->deleteAll();
        }
        $this->config->set('index_running', '0');
        $this->config->set('index_finished', '0');
        $this->config->set('index_started', '');
        $this->config->set('last_index_total', '0');
        $this->config->set('last_index_processed', '0');
        $this->config->set('last_index_error', '');
        return ['documents' => $docs, 'chunks' => $chunks];
    }
}