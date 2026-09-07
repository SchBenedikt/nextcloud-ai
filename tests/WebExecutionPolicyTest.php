<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Web execution policy: on the interactive chat surface, complete and explicit
 * tool calls (including destructive ones) run directly. The confirmation /
 * completion dialog is only shown when required arguments are missing or empty
 * (e.g. a calendar event without a title), so the user can complete them.
 */
final class WebExecutionPolicyTest extends TestCase {

    private function missingRequiredArgs(string $tool, array $args): array {
        $reflection = new ReflectionClass(ActionExecutor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('missingRequiredArgs');
        return $method->invoke($instance, $tool, $args);
    }

    public function testCompleteCalendarEventCallHasNoMissingArgs(): void {
        self::assertSame([], $this->missingRequiredArgs('create_calendar_event', [
            'summary' => 'Test',
            'start' => '2026-09-08T14:00',
        ]));
    }

    public function testCalendarEventWithoutTitleIsReportedAsMissing(): void {
        self::assertSame(['summary'], $this->missingRequiredArgs('create_calendar_event', [
            'start' => '2026-09-08T14:00',
        ]));
        self::assertSame(['summary'], $this->missingRequiredArgs('create_calendar_event', [
            'summary' => '   ',
            'start' => '2026-09-08T14:00',
        ]));
    }

    public function testCalendarEventWithoutStartIsReportedAsMissing(): void {
        self::assertSame(['start'], $this->missingRequiredArgs('create_calendar_event', [
            'summary' => 'Test',
        ]));
    }

    public function testConcreteDeleteFileCallHasNoMissingArgs(): void {
        self::assertSame([], $this->missingRequiredArgs('delete_file', ['path' => '/Documents/Test.md']));
        self::assertSame(['path'], $this->missingRequiredArgs('delete_file', []));
    }

    public function testDeleteCalendarEventRequiresConcreteId(): void {
        self::assertSame([], $this->missingRequiredArgs('delete_calendar_event', ['event_id' => 'personal/event.ics']));
        self::assertSame(['event_id'], $this->missingRequiredArgs('delete_calendar_event', []));
    }

    public function testLinkShareNeedsOnlyAPathButUserShareNeedsATarget(): void {
        self::assertSame([], $this->missingRequiredArgs('create_share', ['path' => '/Documents/Plan.pdf']));
        self::assertSame([], $this->missingRequiredArgs('create_share', ['path' => '/Documents/Plan.pdf', 'type' => 'link']));
        self::assertSame(['target'], $this->missingRequiredArgs('create_share', ['path' => '/Documents/Plan.pdf', 'type' => 'user']));
        self::assertSame([], $this->missingRequiredArgs('create_share', ['path' => '/Documents/Plan.pdf', 'type' => 'user', 'target' => 'alice']));
    }

    public function testRequiredArgTablesExistForEveryConfirmationTool(): void {
        $reflection = new ReflectionClass(ActionExecutor::class);
        $required = $reflection->getConstant('REQUIRED_ARGS');
        self::assertIsArray($required);

        $policy = new ToolPolicy($this->createMock(\OCA\EvaAi\Service\AppConfig::class));
        $policy->setSurface(ToolPolicy::SURFACE_WEB);
        foreach ($policy->mutatingTools() as $tool) {
            self::assertArrayHasKey($tool, $required, "Missing required-args table for '$tool'");
        }
    }
}
