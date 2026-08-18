<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ToolPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Security regression tests for EVA's tool permission policy.
 *
 * These tests verify that:
 * - Read-only tools are correctly classified
 * - Mutating tools require appropriate surfaces
 * - Destructive tools are properly restricted
 * - Unknown tools are rejected
 * - Surface isolation works (Talk, TaskProcessing, Web, RAG)
 * - Prompt injection cannot bypass tool restrictions
 */
class ToolPolicySecurityTest extends TestCase {

    private ToolPolicy $policy;

    protected function setUp(): void {
        $this->policy = new ToolPolicy();
    }

    // ---- Risk Classification ----

    public function testReadonlyToolsAreClassifiedCorrectly(): void {
        $readonly = $this->policy->readonlyTools();
        $this->assertNotEmpty($readonly, 'There should be read-only tools');
        $this->assertContains('list_files', $readonly);
        $this->assertContains('read_file', $readonly);
        $this->assertContains('search_files', $readonly);
        $this->assertContains('find_contact', $readonly);
        $this->assertContains('current_time', $readonly);
        $this->assertContains('weather', $readonly);
    }

    public function testMutatingToolsAreClassifiedCorrectly(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        $mutating = $this->policy->mutatingTools();
        $this->assertNotEmpty($mutating, 'There should be mutating tools');
        $this->assertContains('create_file', $mutating);
        $this->assertContains('create_calendar_event', $mutating);
        $this->assertContains('update_task', $mutating);
    }

    public function testDestructiveToolsAreInMutatingList(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        $mutating = $this->policy->mutatingTools();
        $this->assertContains('delete_file', $mutating);
        $this->assertContains('delete_calendar_event', $mutating);
        $this->assertContains('delete_contact', $mutating);
    }

    // ---- Surface Isolation ----

    public function testWebSurfaceAllowsAllTools(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        foreach ($this->policy->allToolNames() as $tool) {
            $result = $this->policy->check($tool);
            $this->assertTrue($result['allowed'], "Tool '$tool' should be allowed on web surface");
        }
    }

    public function testTalkSurfaceAllowsReadonlyToolsOnly(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_TALK);
        foreach ($this->policy->readonlyTools() as $tool) {
            $this->assertTrue($this->policy->check($tool)['allowed'], "Read-only tool '$tool' should be allowed on Talk");
        }
        foreach (['create_file', 'delete_file', 'create_share', 'delete_calendar_event', 'update_profile'] as $tool) {
            $this->assertFalse($this->policy->check($tool)['allowed'], "Mutating tool '$tool' must be blocked on Talk");
        }
    }

    public function testTaskProcessingSurfaceRestrictsMutatingTools(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_TASKPROCESSING);

        // Read-only tools should be allowed
        $this->assertTrue($this->policy->check('list_files')['allowed']);
        $this->assertTrue($this->policy->check('read_file')['allowed']);
        $this->assertTrue($this->policy->check('find_contact')['allowed']);
        $this->assertTrue($this->policy->check('current_time')['allowed']);
        $this->assertTrue($this->policy->check('weather')['allowed']);

        // Mutating tools should NOT be allowed on TaskProcessing surface
        $this->assertFalse($this->policy->check('create_file')['allowed']);
        $this->assertFalse($this->policy->check('delete_file')['allowed']);
        $this->assertFalse($this->policy->check('create_calendar_event')['allowed']);
        $this->assertFalse($this->policy->check('delete_calendar_event')['allowed']);
        $this->assertFalse($this->policy->check('create_share')['allowed']);
    }

    public function testRagSurfaceRestrictsMutatingTools(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_RAG);

        $this->assertTrue($this->policy->check('list_files')['allowed']);
        $this->assertFalse($this->policy->check('create_file')['allowed']);
        $this->assertFalse($this->policy->check('delete_file')['allowed']);
    }

    // ---- Unknown Tools ----

    public function testUnknownToolIsRejected(): void {
        $result = $this->policy->check('nonexistent_tool');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Unknown', $result['reason'] ?? '');
    }

    public function testPromptInjectionToolNameIsRejected(): void {
        $injectionNames = [
            'DROP TABLE',
            "'; DROP TABLE users; --",
            '../../../etc/passwd',
            'rm -rf /',
            'eval(',
            '__proto__',
            'constructor',
            str_repeat('a', 1000),
        ];

        foreach ($injectionNames as $name) {
            $result = $this->policy->check($name);
            $this->assertFalse($result['allowed'], "Injection tool name '$name' should be rejected");
        }
    }

    // ---- Confirmation Requirements ----

    public function testDestructiveToolsRequireConfirmation(): void {
        $destructiveTools = ['delete_file', 'delete_calendar_event', 'delete_contact', 'delete_share', 'delete_task'];
        foreach ($destructiveTools as $tool) {
            $meta = $this->policy->getTool($tool);
            $this->assertNotNull($meta, "Tool '$tool' should be registered");
            $this->assertTrue($meta['requiresConfirmation'], "Tool '$tool' should require confirmation");
        }
    }

    public function testEveryInteractiveMutatingToolRequiresConfirmation(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        foreach ($this->policy->mutatingTools() as $tool) {
            $meta = $this->policy->getTool($tool);
            self::assertNotNull($meta, "Tool '$tool' should be registered");
            self::assertTrue($meta['requiresConfirmation'], "Mutating tool '$tool' should require confirmation");
        }
    }

    public function testReadonlyToolsNeverRequireConfirmation(): void {
        foreach ($this->policy->readonlyTools() as $tool) {
            $meta = $this->policy->getTool($tool);
            $this->assertNotNull($meta);
            $this->assertFalse(
                $meta['requiresConfirmation'],
                "Read-only tool '$tool' should not require confirmation"
            );
        }
    }

    // ---- All Tools Are Registered ----

    public function testAllExpectedToolsAreRegistered(): void {
        $expectedTools = [
            // File tools
            'list_files', 'read_file', 'search_files', 'create_file', 'create_note',
            'create_folder', 'rename_file', 'delete_file', 'update_knowledge',
            // Contact tools
            'find_contact', 'create_contact', 'update_contact', 'delete_contact',
            // Profile
            'read_profile', 'update_profile',
            // Calendar
            'list_calendars', 'list_calendar_events', 'create_calendar_event',
            'update_calendar_event', 'delete_calendar_event', 'find_free_slots',
            // Mail
            'search_mails', 'list_mails', 'read_mail', 'unread_mail_count',
            // Shares
            'list_shares', 'create_share', 'update_share', 'delete_share',
            // Tasks
            'list_tasks', 'create_task', 'update_task', 'complete_task', 'delete_task',
            // Utility
            'recent_activity', 'server_status', 'current_time', 'weather',
        ];

        foreach ($expectedTools as $tool) {
            $meta = $this->policy->getTool($tool);
            $this->assertNotNull($meta, "Tool '$tool' should be registered in policy");
            $this->assertArrayHasKey('risk', $meta);
            $this->assertArrayHasKey('surfaces', $meta);
            $this->assertArrayHasKey('requiresConfirmation', $meta);
        }
    }

    // ---- Surface Switching ----

    public function testSurfaceSwitchingIsSticky(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_TALK);
        $this->assertEquals(ToolPolicy::SURFACE_TALK, $this->policy->getSurface());

        $this->policy->setSurface(ToolPolicy::SURFACE_TASKPROCESSING);
        $this->assertEquals(ToolPolicy::SURFACE_TASKPROCESSING, $this->policy->getSurface());

        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        $this->assertEquals(ToolPolicy::SURFACE_WEB, $this->policy->getSurface());
    }

    public function testInvalidSurfaceIsIgnored(): void {
        $this->policy->setSurface(ToolPolicy::SURFACE_WEB);
        $this->policy->setSurface('invalid_surface');
        $this->assertEquals(ToolPolicy::SURFACE_WEB, $this->policy->getSurface());
    }
}
