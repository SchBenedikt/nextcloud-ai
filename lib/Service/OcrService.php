<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Optional local OCR with bounded input, pixels, pages, output and runtime. */
class OcrService {
    private const MAX_BYTES = 20971520;
    private const MAX_PAGES = 30;
    private const MAX_TEXT = 2097152;
    private const MAX_IMAGE_PIXELS = 25000000;
    private const MAX_CONVERTED_PIXELS = 4000000;
    private const MAX_CONVERTED_BYTES = 33554432;
    private const CONVERTED_IMAGE_MIMES = ['image/avif', 'image/heic', 'image/heif'];
    private const IMAGICK_FORMATS = ['image/avif' => 'AVIF', 'image/heic' => 'HEIC', 'image/heif' => 'HEIF'];
    private static ?array $supportedImageFormats = null;

    /** Supported image MIME types for OCR. */
    private const IMAGE_MIMES = [
        'image/png', 'image/jpeg', 'image/tiff', 'image/webp',
        'image/bmp', 'image/gif', 'image/avif', 'image/heic', 'image/heif',
    ];

    /** Whether this PHP installation can safely decode a modern photo MIME. */
    public static function supportsImageMime(string $mime): bool {
        if (!in_array($mime, self::CONVERTED_IMAGE_MIMES, true) || !class_exists(\Imagick::class)) return false;
        $formats = self::supportedImageFormats();
        return in_array(self::IMAGICK_FORMATS[$mime], $formats, true);
    }

    public function capabilities(): array {
        $tools = [];
        foreach (['tesseract', 'pdftoppm', 'pdfinfo', 'pdftotext', 'libreoffice'] as $name) {
            $tools[$name] = $this->binary($name) !== null;
        }
        // Check available Tesseract languages
        $tesseract = $this->binary('tesseract');
        $tools['tesseract_languages'] = $tesseract !== null ? $this->getAvailableLanguages($tesseract) : [];
        $tools['image_conversion_formats'] = self::supportedImageFormats();
        return $tools;
    }

    /** @return list<string> */
    private static function supportedImageFormats(): array {
        if (self::$supportedImageFormats !== null) return self::$supportedImageFormats;
        self::$supportedImageFormats = [];
        if (!class_exists(\Imagick::class)) return self::$supportedImageFormats;
        foreach (self::IMAGICK_FORMATS as $format) {
            try {
                if (\Imagick::queryFormats($format) !== []) self::$supportedImageFormats[] = $format;
            } catch (\Throwable) {
                // An ImageMagick build may omit individual codecs.
            }
        }
        return self::$supportedImageFormats;
    }

    protected function binary(string $name): ?string {
        foreach (['/usr/bin/', '/usr/local/bin/'] as $directory) {
            if (is_executable($directory . $name)) return $directory . $name;
        }
        return null;
    }

    /** Get list of installed Tesseract language packs. */
    private function getAvailableLanguages(string $tesseract): array {
        $dir = sys_get_temp_dir() . '/eva-ocr-langs-' . bin2hex(random_bytes(8));
        try {
            mkdir($dir, 0700);
            $process = proc_open(
                [$tesseract, '--list-langs'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, $dir
            );
            if (!is_resource($process)) return ['eng'];
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($process);
            $languages = [];
            foreach (explode("\n", $stdout) as $line) {
                $line = trim($line);
                if ($line !== '' && $line !== 'List of available languages' && $line !== '----------------------------------------------------') {
                    $languages[] = $line;
                }
            }
            return $languages !== [] ? $languages : ['eng'];
        } catch (\Throwable) {
            return ['eng'];
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * Validate and normalize OCR language code.
     * Supports multi-language: "eng+deu" for English + German.
     */
    private function validateLanguage(string $language, array $available): string {
        // Split multi-language string
        $languages = explode('+', $language);
        $valid = [];
        foreach ($languages as $lang) {
            $lang = trim($lang);
            if ($lang !== '' && in_array($lang, $available, true)) {
                $valid[] = $lang;
            }
        }
        // If no valid languages, fall back to English
        if ($valid === []) {
            return 'eng';
        }
        return implode('+', $valid);
    }

    public function extract(string $bytes, string $mime, string $language, int $maxRuntimeSeconds = 60, ?callable $shouldYield = null, ?array $pdfPages = null): string {
        if (!preg_match('/^[a-zA-Z0-9_]{1,24}(?:\+[a-zA-Z0-9_]{1,24}){0,3}$/D', $language)) {
            throw new \RuntimeException('Invalid OCR language');
        }
        if ($maxRuntimeSeconds < 1 || $maxRuntimeSeconds > 60) throw new \InvalidArgumentException('OCR runtime must be between 1 and 60 seconds.');
        if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('OCR input exceeds 20 MiB');
        $tesseract = $this->binary('tesseract');
        if ($tesseract === null) throw new \RuntimeException('OCR requires tesseract on the server');
        $dir = sys_get_temp_dir() . '/eva-ocr-' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) throw new \RuntimeException('Unable to create OCR temporary directory');
        $deadline = microtime(true) + $maxRuntimeSeconds;
        try {
            $input = $dir . '/input';
            if (file_put_contents($input, $bytes) === false) throw new \RuntimeException('Unable to stage OCR input');
            $images = [];
            if ($mime === 'application/pdf') {
                $pdfinfo = $this->binary('pdfinfo');
                $pdftoppm = $this->binary('pdftoppm');
                if ($pdfinfo === null || $pdftoppm === null) throw new \RuntimeException('PDF OCR requires pdfinfo and pdftoppm');
                $info = $this->runOcrCommand([$pdfinfo, $input], $deadline, $shouldYield);
                if (!preg_match('/^Pages:\s+(\d+)/m', $info, $matches) || (int)$matches[1] < 1 || (int)$matches[1] > self::MAX_PAGES) {
                    throw new \RuntimeException('PDF OCR requires 1–30 pages; the existing index was preserved');
                }
                $pages = (int)$matches[1];
                if ($pdfPages !== null) {
                    if (count($pdfPages) > self::MAX_PAGES) throw new \RuntimeException('PDF OCR page selection exceeds its limit');
                    $selectedPages = [];
                    foreach ($pdfPages as $page) {
                        if (!is_int($page) || $page < 1 || $page > $pages) throw new \RuntimeException('Invalid PDF OCR page selection');
                        $selectedPages[$page] = true;
                    }
                    if ($selectedPages === []) return '';
                } else {
                    $selectedPages = null;
                }
                $text = '';
                for ($page = 1; $page <= $pages; $page++) {
                    if ($selectedPages !== null && !isset($selectedPages[$page])) continue;
                    $prefix = $dir . '/page';
                    $this->runOcrCommand([$pdftoppm, '-f', (string)$page, '-l', (string)$page, '-singlefile', '-scale-to', '2000', '-png', $input, $prefix], $deadline, $shouldYield);
                    $pageText = $this->runOcrCommand([$tesseract, $prefix . '.png', 'stdout', '-l', $language], $deadline, $shouldYield);
                    $text .= "[Page $page]\n" . $pageText . "\n\n";
                    if (strlen($text) > self::MAX_TEXT) throw new \RuntimeException('OCR text exceeds 2 MiB');
                    @unlink($prefix . '.png');
                }
                return trim($text);
            }
            $inputFile = $input;
            if (in_array($mime, self::CONVERTED_IMAGE_MIMES, true)) {
                if (!self::supportsImageMime($mime)) throw new \RuntimeException('This HEIC/HEIF/AVIF codec is not available in ImageMagick');
                $inputFile = $dir . '/normalized.png';
                $this->convertModernImage($bytes, $inputFile, $deadline, $shouldYield);
            } else {
                $size = @getimagesizefromstring($bytes);
                if ($size === false) throw new \RuntimeException('OCR requires a valid image file');
                if ($size[0] * $size[1] > self::MAX_IMAGE_PIXELS) throw new \RuntimeException('OCR requires an image of at most 25 megapixels');
            }
            return trim($this->runOcrCommand([$tesseract, $inputFile, 'stdout', '-l', $language], $deadline, $shouldYield));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
            @rmdir($dir);
        }
    }

    /** Convert one bounded modern photo to a Tesseract-friendly PNG. */
    private function convertModernImage(string $bytes, string $output, float $deadline, ?callable $shouldYield): void {
        if ($shouldYield !== null && $shouldYield()) throw new ChatPriorityYieldException('OCR yielded to an interactive chat.');
        $resourceLimits = [
            \Imagick::RESOURCETYPE_MEMORY => 201326592,
            \Imagick::RESOURCETYPE_MAP => 268435456,
            \Imagick::RESOURCETYPE_DISK => 134217728,
            \Imagick::RESOURCETYPE_THREAD => 1,
            \Imagick::RESOURCETYPE_TIME => max(1, (int)ceil($deadline - microtime(true))),
        ];
        $previousLimits = [];
        $probe = null;
        $image = null;
        try {
            foreach ($resourceLimits as $type => $limit) {
                $previousLimits[$type] = \Imagick::getResourceLimit($type);
                if (!\Imagick::setResourceLimit($type, $limit)) throw new \RuntimeException('ImageMagick resource limits could not be applied');
            }
            $probe = new \Imagick();
            $probe->pingImageBlob($bytes);
            if ($probe->getNumberImages() !== 1) throw new \RuntimeException('Animated or multi-frame images are not supported for OCR');
            $width = (int)$probe->getImageWidth();
            $height = (int)$probe->getImageHeight();
            $probe->clear();
            $probe = null;
            if ($width < 1 || $height < 1 || $width * $height > self::MAX_IMAGE_PIXELS) {
                throw new \RuntimeException('OCR requires an image of at most 25 megapixels');
            }
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->thumbnailImage(2000, 2000, true, true);
            $geometry = $image->getImageGeometry();
            if ((int)($geometry['width'] ?? 0) * (int)($geometry['height'] ?? 0) > self::MAX_CONVERTED_PIXELS) {
                throw new \RuntimeException('Converted OCR image exceeds its pixel limit');
            }
            $image->setImageFormat('png');
            $image->stripImage();
            if ($shouldYield !== null && $shouldYield()) throw new ChatPriorityYieldException('OCR yielded to an interactive chat.');
            if (microtime(true) >= $deadline) throw new \RuntimeException('Image conversion exceeded its runtime limit');
            if (!$image->writeImage($output)) throw new \RuntimeException('ImageMagick could not create the OCR image');
            if (!is_file($output) || filesize($output) > self::MAX_CONVERTED_BYTES) throw new \RuntimeException('Converted OCR image exceeds its byte limit');
        } catch (\ImagickException $error) {
            throw new \RuntimeException('ImageMagick could not decode this HEIC/HEIF/AVIF image', 0, $error);
        } finally {
            if ($probe instanceof \Imagick) $probe->clear();
            if ($image instanceof \Imagick) $image->clear();
            foreach ($previousLimits as $type => $limit) {
                try { \Imagick::setResourceLimit((int)$type, (int)$limit); } catch (\Throwable) { /* Keep OCR failure primary. */ }
            }
        }
    }

    /** No shell interpolation; subprocess output is drained with a hard deadline. */
    protected function run(array $arguments, float $deadline): string {
        return $this->runProcess($arguments, $deadline, null);
    }

    /** Keep the overridable two-argument runner used by providers and tests. */
    private function runOcrCommand(array $arguments, float $deadline, ?callable $shouldYield): string {
        if ($shouldYield !== null && $shouldYield()) throw new ChatPriorityYieldException('OCR yielded to an interactive chat.');
        return $shouldYield === null
            ? $this->run($arguments, $deadline)
            : $this->runProcess($arguments, $deadline, $shouldYield);
    }

    private function runProcess(array $arguments, float $deadline, ?callable $shouldYield): string {
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'OMP_THREAD_LIMIT' => '1']);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start OCR tool');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        try {
            while (true) {
                if ($shouldYield !== null && $shouldYield()) {
                    throw new ChatPriorityYieldException('OCR yielded to an interactive chat.');
                }
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
                usleep(250000);
            }
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
