<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Centralized tool permission policy for all EVA AI tools.
 *
 * Every tool declares its risk level, required capabilities, confirmation
 * requirements and supported execution surfaces. All tool execution paths
 * (web chat, Talk, RAG, TaskProcessing) must pass through this policy
 * before ActionExecutor performs the operation.
 *
 * @see ActionExecutor
 */
class ToolPolicy {

    /** Risk classification for tools. */
    public const RISK_READONLY = 'readonly';    // No side effects, safe everywhere
    public const RISK_MUTATING = 'mutating';     // Creates/updates user data
    public const RISK_DESTRUCTIVE = 'destructive'; // Deletes data, needs confirmation

    /** Execution surfaces where tools can run. */
    public const SURFACE_WEB = 'web';              // Web chat UI
    public const SURFACE_TALK = 'talk';            // Nextcloud Talk bot
    public const SURFACE_RAG = 'rag';              // RAG pipeline (TaskProcessing)
    public const SURFACE_TASKPROCESSING = 'taskprocessing'; // Assistant app

    /**
     * Complete tool registry with metadata.
     * Format: name => [risk, surfaces, requiresConfirmation, description]
     */
    private const TOOLS = [
        // ---- File operations ----
        'list_files' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List files and folders',
        ],
        'read_file' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read file content',
        ],
        'search_files' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search files by name',
        ],
        'create_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create or overwrite a file',
        ],
        'create_note' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a Markdown note',
        ],
        'create_folder' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a folder',
        ],
        'rename_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Rename a file or folder',
        ],
        'delete_file' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete a file or folder',
        ],
        'update_knowledge' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update personal knowledge base',
        ],

        // ---- Contacts ----
        'find_contact' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search contacts',
        ],
        'create_contact' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a contact',
        ],
        'update_contact' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update a contact',
        ],
        'delete_contact' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete a contact',
        ],

        // ---- Profile ----
        'read_profile' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read own profile (not available in Talk)',
        ],
        'update_profile' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update own profile',
        ],

        // ---- Calendar ----
        'list_calendars' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List calendars',
        ],
        'list_calendar_events' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List calendar events',
        ],
        'create_calendar_event' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a calendar event',
        ],
        'update_calendar_event' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update a calendar event',
        ],
        'delete_calendar_event' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete a calendar event',
        ],
        'find_free_slots' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Find free time slots',
        ],

        // ---- Mail ----
        'search_mails' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search emails',
        ],
        'list_mails' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List recent emails',
        ],
        'read_mail' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read a single email',
        ],
        'unread_mail_count' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get unread mail count',
        ],

        // ---- Shares ----
        'list_shares' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List shares (not available in Talk)',
        ],
        'create_share' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a share',
        ],
        'update_share' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update a share',
        ],
        'delete_share' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete a share',
        ],

        // ---- Tasks ----
        'list_tasks' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List tasks',
        ],
        'create_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create a task',
        ],
        'update_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update a task',
        ],
        'complete_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Mark a task as completed',
        ],
        'delete_task' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete a task',
        ],

        // ---- Utility ----
        'recent_activity' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List recent activity',
        ],
        'server_status' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get server status (not available in Talk)',
        ],
        'current_time' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get current time',
        ],
        'weather' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get weather forecast',
        ],
    ];

    private string $activeSurface = self::SURFACE_WEB;

    /**
     * Set the execution surface for the current context.
     */
    public function setSurface(string $surface): void {
        $allowed = [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_RAG, self::SURFACE_TASKPROCESSING];
        if (in_array($surface, $allowed, true)) {
            $this->activeSurface = $surface;
        }
    }

    /**
     * Get the current execution surface.
     */
    public function getSurface(): string {
        return $this->activeSurface;
    }

    /**
     * Check whether a tool is registered and allowed on the current surface.
     *
     * @return array{allowed:bool,reason?:string,risk?:string,requiresConfirmation?:bool}
     */
    public function check(string $toolName): array {
        $meta = self::TOOLS[$toolName] ?? null;

        if ($meta === null) {
            return [
                'allowed' => false,
                'reason' => 'Unknown tool: ' . $toolName,
            ];
        }

        if (!in_array($this->activeSurface, $meta['surfaces'], true)) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Tool "%s" is not available on the "%s" surface (allowed: %s)',
                    $toolName,
                    $this->activeSurface,
                    implode(', ', $meta['surfaces'])
                ),
            ];
        }

        return [
            'allowed' => true,
            'risk' => $meta['risk'],
            'requiresConfirmation' => $meta['requiresConfirmation'],
        ];
    }

    /**
     * Get metadata for a specific tool.
     *
     * @return array{risk:string,surfaces:string[],requiresConfirmation:bool,description:string}|null
     */
    public function getTool(string $toolName): ?array {
        return self::TOOLS[$toolName] ?? null;
    }

    /**
     * Get all registered tool names.
     *
     * @return string[]
     */
    public function allToolNames(): array {
        return array_keys(self::TOOLS);
    }

    /**
     * Get all tools allowed on the current surface.
     *
     * @return array<string,array>
     */
    public function toolsForSurface(): array {
        $result = [];
        foreach (self::TOOLS as $name => $meta) {
            if (in_array($this->activeSurface, $meta['surfaces'], true)) {
                $result[$name] = $meta;
            }
        }
        return $result;
    }

    /**
     * Get all mutating/destructive tools for the current surface.
     *
     * @return string[]
     */
    public function mutatingTools(): array {
        $tools = [];
        foreach (self::TOOLS as $name => $meta) {
            if ($meta['risk'] !== self::RISK_READONLY &&
                in_array($this->activeSurface, $meta['surfaces'], true)) {
                $tools[] = $name;
            }
        }
        return $tools;
    }

    /**
     * Get all read-only tools for the current surface.
     *
     * @return string[]
     */
    public function readonlyTools(): array {
        $tools = [];
        foreach (self::TOOLS as $name => $meta) {
            if ($meta['risk'] === self::RISK_READONLY &&
                in_array($this->activeSurface, $meta['surfaces'], true)) {
                $tools[] = $name;
            }
        }
        return $tools;
    }
}
