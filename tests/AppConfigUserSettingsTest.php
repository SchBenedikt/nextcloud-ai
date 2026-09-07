<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use ArrayObject;
use OCA\EvaAi\Service\AppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class AppConfigUserSettingsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Config double whose app- and user-value stores are ArrayObjects, so the
     * test can observe writes and pre-seed values through the same references
     * the mocked IConfig reads from.
     *
     * @param array<string,string> $appValues
     * @param array<string,array<string,string>> $userValues
     * @return array{0:IConfig,1:ArrayObject<string,array<string,string>>,2:ArrayObject<string,string>}
     */
    private function configHarness(array $appValues = [], array $userValues = []): array {
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
        $config->method('deleteUserValue')
            ->willReturnCallback(static function (string $userName, string $appName, string $key) use ($user): void {
                if (isset($user[$userName]) && isset($user[$userName][$key])) {
                    unset($user[$userName][$key]);
                }
            });
        $config->method('setAppValue')
            ->willReturnCallback(static function (string $appName, string $key, string $value) use ($app): void {
                $app[$key] = $value;
            });
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $appName, string $key, string $default) use ($app): string {
                return isset($app[$key]) ? (string)$app[$key] : $default;
            });
        return [$config, $user, $app];
    }

    public function testPersonalSettingsAndRuntimeStateUseUserConfig(): void {
        [$config] = $this->configHarness(
            ['chat_model' => 'instance-model'],
            ['alice' => ['chat_model' => 'alice-model']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('alice-model', $appConfig->get('chat_model'));
        $appConfig->set('chat_model', 'alice-model-2');
        $appConfig->set('max_files_per_run', '7');
        $appConfig->set('exclude_paths', 'Private');
        $appConfig->set('index_running', '1');
        self::assertSame('7', $appConfig->get('max_files_per_run'));
        self::assertSame('Private', $appConfig->get('exclude_paths'));
        self::assertSame('1', $appConfig->get('index_running'));
        $appConfig->set('chunk_overlap', '0');
        self::assertSame(0, $appConfig->getInt('chunk_overlap', 120));

        // A second user with no personal values must inherit the admin-configured
        // instance value for user-facing settings (Issue #73) and never read
        // Alice's value or an old instance-wide runtime state.
        $appConfig->setUserId('bob');
        self::assertSame('instance-model', $appConfig->get('chat_model'));
        self::assertSame('40', $appConfig->get('max_files_per_run'));
        self::assertSame('', $appConfig->get('exclude_paths'));
        self::assertSame('0', $appConfig->get('index_running'));
    }

    public function testUserSettingFallsBackToAdminInstanceValueWhenNotOverridden(): void {
        [$config] = $this->configHarness(['ollama_url' => 'http://192.168.1.10:11434']);

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        // Alice never saved a personal value, so the admin-configured instance
        // value is used instead of the hardcoded default.
        self::assertSame('http://192.168.1.10:11434', $appConfig->get('ollama_url'));
        self::assertFalse($appConfig->hasPersonal('ollama_url'));
        self::assertFalse($appConfig->personalMap()['ollama_url']);
    }

    public function testExplicitPersonalValueOverridesAdminInstanceValue(): void {
        [$config] = $this->configHarness(
            ['ollama_url' => 'http://admin-value:11434'],
            ['alice' => ['ollama_url' => 'http://personal-value:11434']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('http://personal-value:11434', $appConfig->get('ollama_url'));
        self::assertTrue($appConfig->hasPersonal('ollama_url'));
        self::assertTrue($appConfig->personalMap()['ollama_url']);
    }

    public function testResetPersonalValueRestoresAdminInstanceValue(): void {
        [$config, $user] = $this->configHarness(
            ['ollama_url' => 'http://admin-value:11434'],
            ['alice' => ['ollama_url' => 'http://personal-value:11434']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');
        self::assertSame('http://personal-value:11434', $appConfig->get('ollama_url'));

        $appConfig->resetPersonal('ollama_url');
        self::assertFalse(isset($user['alice']['ollama_url']));
        self::assertSame('http://admin-value:11434', $appConfig->get('ollama_url'));
        self::assertFalse($appConfig->hasPersonal('ollama_url'));
    }

    public function testRuntimeStateNeverInheritsAdminInstanceValue(): void {
        // Even when an old/foreign instance value exists for a per-user state
        // key, users without their own state keep the hardcoded default.
        [$config] = $this->configHarness(['index_running' => '1', 'index_mode' => 'running']);

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('bob');

        self::assertSame('0', $appConfig->get('index_running'));
        self::assertSame('idle', $appConfig->get('index_mode'));
    }

    public function testAllAndResetOnlyExposeUserFacingKeys(): void {
        [$config, $user] = $this->configHarness(
            [],
            ['alice' => ['chat_model' => 'personal-model', 'index_running' => '1']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        $all = $appConfig->all();
        self::assertSame('personal-model', $all['chat_model']);
        self::assertSame('1', $all['index_running']);

        // Runtime state keys can never be reset through the user-facing API.
        $appConfig->resetPersonal('index_running');
        self::assertSame('1', $appConfig->get('index_running'));
        self::assertTrue(isset($user['alice']['index_running']));

        // Resetting a user-facing setting removes the personal override.
        $appConfig->resetPersonal('chat_model');
        self::assertFalse($appConfig->hasPersonal('chat_model'));
        self::assertFalse(isset($user['alice']['chat_model']));
    }
}
