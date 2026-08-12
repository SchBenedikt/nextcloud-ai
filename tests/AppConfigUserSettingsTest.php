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

    public function testPersonalSettingsUseUserConfigAndRuntimeStateStaysGlobal(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')
            ->with('alice', 'eva_ai', 'chat_model', self::isType('string'))
            ->willReturn('alice-model');
        $config->expects(self::once())
            ->method('setUserValue')
            ->with('alice', 'eva_ai', 'chat_model', 'alice-model-2');
        $config->expects(self::once())
            ->method('setAppValue')
            ->with('eva_ai', 'index_running', '1');
        $config->method('getAppValue')
            ->willReturn('global-value');

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('alice-model', $appConfig->get('chat_model'));
        $appConfig->set('chat_model', 'alice-model-2');
        $appConfig->set('index_running', '1');
    }

    public function testPersonalSettingFallsBackToGlobalValueWhenNotOverridden(): void {
        $config = $this->createMock(IConfig::class);
        $config->expects(self::once())
            ->method('getUserValue')
            ->with('alice', 'eva_ai', 'chat_model', self::isType('string'))
            ->willReturnCallback(static fn(string $user, string $app, string $key, string $default): string => $default);
        $config->expects(self::once())
            ->method('getAppValue')
            ->with('eva_ai', 'chat_model', 'gemma4:cloud')
            ->willReturn('global-model');

        $appConfig = new AppConfig($config);
        $appConfig->setUserId('alice');

        self::assertSame('global-model', $appConfig->get('chat_model'));
    }
}
