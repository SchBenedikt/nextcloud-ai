<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;

/** Per-user, encrypted, write-only credentials; never part of settings exports. */
class ProviderCredentials {
    public const KEY = 'groq_api_key_encrypted';
    public function __construct(private IConfig $config, private ICrypto $crypto) {}
    public function configured(string $userId): bool {
        return $userId !== '' && $this->config->getUserValue($userId, AppConfig::APP, self::KEY, '') !== '';
    }
    public function save(string $userId, string $key): void {
        if ($userId === '') throw new ProviderException('An authenticated user is required');
        if ($key === '') {
            $this->config->deleteUserValue($userId, AppConfig::APP, self::KEY);
            return;
        }
        if (!preg_match('/^gsk_[A-Za-z0-9_-]{16,256}$/D', $key)) throw new \InvalidArgumentException('Invalid Groq API key format');
        $this->config->setUserValue($userId, AppConfig::APP, self::KEY, $this->crypto->encrypt($key));
    }
    public function get(string $userId): string {
        if (!$this->configured($userId)) throw new ProviderException('Save your Groq API key in Settings first');
        try {
            return $this->crypto->decrypt($this->config->getUserValue($userId, AppConfig::APP, self::KEY, ''));
        } catch (\Throwable $e) {
            throw new ProviderException('The Groq API key cannot be decrypted; save it again');
        }
    }
}
