<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Parses ISO date-time values without changing the meaning of explicit offsets. */
final class CalendarTimeParser {
	public static function parseIsoDateTime(string $value, \DateTimeZone $userTimeZone): ?\DateTimeImmutable {
		$value = trim($value);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $value, $matches)) {
			return null;
		}

		$hasExplicitOffset = isset($matches[1]) && $matches[1] !== '';
		try {
			return new \DateTimeImmutable(
				str_replace(' ', 'T', $value),
				$hasExplicitOffset ? null : $userTimeZone,
			);
		} catch (\Throwable) {
			return null;
		}
	}
}
