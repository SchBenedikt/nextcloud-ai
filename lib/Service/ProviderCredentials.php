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

    /** Generic encrypted credential storage for user-configured providers. */
    public function saveCustom(string $userId, string $providerId, string $key): void {
        $this->saveCustomValue($userId, $providerId, 'api_key', $key);
    }
    /** Store an arbitrary connector secret (username, password, API key, ...). */
    public function saveCustomValue(string $userId, string $providerId, string $field, string $value): void {
        if ($userId === '') throw new ProviderException('An authenticated user is required');
        $providerId = preg_replace('/[^a-z0-9_-]/i', '', $providerId) ?: 'default';
        $field = preg_replace('/[^a-z0-9_-]/i', '', $field) ?: 'value';
        $name = 'provider_' . $providerId . '_' . $field;
        if ($value === '') { $this->config->deleteUserValue($userId, AppConfig::APP, $name); return; }
        if (strlen($value) > 1024) throw new \InvalidArgumentException('Connector credential is too long');
        $this->config->setUserValue($userId, AppConfig::APP, $name, $this->crypto->encrypt($value));
    }
    public function customConfigured(string $userId, string $providerId): bool {
        return $this->customValueConfigured($userId, $providerId, 'api_key');
    }
    public function customValueConfigured(string $userId, string $providerId, string $field): bool {
        $providerId = preg_replace('/[^a-z0-9_-]/i', '', $providerId) ?: 'default';
        $field = preg_replace('/[^a-z0-9_-]/i', '', $field) ?: 'value';
        return $userId !== '' && $this->config->getUserValue($userId, AppConfig::APP, 'provider_' . $providerId . '_' . $field, '') !== '';
    }
    public function getCustom(string $userId, string $providerId): string {
        return $this->getCustomValue($userId, $providerId, 'api_key');
    }
    public function getCustomValue(string $userId, string $providerId, string $field): string {
        $providerId = preg_replace('/[^a-z0-9_-]/i', '', $providerId) ?: 'default';
        $field = preg_replace('/[^a-z0-9_-]/i', '', $field) ?: 'value';
        $value = $this->config->getUserValue($userId, AppConfig::APP, 'provider_' . $providerId . '_' . $field, '');
        if ($value === '') throw new ProviderException('Save the provider API key in Settings first');
        try { return $this->crypto->decrypt($value); } catch (\Throwable) { throw new ProviderException('The provider API key cannot be decrypted; save it again'); }
    }

    /** Optional Nextcloud app-password for generic OCS actions in workers. */
    public function saveNextcloudToken(string $userId, string $token): void {
        if ($userId === '') throw new ProviderException('An authenticated user is required');
        if ($token === '') {
            $this->config->deleteUserValue($userId, AppConfig::APP, 'nextcloud_api_token_encrypted');
            return;
        }
        if (strlen($token) > 512 || preg_match('/\s/', $token)) throw new \InvalidArgumentException('Invalid Nextcloud app token');
        $this->config->setUserValue($userId, AppConfig::APP, 'nextcloud_api_token_encrypted', $this->crypto->encrypt($token));
    }

    public function nextcloudTokenConfigured(string $userId): bool {
        return $userId !== '' && $this->config->getUserValue($userId, AppConfig::APP, 'nextcloud_api_token_encrypted', '') !== '';
    }

    public function getNextcloudToken(string $userId): string {
        if (!$this->nextcloudTokenConfigured($userId)) throw new ProviderException('No Nextcloud app token configured');
        try {
            return $this->crypto->decrypt($this->config->getUserValue($userId, AppConfig::APP, 'nextcloud_api_token_encrypted', ''));
        } catch (\Throwable) { throw new ProviderException('The Nextcloud app token cannot be decrypted; save it again'); }
    }
}
