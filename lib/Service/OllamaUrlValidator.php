<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Validates a plain Ollama service base URL without making a network request. */
final class OllamaUrlValidator {
    public static function validate(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))) {
            return 'Ollama server URL must be a plain http(s) URL without credentials, path, query or fragment.';
        }
        return null;
    }
}
