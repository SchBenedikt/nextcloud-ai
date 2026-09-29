<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\OllamaUrlValidator;
use PHPUnit\Framework\TestCase;

/** Input validation for the configurable Ollama endpoint (Issue #594). */
final class ApiOllamaUrlValidationTest extends TestCase {
    private function validate(string $url): ?string {
        return OllamaUrlValidator::validate($url);
    }

    public function testAcceptsOnlyPlainHttpServiceBaseUrls(): void {
        self::assertNull($this->validate('http://127.0.0.1:11434'));
        self::assertNull($this->validate('https://ollama.example.org/'));
        self::assertNull($this->validate('  https://ollama.example.org:443  '));
    }

    public function testRejectsCredentialsAndRequestComponents(): void {
        foreach ([
            'http://alice@ollama.example.org',
            'http://:secret@ollama.example.org',
            'https://ollama.example.org/api',
            'https://ollama.example.org?token=secret',
            'https://ollama.example.org#fragment',
            'ftp://ollama.example.org',
            'http://ollama.example.org:70000',
        ] as $url) {
            self::assertNotNull($this->validate($url), $url . ' must be rejected');
        }
    }
}
