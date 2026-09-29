<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for PATCH /api/chats/{id}/meta. */
final class ChatMetadataRequest {
	/** @param array<string,mixed> $metadata */
	private function __construct(public readonly array $metadata) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$metadata = [];
		foreach (['pinned', 'archived'] as $flag) {
			if (array_key_exists($flag, $input)) {
				if (!is_bool($input[$flag])) {
					throw new InvalidArgumentException($flag . ' must be a boolean');
				}
				$metadata[$flag] = $input[$flag];
			}
		}

		foreach (['folder', 'scopePath', 'instructions', 'persona'] as $field) {
			if (array_key_exists($field, $input)) {
				if (!is_string($input[$field])) {
					throw new InvalidArgumentException($field . ' must be a string');
				}
				$value = trim($input[$field]);
				$metadata[$field] = $field === 'instructions' ? mb_substr($value, 0, 2000) : $value;
			}
		}

		if (array_key_exists('tags', $input)) {
			$tags = $input['tags'];
			if (is_string($tags)) {
				$metadata['tags'] = $tags;
			} elseif (is_array($tags) && array_is_list($tags)) {
				foreach ($tags as $tag) {
					if (!is_string($tag)) {
						throw new InvalidArgumentException('tags must contain only strings');
					}
				}
				$metadata['tags'] = $tags;
			} else {
				throw new InvalidArgumentException('tags must be a string or a list of strings');
			}
		}

		if ($metadata === []) {
			throw new InvalidArgumentException('No metadata given');
		}
		return new self($metadata);
	}
}
