<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use ArrayObject;
use OCA\EvaAi\Service\AppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Web search settings are per-user (each user can enable DuckDuckGo for free),
 * while the weather tool and web search infrastructure (URLs, API keys, limits)
 * remain admin-scoped. Tests verify the scope boundaries are correctly enforced.
 */
final class WebSearchConfigTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * @param array<string,string> $appValues
     * @param array<string,array<string,string>> $userValues
     * @return array{0:AppConfig,1:ArrayObject<string,array<string,string>>,2:ArrayObject<string,string>}
     */
    private function harness(array $appValues = [], array $userValues = []): array {
        $app = new ArrayObject($appValues);
        $user = new ArrayObject($userValues);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->willReturnCallback(static function (string $userName, string $appName, string $key, string $default) use ($user): string {
                return $user[$userName][$key] ?? $default;
            });
        $config->method('setUserValue')
            ->willReturnCallback(static function (string $userName, string $appName, string $key, string $value) use ($user): void {
                if (!isset($user[$userName])) {
                    $user[$userName] = new ArrayObject([]);
                }
                $user[$userName][$key] = $value;
            });
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $appName, string $key, string $default) use ($app): string {
                return isset($app[$key]) ? (string)$app[$key] : $default;
            });
        $config->method('setAppValue')
            ->willReturnCallback(static function (string $appName, string $key, string $value) use ($app): void {
                $app[$key] = $value;
            });
        return [new AppConfig($config), $user, $app];
    }

    public function testWebSearchDefaultsAreOffAndConservative(): void {
        [$config] = $this->harness();
        self::assertSame('0', $config->get('web_search_enabled'), 'web search must be opt-in');
        self::assertSame('duckduckgo', $config->get('web_search_provider'), 'DuckDuckGo is the free default');
        self::assertSame('', $config->get('web_search_url'));
        self::assertSame('5', $config->get('web_search_max_results'));
        self::assertSame('10', $config->get('web_search_timeout'));
        self::assertSame('1', $config->get('web_search_safe_search'));
        self::assertSame('1', $config->get('weather_tool_enabled'));
    }

    public function testAdminSettingsAreClassifiedAsAdminScope(): void {
        [$config] = $this->harness();
        foreach (AppConfig::ADMIN_SETTINGS as $key) {
            self::assertTrue($config->isAdminSetting($key), $key . ' must be admin-scoped');
        }
        self::assertFalse($config->isAdminSetting('chat_model'));
        self::assertFalse($config->isAdminSetting('web_search_api_key'));
    }

    public function testAdminSettingsNeverLeakIntoTheUserSettingsPayload(): void {
        [$config] = $this->harness();
        $userPayload = $config->all();
        foreach (AppConfig::ADMIN_SETTINGS as $key) {
            self::assertArrayNotHasKey($key, $userPayload, $key . ' must not be part of the user settings payload');
        }
        self::assertArrayHasKey('chat_model', $userPayload);
        // web_search_enabled and web_search_provider are now per-user settings.
        self::assertArrayHasKey('web_search_enabled', $userPayload);
        self::assertArrayHasKey('web_search_provider', $userPayload);
    }

    public function testAdminAllExposesEverySwitchButNeverTheApiKey(): void {
        [$config] = $this->harness(['weather_tool_enabled' => '1']);
        $payload = $config->adminAll();
        foreach (AppConfig::ADMIN_SETTINGS as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame('1', $payload['weather_tool_enabled']);
        self::assertArrayNotHasKey('web_search_api_key', $payload);
    }

    public function testAdminSettingsAreStoredAtAppScopeEvenWithAUserContext(): void {
        [$config, $user, $app] = $this->harness();
        $config->setUserId('alice');
        $config->set('weather_tool_enabled', '0');

        self::assertSame('0', $app['weather_tool_enabled'], 'the value must be instance-wide');
        self::assertFalse(isset($user['alice']['weather_tool_enabled']), 'no per-user override may be created');
        self::assertSame('0', $config->get('weather_tool_enabled'));
    }

    public function testAPerUserValueCannotOverrideAnAdminSwitch(): void {
        [$config] = $this->harness([], ['alice' => ['weather_tool_enabled' => '0']]);
        $config->setUserId('alice');
        // Weather tool is an admin setting; even if a user value somehow existed,
        // the admin default stays in force.
        self::assertSame('1', $config->get('weather_tool_enabled'));
    }

    public function testValidateValueAcceptsValidWebSearchSettings(): void {
        [$config] = $this->harness();
        self::assertNull($config->validateValue('web_search_enabled', '1'));
        self::assertNull($config->validateValue('web_search_enabled', '0'));
        self::assertNull($config->validateValue('web_search_safe_search', 'true'));
        self::assertNull($config->validateValue('web_search_provider', 'searxng'));
        self::assertNull($config->validateValue('web_search_provider', 'brave'));
        self::assertNull($config->validateValue('web_search_provider', 'tavily'));
        self::assertNull($config->validateValue('web_search_url', ''));
        self::assertNull($config->validateValue('web_search_url', 'https://searx.example.org'));
        self::assertNull($config->validateValue('web_search_max_results', '5'));
        self::assertNull($config->validateValue('web_search_timeout', '10'));
        self::assertNull($config->validateValue('weather_tool_enabled', '0'));
    }

    public function testValidateValueRejectsInvalidWebSearchSettings(): void {
        [$config] = $this->harness();
        self::assertNotNull($config->validateValue('web_search_provider', 'google'));
        self::assertNotNull($config->validateValue('web_search_url', 'javascript:alert(1)'));
        self::assertNotNull($config->validateValue('web_search_url', 'ftp://example.org'));
        self::assertNotNull($config->validateValue('web_search_url', 'not a url'));
        self::assertNotNull($config->validateValue('web_search_enabled', 'maybe'));
        self::assertNotNull($config->validateValue('web_search_max_results', '99'));
        self::assertNotNull($config->validateValue('web_search_max_results', '0'));
        self::assertNotNull($config->validateValue('web_search_timeout', '3600'));
    }

    public function testLimitsCoverTheWebSearchRanges(): void {
        [$config] = $this->harness();
        $limits = $config->limits();
        self::assertSame([1, 10], $limits['web_search_max_results']);
        self::assertSame([1, 30], $limits['web_search_timeout']);
    }
}
