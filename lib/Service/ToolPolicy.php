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

    public function __construct(
        private AppConfig $appConfig,
        // Optional so the policy stays constructible without Talk (and in
        // tests): when it is absent the Talk gate below is simply skipped.
        private ?TalkChatService $talkChat = null,
    ) {
    }

    /** Risk classification for tools. */
    public const RISK_READONLY = 'readonly';    // No side effects, safe everywhere
    public const RISK_MUTATING = 'mutating';     // Creates/updates user data
    public const RISK_DESTRUCTIVE = 'destructive'; // Deletes data, needs confirmation

    /** Execution surfaces where tools can run. */
    public const SURFACE_WEB = 'web';              // Web chat UI
    public const SURFACE_TALK = 'talk';            // Nextcloud Talk bot
    public const SURFACE_RAG = 'rag';              // RAG pipeline (TaskProcessing)
    public const SURFACE_TASKPROCESSING = 'taskprocessing'; // Assistant app, proposal/read-only phase
    public const SURFACE_TASKPROCESSING_CONFIRMED = 'taskprocessing_confirmed'; // Explicitly confirmed native Assistant actions

    /**
     * Complete tool registry with metadata.
     * Format: name => [risk, surfaces, requiresConfirmation, description]
     */
    private const TOOLS = [
        // ---- File operations ----
        'list_files' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List files and folders',
        ],
        'read_file' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read file content',
        ],
        'inspect_file' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Inspect file metadata after an operation',
        ],
        'run_safe_command' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Run one allowlisted, read-only local diagnostic command',
        ],
        'search_files' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search files by name and bounded text content',
        ],
        'create_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create or overwrite a file',
        ],
        'create_note' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a Markdown note',
        ],
        'create_folder' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a folder',
        ],
        'rename_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Rename a file or folder',
        ],
        'delete_file' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a file or folder',
        ],
        'update_knowledge' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update personal knowledge base',
        ],

        // ---- Contacts ----
        'list_contacts' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List all contacts',
        ],
        'find_contact' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search contacts',
        ],
        'create_contact' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a contact',
        ],
        'update_contact' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update a contact',
        ],
        'delete_contact' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a contact',
        ],

        // ---- Profile ----
        'read_profile' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read own profile (not available in Talk)',
        ],
        'update_profile' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update own profile',
        ],

        // ---- Calendar ----
        'list_calendars' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List calendars',
        ],
        'list_calendar_events' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List calendar events',
        ],
        'create_calendar_event' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a calendar event',
        ],
        'update_calendar_event' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update a calendar event',
        ],
        'delete_calendar_event' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a calendar event',
        ],
        'find_free_slots' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Find free time slots',
        ],

        // ---- Mail ----
        'search_mails' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search emails',
        ],
        'list_mails' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List recent emails',
        ],
        'read_mail' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read a single email',
        ],
        'unread_mail_count' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get unread mail count',
        ],

        // ---- Shares ----
        'list_shares' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List shares (not available in Talk)',
        ],
        'create_share' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a share',
        ],
        'update_share' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update a share',
        ],
        'delete_share' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a share',
        ],

        // ---- Tasks ----
        'list_tasks' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List tasks',
        ],
        'create_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Create a task',
        ],
        'update_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Update a task',
        ],
        'complete_task' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Mark a task as completed',
        ],
        'delete_task' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a task',
        ],

        // ---- Talk ----
        // Listing and reading a room is read-only, and the room is resolved
        // against the user's own room list, so no prompt can reach a
        // conversation the user is not in.
        'list_talk_rooms' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List the user\'s Nextcloud Talk rooms',
        ],
        'read_talk_chat' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Read the recent messages of a Talk room',
        ],
        // Posting speaks as the user, so it needs their explicit opt-in
        // (talk_write_enabled, checked below) and a confirmation. It is not
        // offered on the Talk surface itself: there the bot would post into the
        // room as the person who just asked it something.
        'send_talk_message' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Post a message into a Talk room as the user',
        ],

        // ---- Utility ----
        'recent_activity' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List recent activity',
        ],
        'list_comments' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List comments attached to a Nextcloud object',
        ],
        'add_comment' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Add a comment to a Nextcloud object',
        ],
        'delete_comment' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Delete a Nextcloud comment',
        ],
        'list_system_tags' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List visible Nextcloud system tags',
        ],
        'tag_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Assign a system tag to a Nextcloud file',
        ],
        'untag_file' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Remove a system tag from a Nextcloud file',
        ],
        'list_file_versions' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List available versions of a Nextcloud file',
        ],
        'restore_file_version' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Restore a previous version of a Nextcloud file',
        ],
        'server_status' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get server status (not available in Talk)',
        ],
        'list_nextcloud_capabilities' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Discover enabled Nextcloud apps and available EVA integrations',
        ],
        'discover_app_api' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Discover API routes exposed by an enabled Nextcloud app',
        ],
        'list_learned_app_apis' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List the user\'s cached app API route knowledge',
        ],
        'list_learned_file_locations' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List the user\'s cached file and folder path knowledge',
        ],
        'call_app_api' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Call an enabled app OCS API in the current user context',
        ],
        'list_scheduled_briefings' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'List the user\'s EVA scheduled briefings',
        ],
        'create_scheduled_briefing' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Create an EVA scheduled briefing',
        ],
        'update_scheduled_briefing' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Update an EVA scheduled briefing',
        ],
        'delete_scheduled_briefing' => [
            'risk' => self::RISK_DESTRUCTIVE,
            'surfaces' => [self::SURFACE_WEB],
            'requiresConfirmation' => true,
            'description' => 'Delete an EVA scheduled briefing',
        ],
        'current_time' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get current time',
        ],
        'weather' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Get weather forecast',
        ],
        // Read-only but privacy-relevant: the query leaves the instance. It is
        // therefore opt-in per instance and gated in check() below (Issue #187).
        'web_search' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Search the web for current information',
        ],
        // Reading one page the user can already reach is the same disclosure as
        // searching, so it is gated by the same switch: the URL is validated by
        // the service, which never allows a non-http(s) or credential-bearing
        // address and never follows more than a few redirects.
        'open_website' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Open and read one web page',
        ],
        'list_external_connectors' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => false,
            'description' => 'List configured external HTTPS connectors',
        ],
        'configure_external_connector' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Configure a named external HTTPS connector',
        ],
        'call_external_connector' => [
            'risk' => self::RISK_MUTATING,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TASKPROCESSING_CONFIRMED],
            'requiresConfirmation' => true,
            'description' => 'Call a configured external connector endpoint',
        ],
        // An image search sends the query to the same external index as a web
        // search and is gated by the same switch. It exists as its own tool
        // because a text search cannot satisfy "show me pictures of X" - the
        // model would answer that it cannot display images.
        'search_images' => [
            'risk' => self::RISK_READONLY,
            'surfaces' => [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED, self::SURFACE_RAG],
            'requiresConfirmation' => false,
            'description' => 'Find pictures of a subject on the web',
        ],
    ];

    private string $activeSurface = self::SURFACE_WEB;

    /**
     * Forward the user identity to the internal AppConfig so per-user
     * settings (e.g. web_search_enabled) are resolved correctly.
     */
    public function setUserId(?string $userId): void {
        $this->appConfig->setUserId($userId);
    }

    /**
     * Set the execution surface for the current context.
     */
    public function setSurface(string $surface): void {
        $allowed = [self::SURFACE_WEB, self::SURFACE_TALK, self::SURFACE_RAG, self::SURFACE_TASKPROCESSING, self::SURFACE_TASKPROCESSING_CONFIRMED];
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

        // Privacy opt-out (Issue #69): the weather tool calls the external
        // Open-Meteo services. When disabled it is removed from every tool
        // surface, blocked at dispatch, and skipped by the agent proposal
        // phase - one check covers all execution paths.
        if ($toolName === 'weather' && $this->appConfig->getInt('weather_tool_enabled', 1) === 0) {
            return [
                'allowed' => false,
                'reason' => 'Weather tool disabled by configuration',
            ];
        }

        // Privacy opt-out (Issue #187): the web search tool sends the query to
        // a third-party or admin-hosted search service. It is opt-in per
        // instance, and this single check removes it from every tool surface,
        // blocks dispatch and skips it in the agent proposal phase.
        if (in_array($toolName, ['web_search', 'open_website', 'search_images'], true)
            && $this->appConfig->getInt('web_search_enabled', 0) !== 1) {
            return [
                'allowed' => false,
                'reason' => 'Web search disabled by configuration',
            ];
        }

        if ($toolName === 'run_safe_command' && $this->appConfig->getInt('safe_commands_enabled', 0) !== 1) {
            return ['allowed' => false, 'reason' => 'Safe local diagnostics are disabled by configuration'];
        }

        // The Talk tools only make sense on an instance that runs Talk; with
        // no Talk they would be offered and then fail at call time.
        if (in_array($toolName, ['list_talk_rooms', 'read_talk_chat', 'send_talk_message'], true)
            && $this->talkChat !== null && !$this->talkChat->isAvailable()) {
            return [
                'allowed' => false,
                'reason' => 'Nextcloud Talk is not installed or not enabled on this server',
            ];
        }

        // Writing into a chat speaks in the user's name. The switch is per
        // user and off by default, and this single check removes the tool from
        // every surface, blocks dispatch and skips it in the agent proposal
        // phase.
        if ($toolName === 'send_talk_message'
            && $this->appConfig->getInt('talk_write_enabled', 0) !== 1) {
            return [
                'allowed' => false,
                'reason' => 'Posting to Nextcloud Talk is disabled in the EVA AI settings',
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
            if (!in_array($this->activeSurface, $meta['surfaces'], true)) {
                continue;
            }
            // Respect configuration gates (e.g. a disabled weather or web
            // search tool) so a caller can never re-expose a switched-off
            // tool by listing the surface itself.
            if (!($this->check($name)['allowed'] ?? false)) {
                continue;
            }
            $result[$name] = $meta;
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
