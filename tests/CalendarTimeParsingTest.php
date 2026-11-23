<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\CalendarService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class CalendarTimeParsingTest extends TestCase {
	private function parse(string $value): \DateTimeImmutable {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Berlin');
		$service = (new \ReflectionClass(CalendarService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(CalendarService::class, 'config'))->setValue($service, $config);

		$allDay = false;
		$method = new \ReflectionMethod(CalendarService::class, 'parseTime');
		$result = $method->invokeArgs($service, [$value, 'alice', &$allDay]);
		self::assertInstanceOf(\DateTimeImmutable::class, $result);
		self::assertFalse($allDay);
		return $result;
	}

	public function testZuluTimestampRemainsUtcForNonUtcUsers(): void {
		$expected = new \DateTimeImmutable('2030-07-01T10:00:00Z');
		$actual = $this->parse('2030-07-01T10:00:00Z');
		self::assertSame($expected->getTimestamp(), $actual->getTimestamp());
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
