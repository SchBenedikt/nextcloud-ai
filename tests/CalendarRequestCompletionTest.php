<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\RagService;
use PHPUnit\Framework\TestCase;

final class CalendarRequestCompletionTest extends TestCase {
    /** @return array{0:object,1:array} */
    private function invoke(string $message, array $args): array {
        $reflection = new \ReflectionClass(RagService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('completeCalendarArguments');
        $result = $method->invoke($service, 'alice', $message, $args);
        return [$service, $result];
    }

    public function testQuotedSummaryAndNextWeekWeekendAreCompleted(): void {
        [, $result] = $this->invoke(
            'Erstelle für nächste Woche Samstag und Sonntag ein neues Ereignis "test"',
            []
        );

        self::assertSame('test', $result['summary']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['start']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['end']);
        self::assertLessThan($result['end'], $result['start']);
    }

    public function testExplicitToolValuesAreNeverOverwritten(): void {
        [, $result] = $this->invoke(
            'Erstelle nächste Woche Samstag und Sonntag "anderer Titel"',
            ['summary' => 'Tool-Titel', 'start' => '2030-01-02', 'end' => '2030-01-03']
        );

        self::assertSame('Tool-Titel', $result['summary']);
        self::assertSame('2030-01-02', $result['start']);
        self::assertSame('2030-01-03', $result['end']);
    }

    public function testUnrelatedMessageIsLeftUntouched(): void {
        [, $result] = $this->invoke('Erstelle einen Termin irgendwann', []);
        self::assertSame([], $result);
    }
}
