<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validate every secret before a settings request can change any credentials. */
final class SettingsCredentialsRequest {
    public const FIELDS = ['groq_api_key', 'remove_groq_api_key', 'custom_provider_api_key', 'remove_custom_provider_api_key', 'nextcloud_api_token', 'remove_nextcloud_api_token'];

    private function __construct(
        public readonly ?string $groqKey,
        public readonly bool $removeGroqKey,
        public readonly ?string $customKey,
        public readonly bool $removeCustomKey,
        public readonly ?string $nextcloudToken,
        public readonly bool $removeNextcloudToken,
    ) {}

    public static function fromArray(array $input): self {
        $groq = $input['groq_api_key'] ?? null;
        $custom = $input['custom_provider_api_key'] ?? null;
        $token = $input['nextcloud_api_token'] ?? null;
        if ($groq !== null && (!is_string($groq) || ($groq !== '' && !preg_match('/^gsk_[A-Za-z0-9_-]{16,256}$/D', $groq)))) {
            throw new InvalidArgumentException('Invalid Groq credential input.');
        }
        if ($custom !== null && (!is_string($custom) || strlen($custom) > 512 || preg_match('/[\r\n]/', $custom))) {
            throw new InvalidArgumentException('Invalid custom provider credential input.');
        }
        if ($token !== null && (!is_string($token) || strlen($token) > 512 || preg_match('/\s/', $token))) {
            throw new InvalidArgumentException('Invalid Nextcloud app token input.');
        }
        $flags = [];
        foreach (['remove_groq_api_key', 'remove_custom_provider_api_key', 'remove_nextcloud_api_token'] as $name) {
            $value = $input[$name] ?? false;
            if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException('Invalid credential removal flag.');
            }
            $flags[] = in_array($value, [true, 1, '1'], true);
        }
        return new self($groq, $flags[0], $custom, $flags[1], $token, $flags[2]);
    }
}
