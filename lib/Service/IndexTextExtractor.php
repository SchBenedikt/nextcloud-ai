<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/** Canonical native-file/blob text extraction, shared by indexing and generation builds. */
class IndexTextExtractor {
    private const MAX_DECOMPRESSED_BYTES = 104857600;
    private const MAX_ZIP_ENTRIES = 1000;
    private const MAX_EXTRACT_CHARS = 2000000;
    private const MAX_EMBEDDED_OCR_IMAGES = 4;
    private const MAX_EMBEDDED_OCR_IMAGE_BYTES = 5242880;
    private const MAX_EMBEDDED_OCR_TOTAL_BYTES = 10485760;
    private const MAX_SPARSE_PDF_OCR_PAGES = 8;
    private const SPARSE_PDF_TEXT_CHARS = 160;
    private const OCR_IMAGE_MIMES = [
        'image/png', 'image/jpeg', 'image/tiff', 'image/webp', 'image/bmp',
        'image/x-ms-bmp', 'image/gif', 'image/avif', 'image/heic', 'image/heif',
    ];
    private const OCR_IMAGE_EXTENSIONS = [
        'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
    ];

    public function __construct(private AppConfig $config, private LoggerInterface $logger) {}

    private ?int $maxFileBytes = null;
    private ?\Closure $shouldYield = null;

    /** Erweiterungen, die bedenkenlos als Text gelesen werden dürfen. */
    public const TEXT_EXT = [
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
        // Office Open XML and OpenDocument containers are parsed as text, never executed.
        'docx', 'docm', 'dotx', 'dotm', 'xlsx', 'xlsm', 'xltx', 'xltm',
        'pptx', 'pptm', 'ppsx', 'ppsm', 'ppsxm', 'potx', 'potm',
        'odt', 'ods', 'odp', 'odg', 'odf', 'epub',
        'c', 'h', 'cpp', 'hpp', 'cc', 'cxx', 'cs', 'java', 'kt', 'kts', 'go', 'rs',
        'swift', 'pl', 'pm', 'lua', 'r', 'm', 'mm', 'scala', 'groovy', 'shader',
        'erl', 'ex', 'exs', 'clj', 'cljs', 'hs', 'fs', 'fsx', 'ml', 'nim', 'zig',
        'dockerfile', 'makefile', 'gemfile', 'rakefile', 'procfile',
    ];

    public function isTextMime(?string $mime, string $name = ''): bool {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === null) {
            $extensionMime = self::OCR_IMAGE_EXTENSIONS[$ext] ?? null;
            return $this->config->get('ocr_enabled') === '1' && $extensionMime !== null && OcrService::supportsImageMime($extensionMime);
        }
        $extensionMime = self::OCR_IMAGE_EXTENSIONS[$ext] ?? null;
        if ($this->config->get('ocr_enabled') === '1'
            && ($this->supportsOcrImageMime($mime)
                || ($extensionMime !== null && in_array($mime, ['application/octet-stream', 'application/x-empty'], true)
                    && OcrService::supportsImageMime($extensionMime)))) return true;
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
        return in_array($ext, self::TEXT_EXT, true);
    }

    private function supportsOcrImageMime(string $mime): bool {
        return in_array($mime, self::OCR_IMAGE_MIMES, true)
            && (!in_array($mime, ['image/avif', 'image/heic', 'image/heif'], true) || OcrService::supportsImageMime($mime));
    }



    public function extractText(File $file, ?int $maxBytes = null, ?callable $shouldYield = null): string {
        if ($maxBytes !== null && ($maxBytes < 1 || $maxBytes > 20971520)) throw new \InvalidArgumentException('Invalid source read budget.');
        $previous = $this->maxFileBytes;
        $previousYield = $this->shouldYield;
        $this->maxFileBytes = $maxBytes;
        $this->shouldYield = $shouldYield !== null ? \Closure::fromCallable($shouldYield) : null;
        try { return $this->extractFile($file); }
        finally { $this->maxFileBytes = $previous; $this->shouldYield = $previousYield; }
    }

    private function readFile(File $file): string {
        if ($this->maxFileBytes === null) return (string)$file->getContent();
        $stream = $file->fopen('r');
        if (!is_resource($stream)) throw new \RuntimeException('Source stream could not be opened.');
        try {
            $bytes = stream_get_contents($stream, $this->maxFileBytes + 1);
            if ($bytes === false) throw new \RuntimeException('Source stream could not be read.');
            if (strlen($bytes) > $this->maxFileBytes) throw new \LengthException('Source exceeds its read budget.');
            return $bytes;
        } finally { fclose($stream); }
    }

    private function extractFile(File $file): string {
        $mime = $file->getMimeType() ?? '';
        $name = strtolower($file->getName());

        if (str_starts_with($mime, 'text/')) {
            $raw = $this->readFile($file);
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
                ? $this->mboxText($this->readFile($file))
                : $this->emlText($this->readFile($file));
        }

        // Jupyter notebooks: cells in JSON, markdown and code kept apart.
        if ($ext === 'ipynb' || in_array($mime, ['application/x-ipynb+json', 'application/vnd.jupyter'], true)) {
            $text = $this->notebookText($this->readFile($file));
            if (trim($text) !== '') {
                return $this->normalize($text);
            }
            // A notebook that does not parse is not worth a second attempt.
            return '';
        }

        $ocrMime = in_array($mime, self::OCR_IMAGE_MIMES, true)
            ? $mime
            : (in_array($mime, ['application/octet-stream', 'application/x-empty'], true) ? (self::OCR_IMAGE_EXTENSIONS[$ext] ?? null) : null);
        if ($ocrMime !== null && $this->config->get('ocr_enabled') === '1') {
            return (new OcrService())->extract($this->readFile($file), $ocrMime, $this->config->get('ocr_language'), 60, $this->shouldYield);
        }
        // DOCX + macro-enabled/template variants share the same readable XML body.
        if (in_array($ext, ['docx', 'docm', 'dotx', 'dotm'], true) || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.template') {
            return $this->zipWebText($file, 'docx');
        }
        if (in_array($ext, ['odt', 'ods', 'odp', 'odg', 'odf'], true) || $mime === 'application/vnd.oasis.opendocument.text' || $mime === 'application/vnd.oasis.opendocument.spreadsheet' || $mime === 'application/vnd.oasis.opendocument.presentation' || $mime === 'application/vnd.oasis.opendocument.graphics' || $mime === 'application/vnd.oasis.opendocument.formula') {
            return $this->zipWebText($file, 'odf');
        }
        if ($ext === 'epub' || $mime === 'application/epub+zip') {
            return $this->zipWebText($file, 'epub');
        }

        // Excel (+ macro-enabled workbook/template variants): shared strings and cells.
        if (in_array($ext, ['xlsx', 'xlsm', 'xltx', 'xltm'], true) || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.template') {
            return $this->zipWebText($file, 'xlsx');
        }

        // PowerPoint (+ macro-enabled slideshow/template variants): slide text.
        if (in_array($ext, ['pptx', 'pptm', 'ppsx', 'ppsm', 'ppsxm', 'potx', 'potm'], true) || $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || $mime === 'application/vnd.openxmlformats-officedocument.presentationml.template') {
            return $this->zipWebText($file, 'pptx');
        }

        // RTF: Steuerwörter entfernen / Binary-Steuerzeichen.
        if ($ext === 'rtf' || $mime === 'application/rtf') {
            $raw = $this->readFile($file);
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
            $raw = $this->readFile($file);
            if (strpos($raw, "\0") !== false) {
                return '';
            }
            return $this->normalize($raw);
        }

        // PDF via pdftotext, wenn Poppler verfügbar ist.
        if ($ext === 'pdf' || $mime === 'application/pdf') {
            $txt = $this->pdfToText($file);
            if ($this->config->get('ocr_enabled') === '1' && trim((string)$txt) === '') {
                $txt = (new OcrService())->extract($this->readFile($file), 'application/pdf', $this->config->get('ocr_language'), 60, $this->shouldYield);
            } elseif ($this->config->get('ocr_enabled') === '1' && $txt !== null) {
                $sparsePages = $this->sparsePdfPages($txt);
                if ($sparsePages !== []) {
                    try {
                        $ocrText = (new OcrService())->extract(
                            $this->readFile($file), 'application/pdf', $this->config->get('ocr_language'),
                            60, $this->shouldYield, $sparsePages,
                        );
                        if (trim($ocrText) !== '') $txt .= "\n\n" . $ocrText;
                    } catch (ChatPriorityYieldException $error) {
                        throw $error;
                    } catch (\Throwable $error) {
                        // Keep searchable PDF text even when optional image OCR
                        // is unavailable or one sparse page cannot be rendered.
                        $this->logWarning('eva_ai: sparse PDF page OCR skipped', [
                            'file' => $file->getPath(),
                            'pages' => count($sparsePages),
                            'exception' => $error::class,
                        ]);
                    }
                }
            }
            return $this->normalize((string)$txt);
        }

        // Unbekannte Formate: nur lesen, wenn offensichtlich Text (kein \0).
        $raw = $this->readFile($file);
        if (strpos($raw, "\0") !== false) {
            return '';
        }
        return $this->normalize($raw);
    }

    /**
     * Extract text from a raw content blob (Issue #64: mail attachments).
     * Handles plain text and PDF; other formats are skipped gracefully.
     */
    public function extractTextFromBlob(string $blob, string $mime): string {
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
        $bin = $this->resolveSystemBinary('pdftotext');
        if ($bin === null) {
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
            $output = (new LocalTextProcess())->run([$bin,'-layout','-enc','UTF-8',$tmpIn,'-']);
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
            file_put_contents($tmp, $this->readFile($file));
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
                if (!$aborted && $this->config->get('ocr_enabled') === '1' && in_array($kind, ['docx', 'pptx', 'odf'], true)) {
                    $embedded = $this->zipEmbeddedImageOcr($zip, $total, $aborted, $kind);
                    if ($embedded !== '') $out .= "\n\n" . $embedded;
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

    /** Extract a small, bounded number of embedded Office images with local OCR. */
    private function zipEmbeddedImageOcr(\ZipArchive $zip, int &$total, bool &$aborted, string $kind): string {
        $prefixes = match ($kind) {
            'docx' => ['word/media/'],
            'pptx' => ['ppt/media/'],
            'odf' => ['pictures/'],
            default => [],
        };
        if ($prefixes === []) return '';
        $mimes = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'webp' => 'image/webp',
            'bmp' => 'image/bmp', 'gif' => 'image/gif',
            'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
        ];
        $out = [];
        $imageBytes = 0;
        $processed = 0;
        $count = min($zip->numFiles, self::MAX_ZIP_ENTRIES);
        for ($index = 0; $index < $count && !$aborted && $processed < self::MAX_EMBEDDED_OCR_IMAGES; $index++) {
            $entry = $zip->getNameIndex($index);
            if (!is_string($entry)) continue;
            $lower = strtolower($entry);
            $isMedia = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($lower, $prefix)) { $isMedia = true; break; }
            }
            if (!$isMedia) continue;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!isset($mimes[$extension])) continue;
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || (int)($stat['size'] ?? 0) < 1 || (int)$stat['size'] > self::MAX_EMBEDDED_OCR_IMAGE_BYTES
                || $imageBytes + (int)$stat['size'] > self::MAX_EMBEDDED_OCR_TOTAL_BYTES) continue;
            $bytes = $this->zipReadEntry($zip, $index, $total, $aborted);
            if ($bytes === '' || strlen($bytes) > self::MAX_EMBEDDED_OCR_IMAGE_BYTES) continue;
            $imageBytes += strlen($bytes);
            $processed++;
            try {
                $text = (new OcrService())->extract($bytes, $mimes[$extension], $this->config->get('ocr_language'), 10, $this->shouldYield);
                if (trim($text) !== '') $out[] = '[Embedded image ' . $processed . "]\n" . $text;
            } catch (\Throwable $error) {
                $this->logWarning('eva_ai: embedded Office image OCR skipped', [
                    'kind' => $kind,
                    'image_number' => $processed,
                    'exception' => $error::class,
                ]);
            }
        }
        return implode("\n\n", $out);
    }

    private function pdfToText(File $file): ?string {
        // Nur wenn das poppler-utils Binary vorhanden ist.
        $bin = $this->resolveSystemBinary('pdftotext');
        if ($bin === null) {
            return null;
        }
        $tmpIn = tempnam(sys_get_temp_dir(), 'rag_');
        if ($tmpIn === false) {
            return null;
        }
        try {
            file_put_contents($tmpIn, $this->readFile($file));
            // -layout preserves the physical layout (columns, tables) so
            // tabular PDFs stay structured instead of flowing into one run.
            $txt = (new LocalTextProcess())->run([$bin,'-layout','-enc','UTF-8',$tmpIn,'-']);
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

    /** OCR a small number of PDF pages where Poppler found little or no text. */
    private function sparsePdfPages(string $text): array {
        if (!preg_match_all('/(?:^|\\n)\\[Page\\s+(\\d+)\\]\\s*\\n(.*?)(?=(?:\\n\\[Page\\s+\\d+\\]\\s*\\n)|\\z)/s', $text, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $pages = [];
        foreach ($matches as $match) {
            $page = (int)$match[1];
            $content = preg_replace('/\\s+/u', '', $match[2]) ?? $match[2];
            if ($page > 0 && mb_strlen($content) < self::SPARSE_PDF_TEXT_CHARS) {
                $pages[] = $page;
                if (count($pages) >= self::MAX_SPARSE_PDF_OCR_PAGES) break;
            }
        }
        return $pages;
    }

    /**
     * Legacy binary Office formats (.doc/.xls/.ppt): convert to plain text
     * headless with LibreOffice (soffice) when available, otherwise skip with
     * a logged reason. A private profile dir keeps concurrent conversions
     * from fighting over the global LibreOffice profile lock.
     */
    private function legacyOfficeText(File $file, string $ext): string {
        $bin = $this->resolveSystemBinary('soffice') ?? $this->resolveSystemBinary('libreoffice');
        if ($bin === null) {
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
            file_put_contents($in, $this->readFile($file));
            (new LocalTextProcess())->run([$bin,'--headless','-env:UserInstallation=file://'.$profile,
                '--convert-to','txt:Text (encoded):UTF8','--outdir',$outDir,$in]);
            $txt = '';
            foreach ((array)glob($outDir . '/*.txt') as $f) {
                $part=file_get_contents($f,false,null,0,max(1,8388609-strlen($txt)));
                if($part===false)throw new \RuntimeException('Converted text could not be read.');
                $txt.=$part;
                if(strlen($txt)>8388608)throw new \LengthException('Converted text exceeds its budget.');
            }
            return $txt;
        } finally {
            $this->removeTemporaryDirectory($tmpDir);
        }
    }

    /**
     * Resolve only known absolute installation locations. Looking up a
     * converter through the process PATH lets a compromised PATH select an
     * attacker-controlled executable during indexing.
     */
    protected function resolveSystemBinary(string $name): ?string {
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/', '/opt/homebrew/bin/'] as $directory) {
            $candidate = $directory . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function removeTemporaryDirectory(string $directory): void {
        foreach (array_reverse((array)glob($directory . '/*')) as $path) {
            if (is_dir($path) && !is_link($path)) {
                $this->removeTemporaryDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
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

    public function normalize(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Überflüssige Leerzeichen/Zeilenumbrüche glätten, Blockgrenzen behalten.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text ?? '');
    }

}
