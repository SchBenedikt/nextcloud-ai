<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Optional local OCR with bounded input, pixels, pages, output and runtime. */
class OcrService {
    private const MAX_BYTES = 20971520;
    private const MAX_PAGES = 30;
    private const MAX_TEXT = 2097152;

    public function capabilities(): array {
        $tools = [];
        foreach (['tesseract', 'pdftoppm', 'pdfinfo', 'pdftotext', 'libreoffice'] as $name) {
            $tools[$name] = $this->binary($name) !== null;
        }
        return $tools;
    }

    protected function binary(string $name): ?string {
        foreach (['/usr/bin/', '/usr/local/bin/'] as $directory) {
            if (is_executable($directory . $name)) return $directory . $name;
        }
        return null;
    }

    public function extract(string $bytes, string $mime, string $language): string {
        if (!preg_match('/^[a-zA-Z0-9_]{1,24}(?:\+[a-zA-Z0-9_]{1,24}){0,3}$/D', $language)) {
            throw new \RuntimeException('Invalid OCR language');
        }
        if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('OCR input exceeds 20 MiB');
        $tesseract = $this->binary('tesseract');
        if ($tesseract === null) throw new \RuntimeException('OCR requires tesseract on the server');
        $dir = sys_get_temp_dir() . '/eva-ocr-' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) throw new \RuntimeException('Unable to create OCR temporary directory');
        $deadline = microtime(true) + 60;
        try {
            $input = $dir . '/input';
            if (file_put_contents($input, $bytes) === false) throw new \RuntimeException('Unable to stage OCR input');
            $images = [];
            if ($mime === 'application/pdf') {
                $pdfinfo = $this->binary('pdfinfo');
                $pdftoppm = $this->binary('pdftoppm');
                if ($pdfinfo === null || $pdftoppm === null) throw new \RuntimeException('PDF OCR requires pdfinfo and pdftoppm');
                $info = $this->run([$pdfinfo, $input], $deadline);
                if (!preg_match('/^Pages:\s+(\d+)/m', $info, $matches) || (int)$matches[1] < 1 || (int)$matches[1] > self::MAX_PAGES) {
                    throw new \RuntimeException('PDF OCR requires 1–30 pages; the existing index was preserved');
                }
                $pages = (int)$matches[1];
                $text = '';
                for ($page = 1; $page <= $pages; $page++) {
                    $prefix = $dir . '/page';
                    $this->run([$pdftoppm, '-f', (string)$page, '-l', (string)$page, '-singlefile', '-scale-to', '2000', '-png', $input, $prefix], $deadline);
                    $pageText = $this->run([$tesseract, $prefix . '.png', 'stdout', '-l', $language], $deadline);
                    $text .= "[Page $page]\n" . $pageText . "\n\n";
                    if (strlen($text) > self::MAX_TEXT) throw new \RuntimeException('OCR text exceeds 2 MiB');
                    unlink($prefix . '.png');
                }
                return trim($text);
            }
            $size = @getimagesizefromstring($bytes);
            if ($size === false || $size[0] * $size[1] > 25000000) throw new \RuntimeException('OCR requires an image of at most 25 megapixels');
            return trim($this->run([$tesseract, $input, 'stdout', '-l', $language], $deadline));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
            rmdir($dir);
        }
    }

    /** No shell interpolation; subprocess output is drained with a hard deadline. */
    protected function run(array $arguments, float $deadline): string {
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'OMP_THREAD_LIMIT' => '1']);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start OCR tool');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        try {
            while (true) {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (strlen($output) > self::MAX_TEXT || microtime(true) >= $deadline) {
                    throw new \RuntimeException('OCR output or runtime limit exceeded');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    if ($status['exitcode'] !== 0 || strlen($output) > self::MAX_TEXT) throw new \RuntimeException('OCR tool failed; check installed language data and input format');
                    return $output;
                }
                usleep(10000);
            }
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
