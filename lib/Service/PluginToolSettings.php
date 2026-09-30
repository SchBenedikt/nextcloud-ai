<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Parse and update the small per-user list of disabled third-party tools. */
final class PluginToolSettings {
	public static function isEnabled(string $stored, string $name): bool {
		$settings = json_decode($stored, true);
		return !is_array($settings) || ($settings[$name] ?? true) !== false;
	}

	/** @param list<string> $knownNames */
	public static function withEnabledState(string $stored, string $name, bool $enabled, array $knownNames): string {
		$known = array_fill_keys($knownNames, true);
		$settings = json_decode($stored, true);
		$disabled = [];
		if (is_array($settings)) {
			foreach ($settings as $storedName => $state) {
				if (isset($known[$storedName]) && $state === false) $disabled[$storedName] = false;
			}
		}
		if ($enabled) unset($disabled[$name]);
		else $disabled[$name] = false;
		ksort($disabled, SORT_STRING);
		if ($disabled === []) return '{}';
		return json_encode($disabled, JSON_UNESCAPED_SLASHES) ?: '{}';
	}
}
