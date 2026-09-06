<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

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

    public function testPersonalSettingsAndRuntimeStateUseUserConfig(): void {
        $config = $this->createMock(IConfig::class);
        $values = [];
        $config->method('getUserValue')
            ->willReturnCallback(static function (string $user, string $app, string $key, string $default) use (&$values): string {
                if (isset($values[$user][$key])) {
                    return $values[$user][$key];
                }
                return $user === 'alice' && $key === 'chat_model' ? 'alice-model' : $default;
            });
        $config->method('setUserValue')
            ->willReturnCallback(static function (string $user, string $app, string $key, string $value) use (&$values): void {
                $values[$user][$key] = $value;
            });
        $config->method('setAppValue');
        $config->method('getAppValue')->willReturn('global-value');

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

        // A second user reads only their own values or fixed defaults, never Alice's or a global legacy value.
        $appConfig->setUserId('bob');
        self::assertSame('global-value', $appConfig->get('chat_model'));
        self::assertSame('global-value', $appConfig->get('max_files_per_run'));
        self::assertSame('', $appConfig->get('exclude_paths'));
        self::assertSame('0', $appConfig->get('index_running'));
    }

    public function testPersonalSettingInheritsAdministratorDefaultWhenNotOverridden(): void {
        $config = $this->createMock(IConfig::class);
        $config->expects(self::once())
            ->method('getUserValue')
            ->with('alice', 'eva_ai', 'chat_model', self::isType('string'))
            ->willReturnCallback(static fn(string $user, string $app, string $key, string $default): string => $default);
        $config->expects(self::once())->method('getAppValue')->with('eva_ai', 'chat_model', 'gemma4:cloud')->willReturn('instance-model');

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('instance-model', $appConfig->get('chat_model'));
    }
}
