<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\PluginToggleRequest;
use PHPUnit\Framework\TestCase;

final class PluginToggleRequestTest extends TestCase {
	public function testParsesAValidPluginToggle(): void {
		$request = PluginToggleRequest::fromArray(['name' => 'plugin_demo_lookup', 'enabled' => false]);
		self::assertSame('plugin_demo_lookup', $request->name);
		self::assertFalse($request->enabled);
	}

	public function testRejectsMalformedPluginNamesAndNonBooleanStates(): void {
		foreach ([
			['name' => 'builtin_tool', 'enabled' => false],
			['name' => 'plugin_demo_lookup', 'enabled' => 'false'],
			['name' => 'plugin_demo_lookup', 'enabled' => 0],
		] as $input) {
			try {
				PluginToggleRequest::fromArray($input);
				self::fail('Invalid plugin toggle request was accepted.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
