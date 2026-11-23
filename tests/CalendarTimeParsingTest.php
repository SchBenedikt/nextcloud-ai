<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\CalendarTimeParser;
use PHPUnit\Framework\TestCase;

final class CalendarTimeParsingTest extends TestCase {
	private function parse(string $value): \DateTimeImmutable {
		$result = CalendarTimeParser::parseIsoDateTime($value, new \DateTimeZone('Europe/Berlin'));
		self::assertInstanceOf(\DateTimeImmutable::class, $result);
		return $result;
	}

	public function testZuluTimestampRemainsUtcForNonUtcUsers(): void {
		$actual = $this->parse('2030-07-01T10:00:00Z');
		self::assertSame((new \DateTimeImmutable('2030-07-01T10:00:00Z'))->getTimestamp(), $actual->getTimestamp());
		self::assertSame(0, $actual->getOffset());
	}

	public function testEquivalentExplicitUtcFormatsRepresentTheSameInstant(): void {
		$values = [
			'2030-07-01T10:00:00Z',
			'2030-07-01T10:00Z',
			'2030-07-01T10:00:00+00:00',
		];
		$instants = array_map(fn(string $value): int => $this->parse($value)->getTimestamp(), $values);
		self::assertCount(1, array_unique($instants));
	}

	public function testTimestampWithoutOffsetUsesTheUsersTimezone(): void {
		$local = $this->parse('2030-07-01T10:00:00');
		self::assertSame('Europe/Berlin', $local->getTimezone()->getName());
		self::assertSame(7200, $local->getOffset());
	}
}
