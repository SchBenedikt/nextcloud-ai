<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\PluginToolSettings;
use PHPUnit\Framework\TestCase;

final class PluginToolSettingsTest extends TestCase {
	public function testPluginToolsAreEnabledByDefaultAndCanBeDisabledPerUser(): void {
		self::assertTrue(PluginToolSettings::isEnabled('{}', 'plugin_example_read'));
		$stored = PluginToolSettings::withEnabledState('{}', 'plugin_example_read', false, ['plugin_example_read']);
		self::assertFalse(PluginToolSettings::isEnabled($stored, 'plugin_example_read'));
		self::assertSame('{}', PluginToolSettings::withEnabledState($stored, 'plugin_example_read', true, ['plugin_example_read']));
	}

	public function testInvalidAndUninstalledSettingsDoNotDisableOtherTools(): void {
		$stored = PluginToolSettings::withEnabledState('{"plugin_removed":false,"plugin_keep":false}', 'plugin_new', false, ['plugin_keep', 'plugin_new']);
		self::assertSame('{"plugin_keep":false,"plugin_new":false}', $stored);
		self::assertTrue(PluginToolSettings::isEnabled('{invalid', 'plugin_keep'));
		self::assertFalse(PluginToolSettings::isEnabled($stored, 'plugin_new'));
	}
}
