<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Optional local tools with deadlines, private temporary directories, and no shell interpolation. */
class SystemExtractor {
    private static array $binaries = [];
    public static function capabilities(): array {
        $out = [];
        foreach (['pdftotext','pdftoppm','mutool','soffice','tesseract'] as $name) { $out[$name] = self::binary($name) !== null; }
        return ['tools' => $out, 'office' => DocumentExtractor::OFFICE, 'maxSourceBytes' => 33554432, 'timeoutSeconds' => 30];
    }
    private static function binary(string $name): ?string {
        if (array_key_exists($name, self::$binaries)) { return self::$binaries[$name]; }
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/bin:/usr/local/bin') as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) { return self::$binaries[$name] = $dir . '/' . $name; }
        }
        return self::$binaries[$name] = null;
    }
    private static function run(array $args, string $dir): string {
        $binary = self::binary(array_shift($args));
        if ($binary === null) { throw new \RuntimeException('Required local extraction tool is not installed'); }
        $process = proc_open(array_merge([$binary], $args), [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file', $dir . '/error.log', 'w']], $pipes, $dir);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start local extractor'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false);
        $output = ''; $start = microtime(true); $exit = -1;
        try {
            do {
                $output .= stream_get_contents($pipes[1], 65536);
                if (strlen($output) > 33554432 || microtime(true) - $start > 30) { throw new \RuntimeException('Extraction exceeded output or time limit'); }
                $status = proc_get_status($process); $exit = $status['exitcode'];
                if (!$status['running']) { $output .= stream_get_contents($pipes[1]); break; }
                usleep(10000);
            } while (true);
            if ($exit !== 0) { throw new \RuntimeException('Local extraction failed (encrypted, malformed, or unsupported document)'); }
            return $output;
        } finally {
            if (isset($status) && $status['running']) { proc_terminate($process, 9); }
            fclose($pipes[1]); proc_close($process);
        }
    }
    private static function workspace(callable $fn): string {
        $dir = sys_get_temp_dir() . '/eva_extract_' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('Cannot create extraction workspace'); }
        try { return $fn($dir); }
        finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
            @rmdir($dir);
        }
    }
    public static function legacy(string $source, string $ext): string {
        return self::workspace(static function ($dir) use ($source, $ext): string {
            copy($source, "$dir/input.$ext");
            $format = $ext === 'xls' ? 'csv' : ($ext === 'ppt' ? 'pdf' : 'txt');
            self::run(['soffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--convert-to', $format, '--outdir', $dir, "$dir/input.$ext"], $dir);
            $target = "$dir/input.$format";
            if (!is_file($target) || filesize($target) > 33554432) { throw new \RuntimeException('Conversion output missing or oversized'); }
            return $format === 'pdf' ? self::pdf($target, false) : (string)file_get_contents($target);
        });
    }
    public static function pdf(string $source, bool $ocr): string {
        return self::workspace(static function ($dir) use ($source, $ocr): string {
            $text = '';
            if (self::binary('pdftotext') !== null) { $text = self::run(['pdftotext', '-layout', '-enc', 'UTF-8', $source, '-'], $dir); }
            elseif (self::binary('mutool') !== null) { $text = self::run(['mutool', 'draw', '-F', 'txt', '-o', '-', $source], $dir); }
            elseif (!$ocr) { throw new \RuntimeException('PDF extraction needs pdftotext or mutool'); }
            if (trim($text) === '' && $ocr) {
                // A finite page cap is explicit. Never silently claim a complete index.
                self::run(['pdftoppm', '-f', '1', '-l', '21', '-scale-to', '1600', '-png', $source, $dir . '/page'], $dir);
                $pages = glob($dir . '/page-*.png') ?: []; natsort($pages);
                if (count($pages) > 20) { throw new \RuntimeException('OCR supports at most 20 pages per document'); }
                foreach ($pages as $page) { $text .= self::run(['tesseract', $page, 'stdout'], $dir) . "\f"; }
            }
            $out = '';
            foreach (explode("\f", $text) as $i => $page) { if (trim($page) !== '') { $out .= "\n\n# Page: " . ($i + 1) . "\n" . $page; } }
            return trim($out);
        });
    }
    public static function image(string $source): string {
        return self::workspace(static fn($dir) => self::run(['tesseract', $source, 'stdout'], $dir));
    }
}
