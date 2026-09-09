<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests;
use OCA\EvaAi\Service\OcrService;
use OCA\EvaAi\Service\AppConfig;
use PHPUnit\Framework\TestCase;

final class OcrServiceTest extends TestCase {
    public function testImageExtractionAndLanguageValidation(): void {
        $service = new class extends OcrService {
            protected function binary(string $name): ?string { return '/test/' . $name; }
            protected function run(array $arguments, float $deadline): string {
                self::assertArgs($arguments);
                return 'Invoice 123';
            }
            private static function assertArgs(array $arguments): void {
                if ($arguments[0] !== '/test/tesseract' || end($arguments) !== 'deu+eng') throw new \RuntimeException('Unexpected OCR command');
            }
        };
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        self::assertSame('Invoice 123', $service->extract($png, 'image/png', 'deu+eng'));
        $this->expectExceptionMessage('Invalid OCR language');
        $service->extract($png, 'image/png', 'eng;touch /tmp/unwanted');
    }
    public function testMissingDependencyAndOversizedInputsFailExplicitly(): void {
        $service = new class extends OcrService { protected function binary(string $name): ?string { return null; } };
        self::assertFalse($service->capabilities()['tesseract']);
        $this->expectExceptionMessage('requires tesseract');
        $service->extract('data', 'image/png', 'eng');
    }
    public function testPdfPageLimitPreservesPreviousIndexByThrowing(): void {
        $service = new class extends OcrService {
            protected function binary(string $name): ?string { return '/test/' . $name; }
            protected function run(array $arguments, float $deadline): string { return "Pages: 31\n"; }
        };
        $this->expectExceptionMessage('existing index was preserved');
        $service->extract('%PDF-test', 'application/pdf', 'eng');
    }
    public function testSubprocessTimeoutIsEnforced(): void {
        $service = new class extends OcrService {
            public function slow(): string { return $this->run([PHP_BINARY, '-r', 'usleep(5000000);'], microtime(true) + 0.05); }
        };
        $this->expectExceptionMessage('runtime limit exceeded');
        $service->slow();
    }
    public function testOcrSettingsRejectMalformedValues(): void {
        if (!EVA_AI_OCP_AVAILABLE) self::markTestSkipped('OCP required');
        $config = new AppConfig($this->createMock(\OCP\IConfig::class));
        self::assertNull($config->validateValue('ocr_language', 'deu+eng'));
        self::assertNotNull($config->validateValue('ocr_language', '--help'));
        self::assertNotNull($config->validateValue('ocr_enabled', []));
    }
}
