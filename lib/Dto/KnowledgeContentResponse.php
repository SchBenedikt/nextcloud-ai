<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed payload returned by GET /api/knowledge. */
final class KnowledgeContentResponse {
	private function __construct(
		public readonly string $content,
		public readonly int $length,
	) {
	}

	public static function fromContent(string $content): self {
		return new self($content, mb_strlen($content));
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		$content = $data['content'] ?? null;
		$length = $data['length'] ?? null;
		if (!is_string($content) || !is_int($length) || $length < 0 || $length !== mb_strlen($content)) {
			throw new InvalidArgumentException('Knowledge response requires content and its matching non-negative length');
		}
		return new self($content, $length);
	}

	/** @return array{content:string,length:int} */
	public function toArray(): array {
		return ['content' => $this->content, 'length' => $this->length];
	}
}
