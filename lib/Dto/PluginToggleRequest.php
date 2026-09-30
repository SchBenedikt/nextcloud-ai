<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated per-user request to enable or disable one installed EVA plugin tool. */
final class PluginToggleRequest {
	private function __construct(
		public readonly string $name,
		public readonly bool $enabled,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$name = $input['name'] ?? null;
		if (!is_string($name) || preg_match('/^plugin_[a-z0-9][a-z0-9_-]{1,63}$/D', $name) !== 1) {
			throw new InvalidArgumentException('A valid plugin tool name is required.');
		}
		if (!is_bool($input['enabled'] ?? null)) {
			throw new InvalidArgumentException('The enabled value must be a boolean.');
		}
		return new self($name, $input['enabled']);
	}
}
