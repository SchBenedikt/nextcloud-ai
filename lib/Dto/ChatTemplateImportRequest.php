<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated top-level input for importing saved prompt templates. */
final class ChatTemplateImportRequest {
	/** @param list<array<string,mixed>> $templates */
	private function __construct(public readonly array $templates) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$templates = $input['templates'] ?? null;
		if (!is_array($templates) || !array_is_list($templates)) {
			throw new InvalidArgumentException('A template list is required');
		}

		// ChatStore processes at most 100 entries and ignores non-object items.
		// Normalize that legacy behavior here before handing data to the service.
		$templates = array_slice($templates, 0, 100);
		$templates = array_values(array_filter($templates, 'is_array'));
		return new self($templates);
	}
}
