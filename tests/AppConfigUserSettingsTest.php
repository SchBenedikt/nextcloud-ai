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

    public function testProviderProfilesAreValidatedAndResolvedPerUser(): void {
        [$config] = $this->configHarness([], ['alice' => [
            'provider_profiles' => '[{"id":"deepseek","name":"DeepSeek","url":"https://api.deepseek.com/v1","model":"deepseek-chat"}]',
            'chat_provider' => 'deepseek',
        ]]);
        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');
        self::assertNull($appConfig->validateValue('provider_profiles', [[
            'id' => 'deepseek', 'name' => 'DeepSeek', 'url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat',
        ]]));
        self::assertSame('deepseek-chat', $appConfig->providerProfile()['model'] ?? null);
        self::assertNotNull($appConfig->validateValue('provider_profiles', [['id' => 'bad id', 'name' => '', 'url' => 'file:///tmp', 'model' => '']]));
    }

    public function testUserSettingFallsBackToAdminInstanceValueWhenNotOverridden(): void {
        [$config] = $this->configHarness(['ollama_url' => 'http://192.168.1.10:11434']);

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        // Alice never saved a personal value, so the admin-configured instance
        // value is used instead of the hardcoded default.
        self::assertSame('http://192.168.1.10:11434', $appConfig->get('ollama_url'));
    }

    public function testExplicitPersonalValueOverridesAdminInstanceValue(): void {
        [$config] = $this->configHarness(
            ['ollama_url' => 'http://admin-value:11434'],
            ['alice' => ['ollama_url' => 'http://personal-value:11434']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('http://personal-value:11434', $appConfig->get('ollama_url'));
    }

    /**
     * A run whose worker is gone must stop claiming to be running.
     *
     * A process killed mid-run never reaches its cleanup, and the claim it left
     * behind makes every later pass answer "already running" while nothing is
     * running at all. The heartbeat is the only liveness signal, so an old one
     * ends the run.
     */
    public function testAnAbandonedRunIsReleased(): void
    {
        [$config] = $this->configHarness([], ['alice' => [
            'index_running' => '1',
            'index_heartbeat' => (string)(time() - 1000),
            'index_started' => (string)(time() - 1000),
            'index_run_id' => 'deadbeef',
            'index_mode' => 'talk',
        ]]);

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertTrue($appConfig->recoverAbandonedRun(), 'the abandoned claim was not released');
        self::assertSame('0', $appConfig->get('index_running'));
        self::assertSame('idle', $appConfig->get('index_mode'));
        self::assertSame('', $appConfig->get('index_run_id'));
        self::assertSame('', $appConfig->get('index_heartbeat'));
    }

    /** A run with a fresh heartbeat is alive and keeps its claim. */
    public function testALiveRunKeepsItsClaim(): void
    {
        [$config] = $this->configHarness([], ['alice' => [
            'index_running' => '1',
            'index_heartbeat' => (string)(time() - 5),
            'index_run_id' => 'live',
        ]]);

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertFalse($appConfig->recoverAbandonedRun(), 'a live run was declared abandoned');
        self::assertSame('1', $appConfig->get('index_running'));
        self::assertSame('live', $appConfig->get('index_run_id'));
    }

    /**
     * A run that never wrote a heartbeat is judged by its start time, in both
     * directions: an ancient start ends it, a recent one does not.
     */
    public function testARunWithoutAHeartbeatFallsBackToItsStartTime(): void
    {
        foreach ([[time() - 2000, true], [time() - 10, false]] as [$started, $expected]) {
            [$config] = $this->configHarness([], ['alice' => [
                'index_running' => '1',
                'index_heartbeat' => '',
                'index_started' => (string)$started,
            ]]);

            $appConfig = new AppConfig($config);
            $appConfig->setUserId('alice');

            self::assertSame($expected, $appConfig->recoverAbandonedRun(), 'start time ' . $started);
        }
    }

    /**
     * A pending cancellation shortens the window instead of skipping it: the
     * worker was asked to stop and is expected to notice quickly.
     */
    public function testAPendingCancellationUsesTheShorterWindow(): void
    {
        foreach ([[400, true], [60, false]] as [$age, $expected]) {
            [$config] = $this->configHarness([], ['alice' => [
                'index_running' => '1',
                'index_heartbeat' => (string)(time() - $age),
                'index_cancel_requested' => '1',
            ]]);

            $appConfig = new AppConfig($config);
            $appConfig->setUserId('alice');

            self::assertSame($expected, $appConfig->recoverAbandonedRun(), 'heartbeat age ' . $age);
        }
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

    public function testAllExposesUserSettingsAndRuntimeState(): void {
        [$config] = $this->configHarness(
            [],
            ['alice' => ['chat_model' => 'personal-model', 'index_running' => '1']]
        );

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        $all = $appConfig->all();
        self::assertSame('personal-model', $all['chat_model']);
        self::assertSame('1', $all['index_running']);
    }
}
