<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Server;

/** Executes the user's bounded file and note operations. */
final class FileToolExecutor implements DomainToolExecutor {
    private const MAX_SEARCH_DEPTH = 5;
    private const MAX_SEARCH_NODES = 2000;
    private const MAX_SEARCH_FILE_BYTES = 1048576;
    private const MAX_SEARCH_DOCUMENT_BYTES = 8388608;
    private const MAX_SEARCH_EXTRACT_FILES = 40;
    private const MAX_SEARCH_RESULTS = 50;
    private const MAX_SEARCH_DEPTH_CONFIG = 10;
    private const MAX_SEARCH_NODES_CONFIG = 10000;
    private const MAX_SEARCH_RESULTS_CONFIG = 100;
    private const SEARCH_CACHE_TTL = 15;
    private const MAX_LIST_DEPTH = 2;
    private const MAX_LIST_ENTRIES = 300;
    private const MAX_READ_CHARS = 20000;
    private const MAX_READ_CHUNK_CHARS = 100000;
    private const MAX_READ_FILE_BYTES = 8388608;
    private const MAX_WRITE_CHARS = 100000;
    private const KNOWLEDGE_MAX_CHARS = 60000;
    private const KNOWLEDGE_TARGET_CHARS = 45000;
    private const KNOWLEDGE_PROFILE_MARKER = '<!-- eva_ai:profile-initialized -->';
    private const NOTES_FOLDER = 'Notes';

    public function __construct(private IRootFolder $rootFolder, private AppConfig $config, private ?Indexer $indexer = null) {
    }

    public function tools(): array {
        return ['list_files', 'create_file', 'create_files', 'create_note', 'list_notes', 'read_note', 'update_note', 'create_folder', 'rename_file', 'move_file', 'copy_file', 'file_checksum', 'delete_file', 'read_file', 'read_files', 'extract_file_text', 'inspect_file', 'search_files', 'update_knowledge', 'list_learned_file_locations'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        if ($tool === 'list_learned_file_locations') {
            return $this->listLearnedFileLocations();
        }
        if (PHP_SAPI === 'cli') {
            \OC_Util::setupFS($userId);
        }
        try {
            $home = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'File tools are not available for this user.'];
        }
        return match ($tool) {
            'list_files' => $this->listFiles($home, $args),
            'create_file' => $this->createFile($home, $args),
            'create_files' => $this->createFiles($home, $args),
            'create_note' => $this->createNote($home, $args),
            'list_notes' => $this->listNotes($home, $args),
            'read_note' => $this->readNote($home, $args),
            'update_note' => $this->updateNote($home, $args),
            'create_folder' => $this->createFolder($home, $args),
            'rename_file' => $this->renameFile($home, $args),
            'move_file' => $this->moveFile($home, $args),
            'copy_file' => $this->copyFile($home, $args),
            'file_checksum' => $this->fileChecksum($home, $args),
            'delete_file' => $this->deleteFile($home, $args),
            'read_file' => $this->readFile($home, $args),
            'read_files' => $this->readFiles($home, $args),
            'extract_file_text' => $this->extractFileText($home, $args),
            'inspect_file' => $this->inspectFile($home, $args),
            'search_files' => $this->searchFiles($home, $args),
            'update_knowledge' => $this->updateKnowledge($home, $args),
            'list_learned_file_locations' => $this->listLearnedFileLocations(),
            default => ['ok' => false, 'error' => 'Unsupported file tool: ' . $tool],
        };
    }

/** @return array<array{name:string,path:string,type:string,size?:int}> */
    private function listFiles(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $folder = $this->folderAt($home, $path);
        $rootLen = strlen($folder->getPath()) + 1;
        $out = [];
        $count = 0;
        $this->walk($folder, $out, 0, $count, $rootLen);
        $this->rememberFileLocations($out);
        return ['ok' => true, 'result' => $out];
    }

    private function walk(Folder $folder, array &$out, int $depth, int &$count, int $rootLen): void {
        if ($depth >= self::MAX_LIST_DEPTH || $count >= self::MAX_LIST_ENTRIES) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($count >= self::MAX_LIST_ENTRIES) {
                return;
            }
            $count++;
            $rel = substr($node->getPath(), $rootLen);
            if ($node instanceof File) {
                $out[] = ['name' => $node->getName(), 'path' => $rel, 'type' => 'file', 'file_id' => (int)$node->getId(), 'size' => $node->getSize()];
            } elseif ($node instanceof Folder) {
                $out[] = ['name' => $node->getName(), 'path' => $rel, 'type' => 'folder', 'file_id' => (int)$node->getId()];
                if ($depth + 1 < self::MAX_LIST_DEPTH) {
                    $this->walk($node, $out, $depth + 1, $count, $rootLen);
                }
            }
        }
    }

    /**
     * Prepare a confirmation payload for text file changes without writing.
     * The expected checksum is returned with the arguments and checked again
     * by runConfirmed() immediately before the change is applied.
     */
    private function buildFileChangeConfirmation(string $userId, string $name, array $args, string $risk): array {
        try {
            if (PHP_SAPI === 'cli') {
                \OC_Util::setupFS($userId);
            }
            $home = $this->rootFolder->getUserFolder($userId);
            $path = $this->fileChangePath($name, $args);
            if ($path === '') {
                return ['ok' => false, 'error' => 'A valid file path is required before EVA can prepare a preview.'];
            }
            $old = '';
            $expected = '__missing__';
            try {
                $node = $home->get($path);
                if (!$node instanceof File) {
                    return ['ok' => false, 'error' => 'The preview target is not a file.'];
                }
                if ($node->getSize() > self::MAX_READ_FILE_BYTES) {
                    return ['ok' => false, 'error' => 'This file is too large to preview safely; EVA has not changed it.'];
                }
                $old = (string)$node->getContent();
                $expected = hash('sha256', $old);
            } catch (NotFoundException) {
                if ($name === 'update_note' || $name === 'delete_file') {
                    return ['ok' => false, 'error' => 'The file no longer exists; EVA has not changed it.'];
                }
            }

            if ($name === 'delete_file') {
                $new = '';
            } elseif ($name === 'create_note' || $name === 'update_note') {
                $new = (string)($args['content'] ?? '');
            } elseif (array_key_exists('content_base64', $args)) {
                $decoded = base64_decode((string)$args['content_base64'], true);
                $new = $decoded === false ? "\0" : $decoded;
            } else {
                $new = (string)($args['content'] ?? '');
            }
            $preview = (new DiffService())->generateDiff($old, $new);
            $args['_eva_expected_sha256'] = $expected;
            $args['_eva_preview_path'] = $path;
            $args['_eva_preview'] = $preview + [
                'path' => $path,
                'action' => $name === 'delete_file' ? 'delete' : ($expected === '__missing__' ? 'create' : 'update'),
            ];
            return [
                'ok' => false,
                'confirmation_required' => true,
                'tool' => $name,
                'arguments' => $args,
                'risk' => $risk,
                'error' => 'Review the proposed file change before applying it.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'A file preview could not be prepared; EVA has not changed the file.'];
        }
    }

    private function fileChangePath(string $name, array $args): string {
        $path = $name === 'create_note'
            ? trim((string)($args['title'] ?? ''))
            : trim((string)($args['path'] ?? ''));
        if ($name === 'create_note' || $name === 'update_note') {
            if (!str_ends_with(strtolower($path), '.md')) {
                $path .= '.md';
            }
            if (strpos($path, '/') === false) {
                $path = self::NOTES_FOLDER . '/' . $path;
            }
        }
        return $this->cleanPath($path);
    }

    /** @return array{ok:bool,error?:string,content?:string} */
    private function validateFileChangeSnapshot(string $userId, string $name, array $args): array {
        try {
            if (PHP_SAPI === 'cli') {
                \OC_Util::setupFS($userId);
            }
            $home = $this->rootFolder->getUserFolder($userId);
            $path = (string)($args['_eva_preview_path'] ?? $this->fileChangePath($name, $args));
            $expected = (string)$args['_eva_expected_sha256'];
            $currentContent = '';
            try {
                $node = $home->get($path);
                if (!$node instanceof File || $node->getSize() > self::MAX_READ_FILE_BYTES) {
                    return ['ok' => false, 'error' => 'The file changed after the preview. Ask EVA to prepare a new preview.'];
                }
                $currentContent = (string)$node->getContent();
                $actual = hash('sha256', $currentContent);
            } catch (NotFoundException) {
                $actual = '__missing__';
            }
            if (!hash_equals($expected, $actual)) {
                return ['ok' => false, 'error' => 'The file changed after the preview. Ask EVA to prepare a new preview.'];
            }
            return ['ok' => true, 'content' => $currentContent];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The preview could not be verified; EVA has not changed the file.'];
        }
    }

    /** @return array{ok:true,result:string} */
    private function createFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $binary = array_key_exists('content_base64', $args);
        if ($binary) {
            $decoded = base64_decode((string)$args['content_base64'], true);
            if ($decoded === false || $decoded === '') return ['ok' => false, 'error' => 'content_base64 must be non-empty valid base64'];
            $content = $decoded;
        } else {
            $content = (string)($args['content'] ?? '');
        }
        if ($path === '' || str_ends_with($path, '/')) {
            return ['ok' => false, 'error' => 'A valid file path is required'];
        }
        if ($content === '') {
            return ['ok' => false, 'error' => 'File content must not be empty'];
        }
        $maxChars = (int)$this->config->get('exec_write_max_chars') ?: 100000;
        if (($binary ? strlen($content) : mb_strlen($content)) > $maxChars) {
            return ['ok' => false, 'error' => 'File content exceeds ' . $maxChars . ' characters'];
        }
        if (!$binary && strpos($content, "\0") !== false) {
            return ['ok' => false, 'error' => 'Only text files can be created'];
        }
        [, $name] = $this->splitPath($path);
        $typeError = $this->checkWriteType($name);
        if ($typeError !== null) {
            return ['ok' => false, 'error' => $typeError];
        }
        if (!$binary && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'docx') {
            try { $content = $this->buildDocx($content); } catch (\Throwable $e) { return ['ok' => false, 'error' => 'DOCX generation is unavailable on this server: ' . $e->getMessage()]; }
        }
        if (!$binary && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') {
            try { $content = $this->buildXlsx($content); } catch (\Throwable $e) { return ['ok' => false, 'error' => 'XLSX generation is unavailable on this server: ' . $e->getMessage()]; }
        }
        [$dir, $name] = $this->splitPath($path);
        $folder = $this->ensureFolderPath($home, $dir);
        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File) {
                $existing->putContent($content);
                $this->bumpSearchRevision();
                return ['ok' => true, 'result' => 'Updated ' . $path];
            }
            return ['ok' => false, 'error' => 'A folder with that name already exists at ' . $path];
        }
        // The ownership marker is recorded BEFORE the file is created and
        // finalized with the resulting node afterwards, so an interruption
        // between the two leaves a pending record that reconciliation resolves
        // instead of a missing grant (Issue #183).
        $this->markOwnedPending($home, $path, function () use ($folder, $name, $content): void {
            $folder->newFile($name, $content);
        });
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Created ' . $path];
    }

    /** Build a minimal standards-compliant Word document without external services. */
    private function buildDocx(string $text): string {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP ZipArchive extension is required');
        $zip = new \ZipArchive(); $tmp = tempnam(sys_get_temp_dir(), 'eva_docx_');
        if ($tmp === false || $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('could not create archive');
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $lines = preg_split("/\\R/u", $text) ?: [];
        $paras = ''; foreach ($lines as $line) $paras .= '<w:p><w:r><w:t xml:space="preserve">' . $esc($line) . '</w:t></w:r></w:p>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paras . '<w:sectPr/></w:body></w:document>');
        $zip->close(); $data = file_get_contents($tmp); @unlink($tmp); if (!is_string($data) || $data === '') throw new \RuntimeException('archive was empty'); return $data;
    }

    /** Build a minimal Excel workbook from comma/tab separated text. */
    private function buildXlsx(string $text): string {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP ZipArchive extension is required');
        $zip = new \ZipArchive(); $tmp = tempnam(sys_get_temp_dir(), 'eva_xlsx_');
        if ($tmp === false || $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('could not create archive');
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $rows = preg_split('/\R/u', trim($text)) ?: []; $sheet = ''; $r = 0;
        foreach ($rows as $line) { $r++; $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line); $c = 0; $sheet .= '<row r="' . $r . '">'; foreach ($cells as $value) { $c++; $col = ''; $n = $c; while ($n > 0) { $n--; $col = chr(65 + ($n % 26)) . $col; $n = intdiv($n, 26); } $sheet .= '<c r="' . $col . $r . '" t="inlineStr"><is><t>' . $esc((string)$value) . '</t></is></c>'; } $sheet .= '</row>'; }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="EVA" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>');
        $zip->close(); $data = file_get_contents($tmp); @unlink($tmp); if (!is_string($data) || $data === '') throw new \RuntimeException('archive was empty'); return $data;
    }

    /** Create several files while preserving per-file validation/results. */
    private function createFiles(Folder $home, array $args): array {
        $files = $args['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 20) return ['ok' => false, 'error' => 'files must contain between 1 and 20 entries'];
        $results = []; $allOk = true;
        foreach ($files as $entry) {
            if (!is_array($entry)) { $results[] = ['ok' => false, 'error' => 'Each entry must contain path and content']; $allOk = false; continue; }
            $payload = ['path' => $entry['path'] ?? ''];
            if (array_key_exists('content_base64', $entry)) $payload['content_base64'] = $entry['content_base64']; else $payload['content'] = $entry['content'] ?? '';
            $result = $this->createFile($home, $payload);
            $results[] = $result; if (empty($result['ok'])) $allOk = false;
        }
        return ['ok' => $allOk, 'result' => ['files' => $results, 'created' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))), 'failed' => count(array_filter($results, static fn(array $r): bool => empty($r['ok'])))]];
    }

    /** Prüft die konfigurierte Dateityp-Einschränkung; liefert Fehlertext oder null. */
    private function checkWriteType(string $name): ?string {
        $allowed = strtolower(trim((string)$this->config->get('exec_write_types')));
        if ($allowed === '' || $allowed === '*') {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $list = array_map('trim', explode(',', $allowed));
        if (in_array($ext, $list, true)) {
            return null;
        }
        return 'File type .' . ($ext !== '' ? $ext : '?') . ' is not allowed (allowed: ' . $allowed . ')';
    }

    /** @return array{ok:true,result:string} */
    private function createNote(Folder $home, array $args): array {
        $title = trim((string)($args['title'] ?? ''));
        $content = (string)($args['content'] ?? '');
        if ($title === '') {
            return ['ok' => false, 'error' => 'A note title is required'];
        }
        if (!str_ends_with(strtolower($title), '.md')) {
            $title .= '.md';
        }
        $title = $this->cleanName($title);
        return $this->createFile($home, ['path' => self::NOTES_FOLDER . '/' . $title, 'content' => $content]);
    }

    private function listNotes(Folder $home, array $args): array {
        $search = trim((string)($args['search'] ?? ''));
        $notesDir = $this->folderAt($home, self::NOTES_FOLDER);
        $out = [];
        $count = 0;
        foreach ($notesDir->getDirectoryListing() as $node) {
            if ($count >= 300) break;
            if (!$node instanceof \OCP\Files\File) continue;
            $name = $node->getName();
            if (!str_ends_with(strtolower($name), '.md')) continue;
            if ($search !== '' && stripos($name, $search) === false) continue;
            $out[] = [
                'name' => $name,
                'path' => self::NOTES_FOLDER . '/' . $name,
                'size' => (int)$node->getNode()->getSize(),
                'last_modified' => date('Y-m-d H:i:s', $node->getNode()->getMTime()),
            ];
            $count++;
        }
        usort($out, static fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));
        return ['ok' => true, 'result' => ['notes' => $out, 'count' => count($out)]];
    }

    private function readNote(Folder $home, array $args): array {
        $path = trim((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'Note path is required'];
        // Ensure .md extension
        if (!str_ends_with(strtolower($path), '.md')) {
            $path .= '.md';
        }
        // If no folder prefix, assume Notes folder
        if (strpos($path, '/') === false) {
            $path = self::NOTES_FOLDER . '/' . $path;
        }
        $path = $this->cleanPath($path);
        try {
            $node = $home->get($path);
            if (!$node instanceof \OCP\Files\File) {
                return ['ok' => false, 'error' => 'Path is not a file'];
            }
            $content = (string)$node->getContent();
            return ['ok' => true, 'result' => [
                'path' => $path,
                'name' => $node->getName(),
                'content' => $content,
                'size' => (int)$node->getSize(),
                'last_modified' => date('Y-m-d H:i:s', $node->getMTime()),
            ]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Note not found: ' . $path];
        }
    }

    private function updateNote(Folder $home, array $args): array {
        $path = trim((string)($args['path'] ?? ''));
        $content = (string)($args['content'] ?? '');
        if ($path === '') return ['ok' => false, 'error' => 'Note path is required'];
        // Ensure .md extension
        if (!str_ends_with(strtolower($path), '.md')) {
            $path .= '.md';
        }
        // If no folder prefix, assume Notes folder
        if (strpos($path, '/') === false) {
            $path = self::NOTES_FOLDER . '/' . $path;
        }
        $path = $this->cleanPath($path);
        try {
            $node = $home->get($path);
            if (!$node instanceof \OCP\Files\File) {
                return ['ok' => false, 'error' => 'Path is not a file'];
            }
            $node->putContent($content);
            $this->bumpSearchRevision();
            return ['ok' => true, 'result' => 'Updated note ' . $path];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Note not found: ' . $path];
        }
    }

    /** @return array{ok:true,result:string} */
    private function createFolder(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'error' => 'Folder path required'];
        }
        $existed = $home->nodeExists($path);
        if (!$existed) {
            // Pending marker before the folder is created, finalize afterwards
            // (Issue #183) — same crash-safe semantics as file creation.
            $this->markOwnedPending($home, $path, function () use ($home, $path): void {
                $this->ensureFolderPath($home, $path);
            });
        } else {
            $this->ensureFolderPath($home, $path);
        }
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Created folder ' . $path];
    }

    /** @return array{ok:true,result:string} */
    private function renameFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $newName = trim((string)($args['new_name'] ?? ''));
        if ($path === '' || $newName === '' || str_contains($newName, '/') || $newName === '.' || $newName === '..') {
            return ['ok' => false, 'error' => 'Valid path and new_name are required'];
        }
        $node = $this->resolve($home, $path);
        $parent = $node->getParent();
        if ($parent->nodeExists($newName)) {
            return ['ok' => false, 'error' => 'Target name already exists'];
        }
        $node->move($parent->getPath() . '/' . $newName);
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Renamed to ' . $newName];
    }

    /** Move a file or folder to a new relative path, creating destination folders. */
    private function moveFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $targetPath = $this->cleanPath((string)($args['target_path'] ?? ''));
        if ($path === '' || $targetPath === '' || $path === $targetPath || $targetPath === '/') {
            return ['ok' => false, 'error' => 'Valid, different path and target_path are required'];
        }
        $node = $this->resolve($home, $path);
        if ($node instanceof Folder && str_starts_with($targetPath . '/', $path . '/')) {
            return ['ok' => false, 'error' => 'A folder cannot be moved into itself'];
        }
        [$targetDir, $targetName] = $this->splitPath($targetPath);
        $targetName = $this->cleanName($targetName);
        if ($targetName === '') {
            return ['ok' => false, 'error' => 'A valid target name is required'];
        }
        $destination = $this->ensureFolderPath($home, $targetDir);
        if ($destination->nodeExists($targetName)) {
            return ['ok' => false, 'error' => 'Target already exists'];
        }
        $node->move($destination->getPath() . '/' . $targetName);
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Moved ' . $path . ' to ' . $targetPath];
    }

    /** Copy a file or folder to a new relative path, creating destination folders. */
    private function copyFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $targetPath = $this->cleanPath((string)($args['target_path'] ?? ''));
        if ($path === '' || $targetPath === '' || $path === $targetPath || $targetPath === '/') {
            return ['ok' => false, 'error' => 'Valid, different path and target_path are required'];
        }
        $node = $this->resolve($home, $path);
        if ($node instanceof Folder && str_starts_with($targetPath . '/', $path . '/')) {
            return ['ok' => false, 'error' => 'A folder cannot be copied into itself'];
        }
        [$targetDir, $targetName] = $this->splitPath($targetPath);
        $targetName = $this->cleanName($targetName);
        if ($targetName === '') return ['ok' => false, 'error' => 'A valid target name is required'];
        $destination = $this->ensureFolderPath($home, $targetDir);
        if ($destination->nodeExists($targetName)) return ['ok' => false, 'error' => 'Target already exists'];
        $node->copy($destination->getPath() . '/' . $targetName);
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Copied ' . $path . ' to ' . $targetPath];
    }

    /** Return a bounded checksum for post-operation integrity validation. */
    private function fileChecksum(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) return ['ok' => false, 'error' => 'Not a file'];
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) return ['ok' => false, 'error' => 'File too large to checksum'];
        try {
            $content = (string)$node->getContent();
            return ['ok' => true, 'result' => ['path' => $path, 'algorithm' => 'sha256', 'checksum' => hash('sha256', $content), 'size' => (int)$node->getSize(), 'modified' => (int)$node->getMTime()]];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'File checksum could not be calculated'];
        }
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function deleteFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '' || $path === '/') {
            return ['ok' => false, 'error' => 'A valid path is required'];
        }
        $mode = (string)$this->config->get('exec_delete_mode');
        if ($mode === 'off') {
            return ['ok' => false, 'error' => 'Deleting files is disabled in the app settings.'];
        }
        $node = $this->resolve($home, $path);
        if ($mode !== 'all' && !$this->isOwned($home, $node)) {
            return ['ok' => false, 'error' => 'Only files EVA created itself may be deleted (adjust "delete permission" in the app settings to allow more).'];
        }
        if ($node instanceof Folder && $node->getDirectoryListing() !== []) {
            return ['ok' => false, 'error' => 'Folder is not empty'];
        }
        $fileId = (int)$node->getId();
        $node->delete();
        $this->unmarkOwned($home, $fileId);
        $this->bumpSearchRevision();
        return ['ok' => true, 'result' => 'Deleted ' . ($node instanceof Folder ? 'folder ' : 'file ') . $path];
    }

    /** @return array{ok:true,result:array} */
    private function readFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'error' => 'File path required'];
        }
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) {
            return ['ok' => false, 'error' => 'Not a file'];
        }
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) {
            return ['ok' => false, 'error' => 'File too large to read'];
        }
        $content = (string)$node->getContent();
        if (strpos($content, "\0") !== false) {
            return ['ok' => false, 'error' => 'File is not text'];
        }
        $offset = filter_var($args['offset'] ?? 0, FILTER_VALIDATE_INT);
        $maxChars = filter_var($args['max_chars'] ?? self::MAX_READ_CHARS, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0) {
            return ['ok' => false, 'error' => 'offset must be a non-negative integer'];
        }
        if ($maxChars === false || $maxChars < 1 || $maxChars > self::MAX_READ_CHUNK_CHARS) {
            return ['ok' => false, 'error' => 'max_chars must be between 1 and ' . self::MAX_READ_CHUNK_CHARS];
        }
        $totalChars = mb_strlen($content);
        if ($offset > $totalChars) {
            return ['ok' => false, 'error' => 'offset is beyond the end of the file'];
        }
        $page = mb_substr($content, $offset, $maxChars);
        $nextOffset = $offset + mb_strlen($page);
        return ['ok' => true, 'result' => [
            'path' => $path,
            'content' => $page,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'total_chars' => $totalChars,
            'has_more' => $nextOffset < $totalChars,
        ]];
    }

    /** Read several files while preserving per-file pagination and errors. */
    private function readFiles(Folder $home, array $args): array {
        $files = $args['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 20) return ['ok' => false, 'error' => 'files must contain between 1 and 20 entries'];
        $results = []; $allOk = true;
        foreach ($files as $entry) {
            if (!is_array($entry) || trim((string)($entry['path'] ?? '')) === '') { $results[] = ['ok' => false, 'error' => 'Each entry must contain a path']; $allOk = false; continue; }
            $result = $this->readFile($home, $entry); $results[] = $result; if (empty($result['ok'])) $allOk = false;
        }
        return ['ok' => $allOk, 'result' => ['files' => $results, 'read' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))), 'failed' => count(array_filter($results, static fn(array $r): bool => empty($r['ok'])))]];
    }

    /** Extract indexed text from binary/Office formats in bounded pages. */
    private function extractFileText(Folder $home, array $args): array {
        if ($this->indexer === null) return ['ok' => false, 'error' => 'Document extraction is unavailable'];
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) return ['ok' => false, 'error' => 'Not a file'];
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) return ['ok' => false, 'error' => 'File too large to extract'];
        $maxChars = filter_var($args['max_chars'] ?? self::MAX_READ_CHARS, FILTER_VALIDATE_INT);
        $offset = filter_var($args['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($maxChars === false || $maxChars < 1 || $maxChars > self::MAX_READ_CHUNK_CHARS || $offset === false || $offset < 0) {
            return ['ok' => false, 'error' => 'offset/max_chars are outside the allowed range'];
        }
        try { $content = $this->indexer->extractTextForAgent($node, 100000); }
        catch (\Throwable $e) { return ['ok' => false, 'error' => 'Document extraction failed: ' . $e->getMessage()]; }
        $total = mb_strlen($content);
        if ($offset > $total) return ['ok' => false, 'error' => 'offset is beyond extracted text'];
        $page = mb_substr($content, $offset, $maxChars); $next = $offset + mb_strlen($page);
        return ['ok' => true, 'result' => ['path' => $path, 'content' => $page, 'offset' => $offset, 'next_offset' => $next, 'total_chars' => $total, 'has_more' => $next < $total, 'mime_type' => (string)$node->getMimeType()]];
    }

    private function inspectFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        return ['ok' => true, 'result' => [
            'path' => $path,
            'type' => $node instanceof Folder ? 'folder' : 'file',
            'size' => $node instanceof File ? (int)$node->getSize() : null,
            'mime_type' => $node instanceof File ? (string)$node->getMimeType() : null,
            'modified' => (int)$node->getMTime(),
        ]];
    }

    /** @return array{ok:true,result:array} */
    private function searchFiles(Folder $home, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Search query required'];
        }
        $scopePath = $this->cleanPath((string)($args['path'] ?? ''));
        $scope = $this->folderAt($home, $scopePath);
        $extension = strtolower(ltrim(trim((string)($args['extension'] ?? '')), '.'));
        $forceRefresh = filter_var($args['force_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxDepth = filter_var($args['max_depth'] ?? self::MAX_SEARCH_DEPTH, FILTER_VALIDATE_INT);
        $maxNodes = filter_var($args['max_nodes'] ?? self::MAX_SEARCH_NODES, FILTER_VALIDATE_INT);
        $maxResults = filter_var($args['max_results'] ?? self::MAX_SEARCH_RESULTS, FILTER_VALIDATE_INT);
        $maxDepth = $maxDepth === false ? self::MAX_SEARCH_DEPTH : max(1, min(self::MAX_SEARCH_DEPTH_CONFIG, $maxDepth));
        $maxNodes = $maxNodes === false ? self::MAX_SEARCH_NODES : max(100, min(self::MAX_SEARCH_NODES_CONFIG, $maxNodes));
        $maxResults = $maxResults === false ? self::MAX_SEARCH_RESULTS : max(1, min(self::MAX_SEARCH_RESULTS_CONFIG, $maxResults));
        if ($extension !== '' && !preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
            return ['ok' => false, 'error' => 'extension must contain only letters and digits'];
        }
        $cache = null;
        $userKey = '';
        try { $userKey = (string)($this->config->userId() ?? ''); } catch (\Throwable) { }
        $revision = 0;
        try { $revision = max(0, (int)$this->config->get('search_revision')); } catch (\Throwable) { }
        $cacheKey = 'search_' . substr(hash('sha256', $userKey . "\0" . $revision . "\0" . $query . "\0" . $scopePath . "\0" . $extension . "\0" . $maxDepth . "\0" . $maxNodes . "\0" . $maxResults), 0, 40);
        try {
            $cache = Server::get(\OCP\ICacheFactory::class)->createDistributed('eva_ai_search_');
            $cached = $forceRefresh ? null : $cache->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['result']) && is_array($decoded['result'])) {
                    $this->rememberFileLocations($decoded['result']['matches'] ?? []);
                    return ['ok' => true, 'result' => $decoded['result']];
                }
            }
        } catch (\Throwable) { $cache = null; }
        $matches = [];
        $visited = 0;
        $truncated = false;
        $extracted = 0;
        $this->searchWalk($scope, mb_strtolower($query), $matches, $visited, $truncated, $extracted, 0, $scopePath, $extension, $maxDepth, $maxNodes, $maxResults);
        $this->rememberFileLocations($matches);
        $result = [
            'query' => $query,
            'path' => $scopePath,
            'extension' => $extension !== '' ? $extension : null,
            'cache_bypassed' => $forceRefresh,
            'matches' => $matches,
            'truncated' => $truncated,
            'visited_nodes' => $visited,
            'extracted_documents' => $extracted,
            'limits' => [
                'max_results' => $maxResults,
                'max_nodes' => $maxNodes,
                'max_depth' => $maxDepth,
                'requested_max_results' => $maxResults,
                'max_text_file_bytes' => self::MAX_SEARCH_FILE_BYTES,
                'max_document_bytes' => self::MAX_SEARCH_DOCUMENT_BYTES,
                'max_extracted_documents' => self::MAX_SEARCH_EXTRACT_FILES,
            ],
        ];
        if ($cache !== null) {
            try { $cache->set($cacheKey, json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', self::SEARCH_CACHE_TTL); } catch (\Throwable) { }
        }
        return ['ok' => true, 'result' => $result];
    }

    /** Store only paths/types and timestamps; never file contents. */
    private function rememberFileLocations(array $rows): void {
        try {
            $known = json_decode($this->config->get('learned_file_locations'), true);
            $known = is_array($known) ? $known : [];
            $now = time();
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $path = trim((string)($row['path'] ?? ''));
                if ($path === '' || mb_strlen($path) > 1000 || str_contains($path, '..')) continue;
                $known[$path] = ['type' => (string)($row['type'] ?? 'file'), 'last_seen' => $now];
            }
            uasort($known, static fn(array $a, array $b): int => ((int)($b['last_seen'] ?? 0)) <=> ((int)($a['last_seen'] ?? 0)));
            $this->config->set('learned_file_locations', json_encode(array_slice($known, 0, 500, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable) { /* learning is best effort */ }
    }

    /** Advance the per-user search revision after a successful VFS mutation. */
    private function bumpSearchRevision(): void {
        try {
            $current = max(0, (int)$this->config->get('search_revision'));
            $this->config->set('search_revision', (string)(($current + 1) % 2147483647));
        } catch (\Throwable) { /* cache invalidation is best effort */ }
    }

    private function listLearnedFileLocations(): array {
        try {
            $known = json_decode($this->config->get('learned_file_locations'), true);
            return ['ok' => true, 'result' => ['locations' => is_array($known) ? array_slice($known, 0, 200, true) : [], 'note' => 'Only paths and types are stored; refresh with list_files or search_files when a location may have changed.']];
        } catch (\Throwable) { return ['ok' => true, 'result' => ['locations' => []]]; }
    }

    /**
     * Search names and bounded text content while protecting the request from
     * an unbounded VFS walk or unexpectedly large/binary files.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    private function searchWalk(Folder $folder, string $query, array &$matches, int &$visited, bool &$truncated, int &$extracted, int $depth, string $prefix, string $extension = '', int $maxDepth = self::MAX_SEARCH_DEPTH, int $maxNodes = self::MAX_SEARCH_NODES, int $maxResults = self::MAX_SEARCH_RESULTS): void {
        if ($depth >= $maxDepth || count($matches) >= $maxResults) {
            $truncated = true;
            return;
        }
        try {
            $entries = $folder->getDirectoryListing();
        } catch (\Throwable) {
            // A single unavailable remote folder must not discard matches
            // already collected from other branches. Report a bounded,
            // inspectable partial result instead.
            $truncated = true;
            return;
        }
        foreach ($entries as $node) {
            if (count($matches) >= $maxResults || $visited >= $maxNodes) {
                $truncated = true;
                return;
            }
            $visited++;
            $rel = $prefix === '' ? $node->getName() : $prefix . '/' . $node->getName();
            $nameMatches = str_contains(mb_strtolower($node->getName()), $query);
            if ($node instanceof Folder) {
                if ($nameMatches) {
                    $matches[] = ['path' => $rel, 'reason' => 'filename', 'file_id' => (int)$node->getId()];
                }
                $this->searchWalk($node, $query, $matches, $visited, $truncated, $extracted, $depth + 1, $rel, $extension, $maxDepth, $maxNodes, $maxResults);
                continue;
            }

            if ($extension !== '' && strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) !== $extension) {
                continue;
            }

            $contentMatch = $this->searchFileContent($node, $query, $extracted);

            if ($nameMatches && $contentMatch !== null) {
                $matches[] = ['path' => $rel, 'reason' => 'filename and content', 'file_id' => (int)$node->getId(), 'snippet' => $contentMatch];
            } elseif ($nameMatches) {
                $matches[] = ['path' => $rel, 'reason' => 'filename', 'file_id' => (int)$node->getId()];
            } elseif ($contentMatch !== null) {
                $matches[] = ['path' => $rel, 'reason' => 'content', 'file_id' => (int)$node->getId(), 'snippet' => $contentMatch];
            }
        }
    }

    /**
     * Search a file that is not necessarily indexed yet. Text files are read
     * directly; common PDFs/Office/OpenDocument formats go through the same
     * bounded extractor used by the agent. Extraction is deliberately capped
     * per search so a query cannot trigger an implicit crawl or re-index.
     */
    private function searchFileContent(File $file, string $query, int &$extracted): ?string {
        try {
            if ($this->isSearchableTextFile($file)) {
                $content = (string)$file->getContent();
            } elseif (($content = $this->readLikelyPlainText($file)) !== null) {
                // Some WebDAV uploads have an unknown extension and only the
                // generic octet-stream MIME. Sniff a bounded prefix before
                // accepting the file, so arbitrary binary data is not read as
                // searchable text.
                // The helper returns the already-read bounded content so the
                // file is not fetched twice (important for remote storage).
            } elseif ($this->isSearchableDocument($file)
                && $this->indexer !== null
                && $extracted < self::MAX_SEARCH_EXTRACT_FILES
                && $file->getSize() <= self::MAX_SEARCH_DOCUMENT_BYTES) {
                $extracted++;
                $content = $this->indexer->extractTextForAgent($file, 100000);
            } else {
                return null;
            }
            if (strpos($content, "\0") !== false) {
                return null;
            }
            $position = mb_stripos($content, $query);
            return $position === false
                ? null
                : $this->searchSnippet($content, $position, mb_strlen($query));
        } catch (\Throwable) {
            // A single unreadable or malformed document must not abort search.
            return null;
        }
    }

    private function isSearchableDocument(File $file): bool {
        if ($file->getSize() > self::MAX_SEARCH_DOCUMENT_BYTES) {
            return false;
        }
        $mime = strtolower((string)$file->getMimeType());
        if (in_array($mime, [
            'application/pdf',
            'application/rtf',
            'application/epub+zip',
            'application/msword',
            'application/vnd.ms-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'application/vnd.apple.pages',
        ], true)) {
            return true;
        }
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        return in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'epub', 'rtf'], true);
    }

    private function isSearchableTextFile(File $file): bool {
        if ($file->getSize() > self::MAX_SEARCH_FILE_BYTES) {
            return false;
        }
        $mime = strtolower((string)$file->getMimeType());
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        if (in_array($mime, [
            'application/json', 'application/ld+json', 'application/xml',
            'application/x-yaml', 'application/yaml', 'application/rtf',
            'application/sql', 'application/x-sh', 'application/x-httpd-php',
        ], true)) {
            return true;
        }
        // Nextcloud may report an uploaded text document as
        // application/octet-stream when its MIME map is incomplete. The
        // extension fallback keeps direct, non-indexed search useful without
        // reading arbitrary binary files: only well-known text extensions are
        // eligible and the existing byte/NUL guards still apply.
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        return in_array($extension, [
            'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'jsonl',
            'xml', 'yaml', 'yml', 'html', 'htm', 'xhtml', 'ini', 'cfg',
            'conf', 'properties', 'sql', 'js', 'mjs', 'cjs', 'ts', 'tsx',
            'jsx', 'css', 'scss', 'less', 'vue', 'py', 'rb', 'php', 'sh',
            'bash', 'zsh', 'fish', 'go', 'rs', 'java', 'kt', 'swift', 'r',
            'tex', 'rst', 'adoc', 'org', 'toml', 'env', 'srt', 'vtt',
        ], true);
    }

    private function isLikelyPlainTextFile(File $file): bool {
        return $this->readLikelyPlainText($file) !== null;
    }

    /**
     * Read an unknown octet-stream once and return it only when its bounded
     * prefix looks like UTF-8 text. Remote-storage reads can be expensive, so
     * callers should use the returned content instead of probing then reading
     * the file a second time.
     */
    private function readLikelyPlainText(File $file): ?string {
        if ($file->getSize() <= 0 || $file->getSize() > self::MAX_SEARCH_FILE_BYTES) {
            return null;
        }
        $mime = strtolower((string)$file->getMimeType());
        if (!in_array($mime, ['', 'application/octet-stream', 'binary/octet-stream'], true)) {
            return null;
        }
        $sample = (string)$file->getContent();
        if ($sample === '' || !mb_check_encoding(mb_substr($sample, 0, 65536), 'UTF-8')) {
            return null;
        }
        if (strpos($sample, "\0") !== false) {
            return null;
        }
        $prefix = mb_substr($sample, 0, 65536);
        $controls = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $prefix);
        return ($controls === false || $controls <= max(2, (int)floor(mb_strlen($prefix) * 0.01))) ? $sample : null;
    }

    private function searchSnippet(string $content, int $position, int $queryLength): string {
        $start = max(0, $position - 120);
        $snippet = mb_substr($content, $start, $queryLength + 240);
        $snippet = preg_replace('/\s+/u', ' ', trim($snippet)) ?? trim($snippet);
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($start + mb_strlen($snippet) < mb_strlen($content)) {
            $snippet .= '…';
        }
        return mb_substr($snippet, 0, 300);
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function updateKnowledge(Folder $home, array $args): array {
        $fact = trim((string)($args['fact'] ?? ''));
        if ($fact === '') {
            return ['ok' => false, 'error' => 'A fact is required'];
        }
        if (mb_strlen($fact) > 500) {
            return ['ok' => false, 'error' => 'Fact too long (max 500 characters)'];
        }
        $path = 'KNOWLEDGE.md';
        $line = '- ' . date('Y-m-d') . ': ' . $fact;
        $content = '';
        if ($home->nodeExists($path) && $home->get($path) instanceof File) {
            $content = (string)$home->get($path)->getContent();
        } elseif ($home->nodeExists($path)) {
            return ['ok' => false, 'error' => 'KNOWLEDGE.md exists but is not a file'];
        }
        $content = rtrim($content) . "
" . $line . "
";
        $trimmed = false;
        if (mb_strlen($content) > self::KNOWLEDGE_MAX_CHARS) {
            [$content, $trimmed] = $this->trimKnowledge($content);
        }
        if ($home->nodeExists($path)) {
            $home->get($path)->putContent($content);
        } else {
            $home->newFile($path, $content);
        }
        if ($trimmed) {
            try {
                \OC::$server->get(\Psr\Log\LoggerInterface::class)->warning('eva_ai: knowledge file trimmed; automatic profile section preserved', [
                    'file' => $path,
                ]);
            } catch (\Throwable $e) {
                // Logging must not make a successful knowledge update fail.
            }
        }
        $result = 'Knowledge updated: ' . $line;
        if ($trimmed) {
            $result .= ' Older non-profile entries were trimmed to keep KNOWLEDGE.md bounded; the automatic profile section was preserved.';
        }
        return ['ok' => true, 'result' => $result];
    }

    /**
     * Remove the oldest non-profile lines while retaining the automatic
     * identity block. The block is recognized by its marker (or heading for
     * files created before the marker was introduced).
     *
     * @return array{0:string,1:bool}
     */
    private function trimKnowledge(string $content): array {
        $lines = explode("\n", $content);
        $protected = array_fill(0, count($lines), false);
        $inProfile = false;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === self::KNOWLEDGE_PROFILE_MARKER || $trimmed === '## About me (from my Nextcloud profile)') {
                $inProfile = true;
            }
            if ($inProfile) {
                $protected[$i] = true;
            }
            if ($inProfile && str_starts_with($trimmed, '- Imported automatically on ')) {
                $inProfile = false;
            }
        }

        $removed = array_fill(0, count($lines), false);
        $length = mb_strlen($content);
        foreach ($lines as $i => $line) {
            if ($length <= self::KNOWLEDGE_TARGET_CHARS) {
                break;
            }
            if ($protected[$i]) {
                continue;
            }
            $removed[$i] = true;
            $length -= mb_strlen($line) + ($i < count($lines) - 1 ? 1 : 0);
        }

        $kept = [];
        foreach ($lines as $i => $line) {
            if (!$removed[$i]) {
                $kept[] = $line;
            }
        }
        $updated = implode("\n", $kept);
        return [$updated, $updated !== $content];
    }

    // ---- Marker für "von der KI erstellt" ----

    private function marksFile(string $userId): \OCP\Files\SimpleFS\ISimpleFile {
        // IAppDataFactory wird lazy geholt statt per Konstruktor injiziert:
        // die Aufloesung blockiert im CLI/taskprocessing-Worker.
        $appdata = \OC::$server->get(IAppDataFactory::class)->get(AppConfig::APP);
        try {
            $dir = $appdata->getFolder('ai-marks');
        } catch (\OCP\Files\NotFoundException $e) {
            $dir = $appdata->newFolder('ai-marks');
        }
        // Collision-free per-user namespace (SHA-256 of the exact user ID).
        // Legacy lossy-slug folders are migrated lazily so existing markers
        // are preserved (Issue #8).
        $ns = substr(hash('sha256', $userId), 0, 40);
        try {
            $uid = $dir->getFolder($ns);
        } catch (\OCP\Files\NotFoundException $e) {
            $legacy = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
            try {
                $legacyFolder = $dir->getFolder($legacy);
                $uid = $dir->newFolder($ns);
                if ($legacyFolder->fileExists('created.json')) {
                    $uid->newFile('created.json', $legacyFolder->getFile('created.json')->getContent());
                }
                $legacyFolder->delete();
            } catch (\OCP\Files\NotFoundException $e2) {
                $uid = $dir->newFolder($ns);
            }
        }
        if (!$uid->fileExists('created.json')) {
            $uid->newFile('created.json', '[]');
        }
        return $uid->getFile('created.json');
    }

    private function userNameOf(Folder $home): string {
        $owner = $home->getOwner();
        if ($owner !== null && $owner->getUID() !== '') {
            return $owner->getUID();
        }
        $parts = explode('/', trim($home->getPath(), '/'));
        return $parts[0] ?? '';
    }

    private function ownershipStore(Folder $home): FileOwnershipStore {
        $userId = $this->userNameOf($home);
        if ($userId === '') {
            throw new \RuntimeException('No ownership namespace');
        }
        return new FileOwnershipStore($this->marksFile($userId), $home, $this->lockingProvider, $userId);
    }

    /**
     * Run a filesystem-creating action between a pending ownership marker and
     * its finalize (Issue #183):
     * - beginPending runs before the action, so a crash leaves a truthful
     *   pending record instead of a missing grant;
     * - a failed action cancels the record;
     * - a finalize failure keeps the pending record, which reconciliation
     *   resolves on the next ownership check.
     */
    private function markOwnedPending(Folder $home, string $path, callable $action): void {
        $store = $this->ownershipStore($home);
        $token = $store->beginPending($path);
        try {
            $action();
            try {
                $store->finalizePending($token, $home->get($path));
            } catch (\Throwable $e) {
                // The file exists and the pending record survives; the next
                // ownership check reconciles it into a grant.
            }
        } catch (\Throwable $e) {
            try {
                $store->cancelPending($token);
            } catch (\Throwable $ignored) {
                // Never mask the original action failure.
            }
            throw $e;
        }
    }

    private function unmarkOwned(Folder $home, int $fileId): void {
        try {
            $this->ownershipStore($home)->forget($fileId);
        } catch (\Throwable $e) {
            // Stale IDs never authorize a replacement file and are pruned on next access.
        }
    }

    private function isOwned(Folder $home, \OCP\Files\Node $node): bool {
        try {
            return $this->ownershipStore($home)->contains($node);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- Helfer ----

    private function cleanName(string $name): string {
        $name = str_replace(['/', '\\', '..', "\0"], '-', $name);
        return trim($name, " \t.-");
    }

    /** Entfernt .., führende Slashes und leere Segmente; darf nicht aus dem Home raus. */
    private function cleanPath(string $path): string {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /** @return array{0:string,1:string} */
    private function splitPath(string $path): array {
        $pos = strrpos($path, '/');
        if ($pos === false) {
            return ['', $path];
        }
        return [substr($path, 0, $pos), substr($path, $pos + 1)];
    }

    private function folderAt(Folder $home, string $path): Folder {
        if ($path === '') {
            return $home;
        }
        $node = $home->get($path);
        if (!$node instanceof Folder) {
            throw new NotPermittedException('Not a folder');
        }
        return $node;
    }

    private function ensureFolderPath(Folder $home, string $path): Folder {
        if ($path === '') {
            return $home;
        }
        $current = $home;
        foreach (explode('/', $path) as $seg) {
            if ($seg === '') {
                continue;
            }
            if (!$current->nodeExists($seg)) {
                $current->newFolder($seg);
            }
            $node = $current->get($seg);
            if (!$node instanceof Folder) {
                throw new NotPermittedException('Path component is not a folder: ' . $seg);
            }
            $current = $node;
        }
        return $current;
    }

    private function resolve(Folder $home, string $path): \OCP\Files\Node {
        if ($path === '') {
            return $home;
        }
        return $home->get($path);
    }
}
