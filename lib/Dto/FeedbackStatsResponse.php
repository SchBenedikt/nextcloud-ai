<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed per-user totals returned by GET /api/feedback/stats. */
final class FeedbackStatsResponse {
	private function __construct(
		public readonly int $helpful,
		public readonly int $notHelpful,
		public readonly int $bookmarked,
	) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		foreach (['helpful', 'notHelpful', 'bookmarked'] as $field) {
			if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
				throw new InvalidArgumentException($field . ' must be a non-negative integer');
			}
		}
		return new self($data['helpful'], $data['notHelpful'], $data['bookmarked']);
	}

	/** @return array{helpful:int,notHelpful:int,bookmarked:int} */
	public function toArray(): array {
		return ['helpful' => $this->helpful, 'notHelpful' => $this->notHelpful, 'bookmarked' => $this->bookmarked];
	}
}
