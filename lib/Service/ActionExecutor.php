<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Accounts\IAccountManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUserManager;
use OCP\Server;

/**
 * Führt "AI Actions" auf dem gesamten Benutzer-Dateibereich aus.
 *
 * Anders als die frühere Sandbox-Variante darf das Modell Dateien im ganzen
 * Home-Verzeichnis des eingeloggten Benutzers anlegen, umbenennen, lesen,
 * durchsuchen und löschen - plus Notizen (Notes-Ordner) und Kontakte
 * (CardDAV-Adressbuch des Benutzers).
 */
class ActionExecutor {
    private const MAX_SEARCH_DEPTH = 5;
    private const MAX_SEARCH_NODES = 2000;
    private const MAX_SEARCH_FILE_BYTES = 1048576; // 1 MB per text file
    private const MAX_SEARCH_RESULTS = 50;
    private const MAX_LIST_DEPTH = 2;
    private const MAX_LIST_ENTRIES = 300;
    private const MAX_READ_CHARS = 20000;
    private const MAX_READ_CHUNK_CHARS = 100000;
    private const MAX_READ_FILE_BYTES = 8388608; // 8 MB safety limit
    private const LEARNED_API_TTL = 2592000; // refresh route metadata monthly
    private const APP_API_TIMEOUT = 30;
    private const MAX_WRITE_CHARS = 100000;
    private const KNOWLEDGE_MAX_CHARS = 60000;
    private const KNOWLEDGE_TARGET_CHARS = 45000;
    private const KNOWLEDGE_PROFILE_MARKER = '<!-- eva_ai:profile-initialized -->';
    private const NOTES_FOLDER = 'Notes';

    /**
     * Arguments a tool call needs before it may run without asking the user.
     *
     * On the interactive WEB surface, complete and explicit requests execute
     * immediately. The confirmation/completion dialog is only shown when one
     * of these required arguments is missing or empty, so the user can fill
     * it in. Other surfaces keep the strict confirmation gate.
     */
    private const REQUIRED_ARGS = [
        // Files / notes
        'create_file' => ['path', 'content'],
        'create_files' => ['files'],
        'create_note' => ['title', 'content'],
        'create_folder' => ['path'],
        'rename_file' => ['path', 'new_name'],
        'move_file' => ['path', 'target_path'],
        'copy_file' => ['path', 'target_path'],
        'file_checksum' => ['path'],
        'read_files' => ['files'],
        'delete_file' => ['path'],
        'inspect_file' => ['path'],
        'extract_file_text' => ['path'],
        'update_knowledge' => ['fact'],
        // Profile (any field set is explicit; no single mandatory argument)
        'update_profile' => [],
        // Contacts
        'create_contact' => ['name'],
        'update_contact' => ['query'],
        'delete_contact' => ['query'],
        // Calendar
        'create_calendar_event' => ['summary', 'start'],
        'update_calendar_event' => ['event_id'],
        'delete_calendar_event' => ['event_id'],
        // Shares
        'create_share' => ['path'],
        'update_share' => ['share_id'],
        'delete_share' => ['share_id'],
        // Talk
        'send_talk_message' => ['room', 'message'],
        // Tasks
        'create_task' => ['title'],
        'update_task' => ['task_id'],
        'complete_task' => ['task_id'],
        'delete_task' => ['task_id'],
        'add_comment' => ['object_type', 'object_id', 'message'],
        'delete_comment' => ['comment_id'],
        'tag_file' => ['file_id', 'tag'],
        'untag_file' => ['file_id', 'tag'],
        'restore_file_version' => ['file_id', 'version_id'],
        'call_app_api' => ['app_id', 'path', 'method'],
        'run_safe_command' => ['command'],
        'configure_external_connector' => ['id', 'base_url'],
        'call_external_connector' => ['id', 'path', 'method'],
        'create_scheduled_briefing' => ['prompt', 'time', 'days'],
        'update_scheduled_briefing' => ['briefing_id'],
        'delete_scheduled_briefing' => ['briefing_id'],
    ];

    /**
     * Return the keys of required arguments that are missing or empty.
     *
     * @return string[]
     */
    private function missingRequiredArgs(string $name, array $args): array {
        $required = self::REQUIRED_ARGS[$name] ?? [];
        // Sharing with a user/group additionally needs the concrete recipient.
        if ($name === 'create_share' && in_array((string)($args['type'] ?? 'link'), ['user', 'group'], true)) {
            $required[] = 'target';
        }
        $missing = [];
        foreach ($required as $key) {
            $value = $args[$key] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                $missing[] = $key;
            }
        }
        return array_values(array_unique($missing));
    }

    public function __construct(
        private IRootFolder $rootFolder,
        private IContactsManager $contacts,
        private AppConfig $config,
        private IAccountManager $accounts,
        private IUserManager $userManager,
        private CalendarService $calendar,
        private EmailService $email,
        private SharesService $shares,
        private ActivityService $activity,
        private ToolPolicy $toolPolicy,
        private WebSearchService $webSearch,
        private TalkChatService $talkChat,
        private \OCP\Lock\ILockingProvider $lockingProvider,
        private ?Indexer $indexer = null,
        private ?\OCP\Comments\ICommentsManagerFactory $commentsFactory = null,
        private ?\OCP\SystemTag\ISystemTagManagerFactory $systemTagFactory = null
    ) {
    }

    /**
     * Set the user context on both the internal AppConfig and ToolPolicy
     * so per-user settings (e.g. web_search_enabled) are resolved correctly.
     * Must be called before tools() and run().
     */
    public function setUserId(?string $userId): void {
        $this->config->setUserId($userId);
        $this->toolPolicy->setUserId($userId);
        $this->webSearch->setUserId($userId);
    }

    /**
     * Set the execution surface for tool permission checks.
     */
    public function setSurface(string $surface): void {
        $this->toolPolicy->setSurface($surface);
    }

    /**
     * Get the ToolPolicy instance for external surface configuration.
     */
    public function getToolPolicy(): ToolPolicy {
        return $this->toolPolicy;
    }

    /** @return array<int,array{type:string,function:array}> */
    public function tools(): array {
        $output = [
            ['type' => 'function', 'function' => [
                'name' => 'list_files',
                'description' => 'List files and folders inside the logged-in user\'s Nextcloud home. Use it to find out what the user has stored.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Optional folder, e.g. "Documents". Empty means the home root.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_file',
                'description' => 'Create (or overwrite) a text file anywhere in the user\'s Nextcloud home, e.g. for drafts, notes, plans or documents. Only configured text file types are allowed. The content must be plain text; for complex Office files use a suitable app API or existing template and then validate the result.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from the home folder, e.g. "Documents/Plan.md" or "Report.txt".'],
                    'content' => ['type' => 'string', 'description' => 'The full text content to write.'],
                ], 'required' => ['path', 'content']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_files',
                'description' => 'Create or update up to 20 related plain-text files in one agent step. Each file is validated with the same allowed-type and size limits as create_file; failures are returned per file so successful files are not lost.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'files' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                        'path' => ['type' => 'string'], 'content' => ['type' => 'string'],
                    ], 'required' => ['path', 'content']]],
                ], 'required' => ['files']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_note',
                'description' => 'Create a Markdown note in the standard Notes folder of the user (visible in the Nextcloud Notes app). Perfect for quick notes, meeting minutes or todos.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Title of the note without extension, e.g. "Meeting minutes".'],
                    'content' => ['type' => 'string', 'description' => 'The Markdown body of the note.'],
                ], 'required' => ['title', 'content']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_folder',
                'description' => 'Create a new folder anywhere in the user\'s Nextcloud home.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative folder path, e.g. "Projekte/2026".'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'rename_file',
                'description' => 'Rename a file or folder in the user\'s home. The new name must stay in the same directory.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Current relative path, e.g. "Drafts/old.md".'],
                    'new_name' => ['type' => 'string', 'description' => 'New file or folder name including extension, e.g. "final.md".'],
                ], 'required' => ['path', 'new_name']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'move_file',
                'description' => 'Move a file or folder to another directory in the user\'s home. target_path is the final relative path including the new name; destination folders are created when needed.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Current relative path.'],
                    'target_path' => ['type' => 'string', 'description' => 'Final relative path including the name.'],
                ], 'required' => ['path', 'target_path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'copy_file',
                'description' => 'Copy a file or folder to another directory in the user\'s home. target_path is the final relative path including the new name; destination folders are created when needed.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Source relative path.'],
                    'target_path' => ['type' => 'string', 'description' => 'Final destination path including the name.'],
                ], 'required' => ['path', 'target_path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'file_checksum',
                'description' => 'Calculate a SHA-256 checksum for a file so complex operations can be validated without exposing its contents.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative file path.'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_file',
                'description' => 'Delete a file or an empty folder in the user\'s home. Use only when the user explicitly asks to delete something. Depending on the app settings you may only delete files EVA created itself.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path of the file or folder to delete.'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_file',
                'description' => 'Read a text file in the user\'s home in pages. The default page is 20k characters; when has_more is true, call again with next_offset until the requested file is fully read.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path, e.g. "Documents/Notes.md".'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Character offset for the page, normally the previous response\'s next_offset.'],
                    'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'description' => 'Characters to return (default 20000, maximum 100000).'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'extract_file_text',
                'description' => 'Extract text from Office documents, PDFs, e-books and other indexed formats. Use this for complex files that read_file cannot decode; results are paginated.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path, e.g. "Documents/report.xlsx".'],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                    'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_files',
                'description' => 'Read up to 20 bounded text files in one agent step. Each result is paginated and errors are isolated per file.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'files' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                        'path' => ['type' => 'string'], 'offset' => ['type' => 'integer', 'minimum' => 0], 'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
                    ], 'required' => ['path']]],
                ], 'required' => ['files']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'inspect_file',
                'description' => 'Inspect a file or folder after a complex operation without reading its content. Returns path, type, size, MIME type and modification time.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path, e.g. "Documents/report.xlsx".'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_files',
                'description' => 'Search the user\'s entire Nextcloud home for files by name or content keywords.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Keyword to look for in file and folder names and in bounded text-file content (case-insensitive).'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_knowledge',
                'description' => 'Append personal facts about the user to the knowledge file KNOWLEDGE.md in the home folder (e.g. name, family, work, preferences, allergies, plans). Call it whenever the user shares such information explicitly. The file is read before every answer, so the fact will be considered in all future chats. Facts are appended as one bullet per entry, never overwrite old entries.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'Short, factual sentence about the user, e.g. "Likes green tea, no milk".'],
                ], 'required' => ['fact']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_contacts',
                'description' => 'List all contacts in the user\'s address books (name, e-mail, phone, organisation). Use this when the user asks which contacts they have or wants to see all contacts without a specific search term.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'find_contact',
                'description' => 'Search the user\'s contacts (address books) by name, e-mail or organisation. Returns matching contact details. If the query is empty, all contacts are returned.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Name, e-mail or organisation to search for. Leave empty to list all contacts.'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_contact',
                'description' => 'Add a new contact to the user\'s personal address book (CardDAV).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Full display name of the contact.'],
                    'email' => ['type' => 'string', 'description' => 'Optional e-mail address.'],
                    'phone' => ['type' => 'string', 'description' => 'Optional phone number.'],
                    'org' => ['type' => 'string', 'description' => 'Optional organisation.'],
                ], 'required' => ['name']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_profile',
                'description' => 'Read the logged-in user\'s own Nextcloud profile (display name, e-mail, phone, website, address, organisation, role, headline, biography, pronouns).',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_profile',
                'description' => 'Update the logged-in user\'s own Nextcloud profile. Only pass the fields that should change. Use empty string to clear a field.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'display_name' => ['type' => 'string', 'description' => 'New display name.'],
                    'email' => ['type' => 'string', 'description' => 'New primary e-mail address.'],
                    'phone' => ['type' => 'string', 'description' => 'Phone number.'],
                    'website' => ['type' => 'string', 'description' => 'Website URL.'],
                    'address' => ['type' => 'string', 'description' => 'Postal address.'],
                    'organisation' => ['type' => 'string', 'description' => 'Organisation / company.'],
                    'role' => ['type' => 'string', 'description' => 'Job title / role.'],
                    'headline' => ['type' => 'string', 'description' => 'Short headline or tagline.'],
                    'biography' => ['type' => 'string', 'description' => 'About / biography text.'],
                    'pronouns' => ['type' => 'string', 'description' => 'Pronouns, e.g. "he/him".'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_contact',
                'description' => 'Update an existing contact of the user (address book). Identify it with query (name, e-mail or organisation).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Contact name, e-mail or organisation of the existing contact.'],
                    'name' => ['type' => 'string', 'description' => 'Optional new full display name.'],
                    'email' => ['type' => 'string', 'description' => 'Optional new e-mail address (empty to remove).'],
                    'phone' => ['type' => 'string', 'description' => 'Optional new phone number (empty to remove).'],
                    'org' => ['type' => 'string', 'description' => 'Optional new organisation (empty to remove).'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_contact',
                'description' => 'Delete a contact from the user\'s address book. Use only when the user explicitly asks to delete it.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Contact name, e-mail or organisation to delete.'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_calendars',
                'description' => 'List all Nextcloud calendars of the user with their ids.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_calendar_events',
                'description' => 'List calendar events in a time window. Default: today up to the next 60 days.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'Convenience: include the next N days starting today (1-60). Equivalent to end_date = today+N.'],
                    'past_days' => ['type' => 'integer', 'description' => 'Convenience: include the past N days (0-30). Default 0.'],
                    'start_date' => ['type' => 'string', 'description' => 'Optional start of the window, ISO-8601 like "2026-08-09".'],
                    'end_date' => ['type' => 'string', 'description' => 'Optional end of the window, ISO-8601.'],
                    'calendar' => ['type' => 'string', 'description' => 'Optional calendar name to limit the search.'],
                    'categories' => ['type' => 'string', 'description' => 'Optional comma-separated category filter, e.g. "arbeit,privat".'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_calendar_event',
                'description' => 'Create a new calendar event (meetings, appointments, reminders). Times WITHOUT a "Z" suffix are interpreted in the USER timezone (Europe/Berlin) - so write local times like "2026-08-20 16:00" or "20.08.2026 16:00" or "morgen 10:00", never append Z. Append "Z" only if the user explicitly talks about UTC. A plain date creates an all-day event. Before calculating dates, call current_time to get the actual date.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'Event title, e.g. "Team meeting".'],
                    'start' => ['type' => 'string', 'description' => 'Start time in any supported format.'],
                    'end' => ['type' => 'string', 'description' => 'Optional end time. Default: 1 hour later (all-day: next day).'],
                    'duration_minutes' => ['type' => 'integer', 'description' => 'Optional duration in minutes. Default 60 (or 1 day for all-day). Ignored if end is set.'],
                    'location' => ['type' => 'string', 'description' => 'Optional location / place.'],
                    'description' => ['type' => 'string', 'description' => 'Optional description or agenda.'],
                    'reminder_minutes' => ['type' => 'integer', 'description' => 'Optional reminder X minutes before the event, e.g. 15 or 60.'],
                    'categories' => ['type' => 'string', 'description' => 'Optional comma-separated categories/tags, e.g. "arbeit,privat".'],
                    'calendar' => ['type' => 'string', 'description' => 'Optional calendar name or id; default is the first calendar.'],
                ], 'required' => ['summary', 'start']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_calendar_event',
                'description' => 'Update an existing calendar event (title, times, location, description, categories, reminder). Use the event id from list_calendar_events.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'event_id' => ['type' => 'string', 'description' => 'id like "personal/event.ics" as returned by list_calendar_events.'],
                    'summary' => ['type' => 'string', 'description' => 'New title.'],
                    'start' => ['type' => 'string', 'description' => 'New start, ISO-8601 UTC or plain date.'],
                    'end' => ['type' => 'string', 'description' => 'New end.'],
                    'location' => ['type' => 'string', 'description' => 'New location (empty string removes it).'],
                    'description' => ['type' => 'string', 'description' => 'New description (empty string removes it).'],
                    'categories' => ['type' => 'string', 'description' => 'New categories (comma separated). Empty string removes them.'],
                    'reminder_minutes' => ['type' => 'integer', 'description' => 'Replace reminder with a single VALARM that fires X minutes before the event. 0 removes the reminder.'],
                ], 'required' => ['event_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_calendar_event',
                'description' => 'Delete a calendar event. Use only when the user explicitly asks to delete it.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'event_id' => ['type' => 'string', 'description' => 'id like "personal/event.ics" as returned by list_calendar_events.'],
                ], 'required' => ['event_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'find_free_slots',
                'description' => 'Find free time slots in the user\'s calendar within the next N days, respecting the configured working hours (default 09:00-18:00 in the user\'s timezone). Returns at most 10 slots with length >= min_minutes. Useful before scheduling a meeting.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'How many days to look ahead (1-30). Default 7.'],
                    'min_minutes' => ['type' => 'integer', 'description' => 'Minimum slot length in minutes (5-480). Default 30.'],
                    'workday_start' => ['type' => 'string', 'description' => 'Working day start "HH:MM". Default 09:00.'],
                    'workday_end' => ['type' => 'string', 'description' => 'Working day end "HH:MM". Default 18:00.'],
                    'calendar' => ['type' => 'string', 'description' => 'Optional calendar name or id; default = all user calendars.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_mails',
                'description' => 'Search emails of the user\'s mail account (subject, sender, preview). Typical use: "find the mail about X" or "show my latest mails".',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search text, e.g. "Rechnung" or "alice@example.com".'],
                    'limit' => ['type' => 'integer', 'description' => 'Optional max results (default 10).'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_mails',
                'description' => 'List the most recent emails of the user (latest first). Use when the user asks about their mail without a concrete topic.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Optional max mails (default 15).'],
                    'unread_only' => ['type' => 'boolean', 'description' => 'Optional: only unread mails.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_mail',
                'description' => 'Read the full content of a single email by its id (ids come from list_mails / search_mails).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'message_id' => ['type' => 'integer', 'description' => 'Id of the email.'],
                ], 'required' => ['message_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'unread_mail_count',
                'description' => 'Get how many unread emails the user currently has.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_shares',
                'description' => 'List all file/folder shares of the user: outgoing (link + user/group shares) and incoming shares from others, with expiry, note and link.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Optional max entries (default 100).'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_share',
                'description' => 'Create a new share for a file or folder in the user\'s Nextcloud (public link or share with a user/group). Use for "share this file with X" or "make a download link".',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path of the file/folder, e.g. "Documents/Plan.pdf".'],
                    'type' => ['type' => 'string', 'description' => 'Share type: "link" (default, public link), "user" or "group".'],
                    'target' => ['type' => 'string', 'description' => 'For user/group shares: the user id or group id to share with.'],
                    'write' => ['type' => 'boolean', 'description' => 'Optional: allow editing (default read-only).'],
                    'share' => ['type' => 'boolean', 'description' => 'Optional: allow recipients to reshare (default false).'],
                    'password' => ['type' => 'string', 'description' => 'Optional password for link shares.'],
                    'expiration' => ['type' => 'string', 'description' => 'Optional expiration date, ISO like "2026-12-31".'],
                    'note' => ['type' => 'string', 'description' => 'Optional note / message for the share.'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_share',
                'description' => 'Update an existing share (note, expiration date, permissions). Use share ids from list_shares.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'share_id' => ['type' => 'string', 'description' => 'Id from list_shares.'],
                    'note' => ['type' => 'string', 'description' => 'New note (empty removes it).'],
                    'expiration' => ['type' => 'string', 'description' => 'Optional expiration date ISO, empty removes it.'],
                    'permissions' => ['type' => 'string', 'description' => 'Comma list: read,write,create,delete,share.'],
                ], 'required' => ['share_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_share',
                'description' => 'Delete an existing share. Use only when the user explicitly asks to remove a share or link.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'share_id' => ['type' => 'string', 'description' => 'Id from list_shares.'],
                ], 'required' => ['share_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_tasks',
                'description' => 'List to-do items / tasks of the user. Open tasks first, then by due date. Filters: status (comma separated iCalendar statuses e.g. "NEEDS-ACTION,IN-PROCESS"), category, overdue_only (boolean).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Optional filter by status, e.g. "NEEDS-ACTION" or "NEEDS-ACTION,IN-PROCESS".'],
                    'category' => ['type' => 'string', 'description' => 'Optional filter by category/tag.'],
                    'overdue_only' => ['type' => 'boolean', 'description' => 'If true, return only tasks with due date in the past that are not completed.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_task',
                'description' => 'Create a new to-do item / task for the user in their default task list.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Task title.'],
                    'due' => ['type' => 'string', 'description' => 'Optional due date, any supported format, e.g. "2026-08-20 16:00" or "morgen".'],
                    'description' => ['type' => 'string', 'description' => 'Optional longer description / notes.'],
                    'priority' => ['type' => 'integer', 'description' => 'Optional priority 1-9 (1 highest).'],
                    'categories' => ['type' => 'string', 'description' => 'Optional comma separated categories/tags.'],
                ], 'required' => ['title']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_task',
                'description' => 'Update a task (title, status, due date, description, categories, priority). Use task ids from list_tasks.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'task_id' => ['type' => 'string', 'description' => 'Id like "personal/task.ics" from list_tasks.'],
                    'title' => ['type' => 'string', 'description' => 'New title.'],
                    'status' => ['type' => 'string', 'description' => 'New status: NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED.'],
                    'due' => ['type' => 'string', 'description' => 'New due date, ISO or relative.'],
                    'description' => ['type' => 'string', 'description' => 'New description (empty removes it).'],
                    'categories' => ['type' => 'string', 'description' => 'New categories (comma separated). Empty removes them.'],
                    'priority' => ['type' => 'integer', 'description' => 'New priority 1-9 (1 highest). 0 removes the priority.'],
                ], 'required' => ['task_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'complete_task',
                'description' => 'Mark a task as completed. Use when the user says a task is done.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'task_id' => ['type' => 'string', 'description' => 'Task id from list_tasks.'],
                ], 'required' => ['task_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_task',
                'description' => 'Delete a task permanently. Use only when the user explicitly asks to delete it.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'task_id' => ['type' => 'string', 'description' => 'Task id from list_tasks.'],
                ], 'required' => ['task_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'recent_activity',
                'description' => 'List the recent Nextcloud activity feed of the user (files changed, shares, events) across all apps.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Optional max entries (default 25).'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_comments',
                'description' => 'Read comments attached to a Nextcloud object, usually a file. Use object_type "files" and the numeric file id. Only comments visible to the current user are returned.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'object_type' => ['type' => 'string', 'description' => 'Nextcloud object type, normally files.'],
                    'object_id' => ['type' => 'string', 'description' => 'Object id, normally the file id.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum comments, 1-100.'],
                ], 'required' => ['object_type', 'object_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'add_comment',
                'description' => 'Add a comment to a Nextcloud object after the user explicitly asks to comment, annotate or reply. This requires confirmation before posting.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'object_type' => ['type' => 'string', 'description' => 'Nextcloud object type, normally files.'],
                    'object_id' => ['type' => 'string', 'description' => 'Object id, normally the file id.'],
                    'message' => ['type' => 'string', 'description' => 'Exact comment text to post.'],
                    'parent_id' => ['type' => 'string', 'description' => 'Optional parent comment id for a reply.'],
                ], 'required' => ['object_type', 'object_id', 'message']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_comment',
                'description' => 'Delete a comment by id after explicit user confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'comment_id' => ['type' => 'string', 'description' => 'Comment id returned by list_comments.'],
                ], 'required' => ['comment_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_system_tags',
                'description' => 'List visible Nextcloud system tags. Use this before tagging files so you reuse the exact existing tag name.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Optional name fragment.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'tag_file',
                'description' => 'Assign an existing or user-assignable system tag to a file. This changes file metadata and requires confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'file_id' => ['type' => 'string', 'description' => 'Numeric Nextcloud file id.'],
                    'tag' => ['type' => 'string', 'description' => 'Exact system tag name.'],
                ], 'required' => ['file_id', 'tag']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'untag_file',
                'description' => 'Remove a system tag from a file. This changes file metadata and requires confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'file_id' => ['type' => 'string', 'description' => 'Numeric Nextcloud file id.'],
                    'tag' => ['type' => 'string', 'description' => 'Exact system tag name.'],
                ], 'required' => ['file_id', 'tag']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_file_versions',
                'description' => 'List available versions of a file. Use the numeric file id from list_files/search_files.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'file_id' => ['type' => 'string', 'description' => 'Numeric Nextcloud file id.'],
                ], 'required' => ['file_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'restore_file_version',
                'description' => 'Restore a selected file version. This replaces the current file and always requires confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'file_id' => ['type' => 'string', 'description' => 'Numeric Nextcloud file id.'],
                    'version_id' => ['type' => 'string', 'description' => 'Version/revision id returned by list_file_versions.'],
                ], 'required' => ['file_id', 'version_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'server_status',
                'description' => 'Get technical status info of the Nextcloud server (version, PHP, database, app version, Ollama connectivity, user). Use when the user asks about the system, server or setup.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'run_safe_command',
                'description' => 'Run one allowlisted read-only local diagnostic command. Requires the user setting and explicit confirmation. Never accepts shell syntax, scripts, pipes or redirects.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'command' => ['type' => 'string', 'enum' => ['date', 'uptime', 'php_version', 'node_version', 'disk_free', 'memory_free', 'eva_git_status']],
                ], 'required' => ['command']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_nextcloud_capabilities',
                'description' => 'Discover which Nextcloud apps are enabled and which EVA integrations are available before planning a task. This is read-only and never exposes secrets. Use it when the user asks EVA to work with a Nextcloud feature you have not used before.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'discover_app_api',
                'description' => 'Discover the installed Nextcloud API routes of an enabled app so you can plan a supported action. Set include_internal=true to learn non-OCS app routes as well. This is read-only and never executes a route.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'app_id' => ['type' => 'string', 'description' => 'Optional Nextcloud app id, e.g. deck, bookmarks, forms. Omit to summarize all enabled app routes.'],
                    'include_internal' => ['type' => 'boolean', 'description' => 'Include internal non-OCS routes (default false). Required before calling a non-OCS route.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_learned_app_apis',
                'description' => 'List sanitized Nextcloud app API routes EVA learned earlier for this user. Read-only; use discover_app_api to refresh an app.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_learned_file_locations',
                'description' => 'List bounded file and folder paths EVA learned from earlier Nextcloud searches and listings. Use this to navigate directly before doing another broad search.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'call_app_api',
                'description' => 'Call a discovered endpoint of an enabled Nextcloud app in the current user session. OCS and other same-origin app routes are supported when discovered first. Read methods are allowed; POST, PUT, PATCH and DELETE always require explicit confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'app_id' => ['type' => 'string', 'description' => 'Enabled Nextcloud app id, e.g. deck or bookmarks.'],
                    'path' => ['type' => 'string', 'description' => 'Same-origin route path returned by discover_app_api. OCS paths begin with /ocs/v1.php/apps/{app_id}/ or /ocs/v2.php/apps/{app_id}/; internal app routes must have been discovered with include_internal=true.'],
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                    'params' => ['type' => 'object', 'description' => 'Query/body parameters for the OCS endpoint. Never include credentials.'],
                ], 'required' => ['app_id', 'path', 'method']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_scheduled_briefings',
                'description' => 'List the current user\'s EVA scheduled briefings and their action permissions.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_scheduled_briefing',
                'description' => 'Create a recurring EVA briefing. Use days 1-7 for Monday-Sunday. Read-only is the default; allow_actions must be explicitly true to permit autonomous changes.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'What EVA should do at the scheduled time.'],
                    'time' => ['type' => 'string', 'description' => 'Local time in HH:MM format.'],
                    'days' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Weekdays 1 (Monday) through 7 (Sunday).'],
                    'allow_actions' => ['type' => 'boolean', 'description' => 'Optional explicit opt-in for autonomous tool actions. Defaults to false.'],
                ], 'required' => ['prompt', 'time', 'days']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_scheduled_briefing',
                'description' => 'Update an existing EVA briefing by id. Only supplied fields change.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'briefing_id' => ['type' => 'string', 'description' => 'Id returned by list_scheduled_briefings.'],
                    'prompt' => ['type' => 'string'], 'time' => ['type' => 'string'],
                    'days' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'enabled' => ['type' => 'boolean'], 'allow_actions' => ['type' => 'boolean'],
                ], 'required' => ['briefing_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_scheduled_briefing',
                'description' => 'Delete an EVA scheduled briefing by id.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'briefing_id' => ['type' => 'string', 'description' => 'Id returned by list_scheduled_briefings.'],
                ], 'required' => ['briefing_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'current_time',
                'description' => 'Get the current date and time in the user\'s timezone. IMPORTANT: as an AI model you do not know today\'s date - always call this tool before computing dates, deadlines, appointments or relative times.',
                'parameters' => ['type' => 'object', 'properties' => []],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'weather',
                'description' => 'Get the weather forecast (today + 2 days) for a place. Useful for planning outdoor appointments.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City or place, e.g. "Berlin" or "München".'],
                ], 'required' => ['location']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'web_search',
                'description' => 'Search the public web and the news for information that is not in the indexed files (news, releases, prices, documentation, current events). Use this whenever you need up-to-date information your indexed files do not contain. '
                    . 'Each hit has a `title`, `url`, `snippet` and `content` (the readable text of the page itself - prefer it over the snippet, it is the source). `highlights` holds the passages of that page which actually mention the query: quote from them when they answer the question. `images` lists pictures from the page (the page\'s own preview image first) - when a picture helps the answer, embed it with markdown image syntax using its `url`; do this for the picture that illustrates your answer. `published` is the publication date when the source states one, and `source` names the outlet. '
                    . 'Set `mode` to "news" for anything current (this week, latest, released, announced, price now) and to "all" when you want both background and the newest coverage; the default "web" is a plain web search. '
                    . 'The results are already ordered with the best and most recent first, so prefer the earlier entries, and NEVER prefer your own memory over them: your training data is older than these results, so if they contradict what you remember, the results are right. If the results do not answer the question, search AGAIN with a different, better query (shorter, different words, the product or event name) - you may run several searches for one question - and use `open_website` to read a promising page in full before giving up. '
                    . 'Cite the URLs you actually used as markdown links, state how recent your sources are, and say so when the pages do not answer the question. '
                    . 'When the topic is something you can see - a product, device, vehicle, place, building, event, artwork, animal, dish or logo - also call `search_images` for it and embed two to four of the pictures, even when the user only asked for information: a picture of the thing being described is part of a good answer, and it costs the user nothing.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query, in the user\'s language. Keep it short and specific - it is sent to an external search engine.'],
                    'mode' => ['type' => 'string', 'enum' => WebSearchService::MODES, 'description' => 'Which index to search: "web" (default), "news" for recent articles with dates, or "all" to merge both.'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_images',
                'description' => 'Find pictures of a subject on the web and show them to the user. You CAN display pictures: whenever the user asks to see images, photos, pictures or a logo of something ("show me pictures of X", "zeig mir Bilder von X", "wie sieht X aus", "what does X look like"), call this tool. NEVER reply that you are unable to show images. '
                    . 'Every hit carries `url` (the picture itself - embed it with markdown image syntax `![title](url)`), `title` (a caption, use it as the alt text), `page` (the page the picture was found on - link it) and `preview` (a thumbnail that always loads). '
                    . 'Embed two to four pictures with `![title](url)` so they appear in the answer, then one short sentence about them. '
                    . 'You do not need to be asked: for anything visual - a product, device, vehicle, place, building, event, artwork, animal, dish or logo - show the pictures alongside your answer, including when the user asked a factual question about it. '
                    . 'When the user asks for a picture together with facts (e.g. "show me pictures of the Eiffel Tower and tell me when it was built"), also run a web_search for the facts and mention the source. '
                    . 'Use the words the user used as the query; do not send personal or confidential details.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What the pictures should show, e.g. "golden retriever puppy" or "Nextcloud Hub logo".'],
                    'count' => ['type' => 'integer', 'description' => 'Optional number of pictures (1-12, default 6).'],
                ], 'required' => ['query']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_talk_rooms',
                'description' => 'List the Nextcloud Talk conversations the user is a member of, most recently active first. Each entry carries `name`, `token`, `id`, `type` (one-to-one, group, public) and `lastActivity`. Use it first when the user refers to a chat by name ("the project room", "mein Chat mit Anna") instead of naming a token, and before read_talk_chat or send_talk_message. Only the user\'s own rooms are ever returned.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Optional maximum number of rooms (default 25).'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_talk_chat',
                'description' => 'Read the recent messages of one Nextcloud Talk conversation, oldest first, each dated and attributed to its author. Use this whenever the user asks about the content of a chat ("what did we agree in X?", "was hat Anna im Projekt-Chat geschrieben?", "worum ging es heute in Y?") - the indexed chat history may be older than the conversation, so the current messages come from here. `room` takes the name, token or id from `list_talk_rooms`. Only rooms the user is a member of can be read; a room they left answers as if it did not exist.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'room' => ['type' => 'string', 'description' => 'The room to read: its name, token or numeric id (see list_talk_rooms).'],
                    'limit' => ['type' => 'integer', 'description' => 'Optional number of recent messages to read (5-200, default 50).'],
                    'unread_only' => ['type' => 'boolean', 'description' => 'Optional: return only messages newer than the user\'s Talk read marker.'],
                ], 'required' => ['room']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'send_talk_message',
                'description' => 'Post a message into a Nextcloud Talk conversation as the user who is asking. It appears under their name, exactly as if they had typed it - there is no bot label, so only do this when the user explicitly asks you to write, send, answer, announce or forward something in a chat ("schreib in den Projekt-Chat, dass ...", "tell the team in X that ...", "antworten im Chat Y: ..."). `room` takes the name, token or id from `list_talk_rooms`. Use the user\'s own wording for the message and do not add anything to it; afterwards state which room you posted in. Posting is only possible when the user has enabled it in the EVA AI settings.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'room' => ['type' => 'string', 'description' => 'The room to post into: its name, token or numeric id (see list_talk_rooms).'],
                    'message' => ['type' => 'string', 'description' => 'The exact message to post.'],
                ], 'required' => ['room', 'message']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'open_website',
                'description' => 'Open one web page and read its text in pages. Use it after web_search when a result looks relevant or you need a detail. When has_more=true, call again with next_offset until has_more=false to fully read a long source. Returns readable text, matching passages, images and publication date. Only http(s) pages can be opened.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The full http(s) URL of the page, usually taken from a previous web_search result.'],
                    'query' => ['type' => 'string', 'description' => 'Optional: what you are looking for on that page. The most relevant passages are returned first.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Character offset from a previous response next_offset (default 0).'],
                    'max_chars' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 2000000, 'description' => 'Characters to return in this page (default 2,000,000).'],
                ], 'required' => ['url']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_external_connectors',
                'description' => 'List the user-configured external HTTPS connectors (names, hosts and capabilities; never secrets).',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'discover_external_connector',
                'description' => 'Read a configured connector OpenAPI or Swagger description and return a bounded list of available paths, methods and sanitized parameter requirements. Safe, read-only discovery; use it before calling an unfamiliar service.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Configured connector id.'],
                ], 'required' => ['id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'configure_external_connector',
                'description' => 'Create or update a named external connector. Public HTTPS and explicitly local HTTP(S) services (for example TrueNAS or Home Assistant) are supported; the token is encrypted and never shown to EVA.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Stable connector id, lowercase letters, numbers, underscore or hyphen (max 40).'],
                    'name' => ['type' => 'string', 'description' => 'Human-readable connector name.'],
                    'base_url' => ['type' => 'string', 'description' => 'Base URL. Public services must use HTTPS; local private/loopback hosts may use HTTP or HTTPS, e.g. http://homeassistant.local:8123 or https://192.168.1.20.'],
                    'token' => ['type' => 'string', 'description' => 'Optional bearer token; encrypted at rest and never returned.'],
                ], 'required' => ['id', 'base_url']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'call_external_connector',
                'description' => 'Call a configured external connector. Requests are host-pinned, bounded and always require user confirmation; public services require HTTPS while local services may use HTTP; response values are returned for this run only.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'description' => 'Relative path below the connector base URL, e.g. /api/status.'],
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                    'params' => ['type' => 'object', 'description' => 'Query parameters for GET or JSON body fields for other methods.'],
                ], 'required' => ['id', 'path', 'method']],
            ]],
        ];
        // Ollama akzeptiert leere "properties" nur als leeres OBJEKT {}
        foreach ($output as &$t) {
            $t['function']['parameters']['properties'] = (array)$t['function']['parameters']['properties'] === []
                ? (object)[]
                : $t['function']['parameters']['properties'];
        }
        unset($t);

        // Tool definitions are filtered at the same policy boundary as
        // execution. This is important for callers such as Talk and
        // TaskProcessing: a provider must never expose a tool merely because
        // a caller supplied or requested its name.
        $output = array_values(array_filter($output, function (array $tool): bool {
            $name = (string)($tool['function']['name'] ?? '');
            return $name !== '' && ($this->toolPolicy->check($name)['allowed'] ?? false);
        }));

        return $output;
    }

    /**
     * Build tool definitions for a specific execution surface without
     * changing the caller's current surface. This is used by the agent's
     * proposal phase: mutation tools may be shown to the model as candidates,
     * but they are never executed until an explicit confirmation switches to
     * the confirmed TaskProcessing surface.
     *
     * @return array<int,array{type:string,function:array}>
     */
    public function toolsForSurface(string $surface): array {
        $previous = $this->toolPolicy->getSurface();
        $this->toolPolicy->setSurface($surface);
        try {
            return $this->tools();
        } finally {
            $this->toolPolicy->setSurface($previous);
        }
    }

    /**
     * Execute a tool after the caller has explicitly confirmed it.
     *
     * This is intentionally a separate method so ordinary model-generated
     * calls cannot accidentally opt into the confirmation bypass.
     *
     * @return array{ok:bool,result?:mixed,error?:string}
     */
    public function runConfirmed(string $userId, string $name, array $args): array {
        return $this->run($userId, $name, $args, true);
    }

    /**
     * Führt einen Tool-Aufruf aus. Wirft nie - liefert immer {ok, result|error}.
     * @return array{ok:bool,result?:mixed,error?:string,confirmation_required?:bool,tool?:string,risk?:string}
     */
    public function run(string $userId, string $name, array $args, bool $confirmed = false): array {
        $this->setUserId($userId);
        // Centralized tool permission check
        $policy = $this->toolPolicy->check($name);
        if (!$policy['allowed']) {
            return ['ok' => false, 'error' => $policy['reason'] ?? 'Tool not allowed'];
        }
        if (($policy['requiresConfirmation'] ?? false) && !$confirmed) {
            // Generic app API calls are never auto-approved, even when the
            // web surface has complete arguments: the model may have learned
            // an unfamiliar endpoint and the user must review its exact
            // method, path and parameters first.
            if ($name === 'call_app_api' || $name === 'run_safe_command') {
                return [
                    'ok' => false,
                    'confirmation_required' => true,
                    'tool' => $name,
                    'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                    'error' => $name === 'run_safe_command' ? 'Local diagnostic commands always require explicit user confirmation.' : 'Generic app API calls always require explicit user confirmation.',
                ];
            }
            // Interactive web chat: an explicit, complete request runs
            // immediately. The dialog is only shown when required data is
            // still missing (e.g. an event without a name) or no concrete
            // target was resolved, so the user can complete it there.
            if ($this->toolPolicy->getSurface() === ToolPolicy::SURFACE_WEB) {
                $missing = $this->missingRequiredArgs($name, $args);
                if ($missing === []) {
                    // Complete and explicit -> execute directly below.
                } else {
                    return [
                        'ok' => false,
                        'confirmation_required' => true,
                        'tool' => $name,
                        'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                        'missing' => $missing,
                        'error' => 'This action needs more information before it can run: ' . implode(', ', $missing),
                    ];
                }
            } else {
                // Non-interactive surfaces keep the strict confirmation gate.
                return [
                    'ok' => false,
                    'confirmation_required' => true,
                    'tool' => $name,
                    'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                    'error' => 'This action requires explicit user confirmation before it can be executed.',
                ];
            }
        }

        // File tools must work consistently in TaskProcessing workers
        // (occ taskprocessing:worker runs in CLI). The user filesystem is not
        // mounted by default in CLI, so we initialize it with the supported
        // Nextcloud API before resolving the user folder (Issue #10). If the
        // mount still cannot be set up, file tools degrade gracefully while
        // non-file tools keep working.
        $home = null;
        try {
            if (PHP_SAPI === 'cli') {
                \OC_Util::setupFS($userId);
            }
            $home = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            $home = null;
        }

        $fileTools = [
            'list_files', 'create_file', 'create_files', 'create_note', 'create_folder',
            'rename_file', 'move_file', 'copy_file', 'file_checksum', 'delete_file', 'read_file', 'read_files', 'inspect_file', 'search_files',
            'extract_file_text',
            'update_knowledge',
        ];
        if (in_array($name, $fileTools, true) && $home === null) {
            return ['ok' => false, 'error' => 'File tools are not available in the background worker (CLI). Ask in the web chat instead.'];
        }

        try {
            $result = match ($name) {
                'list_files' => $this->listFiles($home, $args),
                'create_file' => $this->createFile($home, $args),
                'create_files' => $this->createFiles($home, $args),
                'create_note' => $this->createNote($home, $args),
                'create_folder' => $this->createFolder($home, $args),
                'rename_file' => $this->renameFile($home, $args),
                'move_file' => $this->moveFile($home, $args),
                'copy_file' => $this->copyFile($home, $args),
                'file_checksum' => $this->fileChecksum($home, $args),
                'delete_file' => $this->deleteFile($home, $args),
                'read_file' => $this->readFile($home, $args),
                'read_files' => $this->readFiles($home, $args),
                'extract_file_text' => $this->extractFileText($home, $args),
                'inspect_file' => $this->inspectFile($home, $args),
                'search_files' => $this->searchFiles($home, $args),
                'list_contacts' => $this->listContacts($userId),
                'find_contact' => $this->findContact($userId, $args),
                'create_contact' => $this->createContact($userId, $args),
                'update_contact' => $this->updateContact($userId, $args),
                'delete_contact' => $this->deleteContact($userId, $args),
                'read_profile' => $this->readProfile($userId),
                'update_profile' => $this->updateProfile($userId, $args),
                'list_calendars' => ['ok' => true, 'result' => $this->calendar->calendars($userId)],
                'list_calendar_events' => $this->calendar->listEvents($userId, $args),
                'create_calendar_event' => $this->calendar->createEvent($userId, $args),
                'update_calendar_event' => $this->calendar->updateEvent($userId, $args),
                'delete_calendar_event' => $this->calendar->deleteEvent($userId, $args),
                'find_free_slots' => $this->calendar->findFreeSlots($userId, $args),
                'current_time' => $this->currentTime($userId),
                'weather' => $this->weather($args),
                'web_search' => $this->runWebSearch($args),
                'search_images' => $this->runImageSearch($args),
                'open_website' => $this->openWebsite($args),
                'list_external_connectors' => $this->listExternalConnectors(),
                'discover_external_connector' => $this->discoverExternalConnector($args),
                'configure_external_connector' => $this->configureExternalConnector($args),
                'call_external_connector' => $this->callExternalConnector($args),
                'list_talk_rooms' => $this->listTalkRooms($userId, $args),
                'read_talk_chat' => $this->readTalkChat($userId, $args),
                'send_talk_message' => $this->sendTalkMessage($userId, $args),
                'search_mails' => $this->searchMails($userId, $args),
                'list_mails' => $this->listMails($userId, $args),
                'read_mail' => $this->readMail($userId, $args),
                'unread_mail_count' => $this->unreadMailCount($userId),
                'list_shares' => $this->shares->list($userId, $args),
                'create_share' => $this->shares->create($userId, $args),
                'update_share' => $this->shares->update($userId, $args),
                'delete_share' => $this->shares->delete($userId, $args),
                'list_tasks' => $this->calendar->listTasks($userId, $args),
                'create_task' => $this->calendar->createTask($userId, $args),
                'update_task' => $this->calendar->updateTask($userId, $args),
                'complete_task' => $this->calendar->completeTask($userId, $args),
                'delete_task' => $this->calendar->deleteTask($userId, $args),
                'recent_activity' => $this->activity->recent($userId, $args),
                'list_comments' => $this->listComments($args),
                'add_comment' => $this->addComment($userId, $args),
                'delete_comment' => $this->deleteComment($args),
                'list_system_tags' => $this->listSystemTags($args),
                'tag_file' => $this->tagFile($userId, $args, false),
                'untag_file' => $this->tagFile($userId, $args, true),
                'list_file_versions' => $this->listFileVersions($home, $args),
                'restore_file_version' => $this->restoreFileVersion($home, $args),
                'server_status' => $this->serverStatus($userId),
                'run_safe_command' => $this->runSafeCommand($args),
                'list_nextcloud_capabilities' => $this->listNextcloudCapabilities(),
                'discover_app_api' => $this->discoverAppApi($args),
                'list_learned_app_apis' => $this->listLearnedAppApis(),
                'list_learned_file_locations' => $this->listLearnedFileLocations(),
                'call_app_api' => $this->callAppApi($args),
                'list_scheduled_briefings' => $this->listScheduledBriefings(),
                'create_scheduled_briefing' => $this->createScheduledBriefing($args),
                'update_scheduled_briefing' => $this->updateScheduledBriefing($args),
                'delete_scheduled_briefing' => $this->deleteScheduledBriefing($args),
                'update_knowledge' => $this->updateKnowledge($home, $args),
                default => ['ok' => false, 'error' => 'Unknown tool: ' . $name],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return $result;
    }

    private function commentsManager(): ?\OCP\Comments\ICommentsManager {
        try {
            $factory = $this->commentsFactory ?? Server::get(\OCP\Comments\ICommentsManagerFactory::class);
            return $factory->getManager();
        } catch (\Throwable) {
            return null;
        }
    }

    private function systemTagServices(): ?array {
        try {
            $factory = $this->systemTagFactory ?? Server::get(\OCP\SystemTag\ISystemTagManagerFactory::class);
            return ['manager' => $factory->getManager(), 'mapper' => $factory->getObjectMapper()];
        } catch (\Throwable) { return null; }
    }

    private function listSystemTags(array $args): array {
        $services = $this->systemTagServices();
        if ($services === null) return ['ok' => false, 'error' => 'Nextcloud system tags are not available.'];
        try {
            $search = trim((string)($args['search'] ?? ''));
            $user = $this->userManager->get($this->config->userId() ?? '');
            $tags = $services['manager']->getAllTags(true, $search !== '' ? '%' . $search . '%' : null);
            $out = [];
            foreach ($tags as $tag) {
                if ($user !== null && !$services['manager']->canUserSeeTag($tag, $user)) continue;
                $out[] = ['id' => (string)$tag->getId(), 'name' => (string)$tag->getName(), 'user_visible' => (bool)$tag->isUserVisible(), 'user_assignable' => (bool)$tag->isUserAssignable(), 'color' => method_exists($tag, 'getColor') ? (string)$tag->getColor() : null];
            }
            return ['ok' => true, 'result' => ['tags' => $out]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'System tags could not be read.']; }
    }

    private function tagFile(string $userId, array $args, bool $remove): array {
        $services = $this->systemTagServices();
        $fileId = trim((string)($args['file_id'] ?? '')); $name = trim((string)($args['tag'] ?? ''));
        if ($services === null) return ['ok' => false, 'error' => 'Nextcloud system tags are not available.'];
        if (!ctype_digit($fileId) || (int)$fileId < 1 || $name === '') return ['ok' => false, 'error' => 'A numeric file_id and non-empty tag are required'];
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) return ['ok' => false, 'error' => 'User not found'];
            $tag = $services['manager']->getTag($name, true, true);
            if (!$services['manager']->canUserAssignTag($tag, $user)) return ['ok' => false, 'error' => 'The user may not assign this system tag.'];
            if ($remove) $services['mapper']->unassignTags($fileId, 'files', (string)$tag->getId());
            else $services['mapper']->assignTags($fileId, 'files', (string)$tag->getId());
            return ['ok' => true, 'result' => ['file_id' => (int)$fileId, 'tag' => (string)$tag->getName(), 'removed' => $remove]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'The system tag could not be changed. Check file access and tag permissions.']; }
    }

    private function versionManager(): ?object {
        $class = '\\OCA\\Files_Versions\\Versions\\IVersionManager';
        if (!interface_exists($class)) return null;
        try { return Server::get($class); } catch (\Throwable) { return null; }
    }

    private function fileById(?Folder $home, array $args): ?File {
        $id = trim((string)($args['file_id'] ?? ''));
        if ($home === null || !ctype_digit($id) || (int)$id < 1) return null;
        try {
            foreach ($home->getById((int)$id) as $node) if ($node instanceof File) return $node;
        } catch (\Throwable) { }
        return null;
    }

    private function listFileVersions(?Folder $home, array $args): array {
        $manager = $this->versionManager();
        $file = $this->fileById($home, $args);
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud files versions app is not available.'];
        if ($file === null) return ['ok' => false, 'error' => 'File not found or not accessible.'];
        try {
            $user = $this->userManager->get($this->config->userId() ?? '');
            if ($user === null) return ['ok' => false, 'error' => 'User not found.'];
            $versions = $manager->getVersionsForFile($user, $file);
            $out = [];
            foreach ($versions as $version) {
                $out[] = [
                    'version_id' => (string)$version->getRevisionId(),
                    'timestamp' => $version->getTimestamp(),
                    'date' => gmdate(DATE_ATOM, $version->getTimestamp()),
                    'size' => $version->getSize(),
                    'name' => $version->getSourceFileName(),
                    'mime_type' => $version->getMimeType(),
                ];
            }
            return ['ok' => true, 'result' => ['file_id' => (int)$file->getId(), 'path' => $file->getPath(), 'versions' => $out]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'File versions could not be read.']; }
    }

    private function restoreFileVersion(?Folder $home, array $args): array {
        $manager = $this->versionManager();
        $file = $this->fileById($home, $args);
        $revision = trim((string)($args['version_id'] ?? ''));
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud files versions app is not available.'];
        if ($file === null || $revision === '') return ['ok' => false, 'error' => 'A valid file_id and version_id are required.'];
        try {
            $user = $this->userManager->get($this->config->userId() ?? '');
            if ($user === null) return ['ok' => false, 'error' => 'User not found.'];
            $version = null;
            foreach ($manager->getVersionsForFile($user, $file) as $candidate) {
                if ((string)$candidate->getRevisionId() === $revision) { $version = $candidate; break; }
            }
            if ($version === null) return ['ok' => false, 'error' => 'That version does not belong to this file or is no longer available.'];
            $manager->rollback($version);
            return ['ok' => true, 'result' => ['file_id' => (int)$file->getId(), 'version_id' => $revision, 'restored' => true]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'The file version could not be restored. Check locks and permissions.']; }
    }

    private function commentData(\OCP\Comments\IComment $comment): array {
        return [
            'id' => (string)$comment->getId(),
            'parent_id' => (string)$comment->getParentId(),
            'object_type' => (string)$comment->getObjectType(),
            'object_id' => (string)$comment->getObjectId(),
            'actor_type' => (string)$comment->getActorType(),
            'actor_id' => (string)$comment->getActorId(),
            'message' => (string)$comment->getMessage(),
            'created' => $comment->getCreationDateTime()?->format(DATE_ATOM),
        ];
    }

    private function listComments(array $args): array {
        $manager = $this->commentsManager();
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        $type = trim((string)($args['object_type'] ?? 'files'));
        $id = trim((string)($args['object_id'] ?? ''));
        if ($type === '' || $id === '') return ['ok' => false, 'error' => 'object_type and object_id are required'];
        $limit = max(1, min(100, (int)($args['limit'] ?? 50)));
        try {
            $comments = $manager->getForObject($type, $id, $limit, 0);
            return ['ok' => true, 'result' => ['comments' => array_map(fn($comment): array => $this->commentData($comment), $comments), 'object_type' => $type, 'object_id' => $id]];
        } catch (\Throwable $e) { return ['ok' => false, 'error' => 'Comments could not be read.']; }
    }

    private function addComment(string $userId, array $args): array {
        $manager = $this->commentsManager();
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        $type = trim((string)($args['object_type'] ?? 'files')); $id = trim((string)($args['object_id'] ?? '')); $message = trim((string)($args['message'] ?? ''));
        if ($type === '' || $id === '' || $message === '') return ['ok' => false, 'error' => 'object_type, object_id and message are required'];
        try {
            $comment = $manager->create('users', $userId, $type, $id);
            $comment->setMessage(mb_substr($message, 0, \OCP\Comments\IComment::MAX_MESSAGE_LENGTH));
            if (trim((string)($args['parent_id'] ?? '')) !== '') $comment->setParentId(trim((string)$args['parent_id']));
            $saved = $manager->save($comment);
            return ['ok' => true, 'result' => $this->commentData($saved)];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'Comment could not be added. Check object access and comment length.']; }
    }

    private function deleteComment(array $args): array {
        $manager = $this->commentsManager(); $id = trim((string)($args['comment_id'] ?? ''));
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        if ($id === '') return ['ok' => false, 'error' => 'comment_id is required'];
        try { $manager->delete($id); return ['ok' => true, 'result' => ['comment_id' => $id, 'deleted' => true]]; }
        catch (\Throwable) { return ['ok' => false, 'error' => 'Comment could not be deleted. Check ownership and permissions.']; }
    }

    /** Read-only capability discovery for agent planning; never returns secrets. */
    private function listNextcloudCapabilities(): array {
        try {
            $manager = Server::get(\OCP\App\IAppManager::class);
            $apps = array_values(array_unique(array_map('strval', $manager->getEnabledApps())));
            sort($apps, SORT_STRING);
            $apiCatalog = [
                'files' => ['protocols' => ['WebDAV', 'OCS'], 'eva_tools' => ['list_files', 'read_file', 'search_files', 'create_file', 'rename_file', 'delete_file']],
                'calendar' => ['protocols' => ['CalDAV', 'OCS'], 'eva_tools' => ['list_calendars', 'list_calendar_events', 'find_free_slots', 'create_calendar_event', 'update_calendar_event', 'delete_calendar_event']],
                'contacts' => ['protocols' => ['CardDAV', 'OCS'], 'eva_tools' => ['list_contacts', 'find_contact', 'create_contact', 'update_contact', 'delete_contact']],
                'mail' => ['protocols' => ['IMAP/Nextcloud Mail service'], 'eva_tools' => ['search_mails', 'list_mails', 'read_mail', 'unread_mail_count']],
                'spreed' => ['protocols' => ['OCS Talk API'], 'eva_tools' => ['list_talk_rooms', 'read_talk_chat', 'send_talk_message']],
                'notes' => ['protocols' => ['Nextcloud Notes service/WebDAV'], 'eva_tools' => ['create_note', 'read_file', 'search_files']],
                'activity' => ['protocols' => ['OCS Activity API'], 'eva_tools' => ['recent_activity']],
                'files_sharing' => ['protocols' => ['OCS Sharing API'], 'eva_tools' => ['list_shares', 'create_share', 'update_share', 'delete_share']],
                'deck' => ['protocols' => ['Deck OCS API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'bookmarks' => ['protocols' => ['Bookmarks REST API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'forms' => ['protocols' => ['Forms OCS API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'comments' => ['protocols' => ['OCS Comments API', 'server-side ICommentsManager'], 'eva_tools' => ['list_comments', 'add_comment', 'delete_comment']],
                'systemtags' => ['protocols' => ['server-side ISystemTagManager/ISystemTagObjectMapper', 'OCS Files Tags API'], 'eva_tools' => ['list_system_tags', 'tag_file', 'untag_file']],
                'files_versions' => ['protocols' => ['server-side IVersionManager'], 'eva_tools' => ['list_file_versions', 'restore_file_version']],
                '_generic' => ['protocols' => ['Nextcloud route metadata, OCS and app-specific routes'], 'eva_tools' => ['discover_app_api', 'call_app_api'], 'status' => 'unknown app routes can be learned and invoked through the confirmation-gated generic adapter'],
            ];
            $availableApis = [];
            foreach ($apiCatalog as $app => $metadata) if (in_array($app, $apps, true)) $availableApis[$app] = $metadata;
            $availableApis['_generic'] = $apiCatalog['_generic'];
            return [
                'ok' => true,
                'enabled_apps' => $apps,
                'eva_integrations' => [
                    'files' => in_array('files', $apps, true),
                    'calendar' => in_array('calendar', $apps, true),
                    'contacts' => in_array('contacts', $apps, true),
                    'mail' => in_array('mail', $apps, true),
                    'spreed' => in_array('spreed', $apps, true),
                    'notes' => in_array('notes', $apps, true),
                ],
                'api_catalog' => $availableApis,
                'next_step' => 'Plan with the protocols and EVA tools listed above. Prefer a dedicated EVA adapter; for an enabled app without one, call list_learned_app_apis or discover_app_api first (include_internal=true when needed), then use the exact same-origin discovered route with call_app_api. Generic calls are always confirmation-gated interactively and require the encrypted app token in background runs.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Nextcloud capability discovery is unavailable.'];
        }
    }

    /**
     * Read route metadata from Nextcloud's router without invoking controllers.
     * This gives the agent a safe way to learn an installed app's API surface;
     * execution still goes through a same-origin app path and its normal
     * Nextcloud authentication/permission checks.
     */
    private function discoverAppApi(array $args): array {
        $appId = strtolower(trim((string)($args['app_id'] ?? '')));
        if ($appId !== '' && !preg_match('/^[a-z0-9_]+$/', $appId)) {
            return ['ok' => false, 'error' => 'app_id must contain only lowercase letters, numbers and underscores.'];
        }
        try {
            $appManager = Server::get(\OCP\App\IAppManager::class);
            $enabled = array_values(array_unique(array_map('strval', $appManager->getEnabledApps())));
            if ($appId !== '' && !in_array($appId, $enabled, true)) {
                return ['ok' => false, 'error' => 'That app is not enabled or is not available to this instance.'];
            }
            $router = Server::get(\OCP\Route\IRouter::class);
            if (method_exists($router, 'loadRoutes')) $router->loadRoutes($appId !== '' ? $appId : null);
            if (!method_exists($router, 'getRouteCollection')) {
                return ['ok' => false, 'error' => 'This Nextcloud version does not expose route discovery.'];
            }
            $collection = $router->getRouteCollection();
            $includeInternal = (bool)($args['include_internal'] ?? false);
            $routes = [];
            foreach ($collection->all() as $name => $route) {
                $defaults = $route->getDefaults();
                $controller = (string)($defaults['_controller'] ?? '');
                $routeApp = strtolower((string)($defaults['_app'] ?? ''));
                if ($routeApp === '' && is_string($name) && str_contains($name, '#')) {
                    $routeApp = strtolower((string)strtok($name, '#'));
                }
                if ($appId !== '' && $routeApp !== $appId && !str_starts_with(strtolower((string)$name), $appId . '.')) continue;
                $path = method_exists($route, 'getPath') ? (string)$route->getPath() : '';
                $isOcs = str_starts_with($path, '/ocs/') || str_starts_with($path, '/ocsapp/');
                if (!$includeInternal && !$isOcs) continue;
                $methods = method_exists($route, 'getMethods') ? array_values(array_map('strval', $route->getMethods())) : [];
                $variables = method_exists($route, 'getVariableNames') ? array_values(array_map('strval', $route->getVariableNames())) : [];
                $requirements = method_exists($route, 'getRequirements') ? array_map('strval', $route->getRequirements()) : [];
                $routes[] = [
                    'name' => (string)$name,
                    'app_id' => $routeApp,
                    'methods' => $methods,
                    'path' => $path,
                    'controller' => $controller,
                    'ocs' => $isOcs,
                    'variables' => $variables,
                    'requirements' => $requirements,
                ];
            }
            usort($routes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            if (count($routes) > 300) $routes = array_slice($routes, 0, 300);
            $this->rememberAppApi($appId, $routes);
            return ['ok' => true, 'result' => ['app_id' => $appId !== '' ? $appId : null, 'route_count' => count($routes), 'routes' => $routes, 'execution_policy' => 'Discovery never executes a route. Use the exact same-origin path with call_app_api; route variables and requirements describe the path placeholders. Non-OCS routes must be discovered with include_internal=true. Interactive calls require confirmation and background calls require the encrypted app token.']];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Nextcloud app API discovery is unavailable.'];
        }
    }

    private function rememberAppApi(string $appId, array $routes): void {
        if ($appId === '') return;
        try {
            $known = json_decode($this->config->get('learned_app_apis'), true);
            $known = is_array($known) ? $known : [];
            $sanitized = [];
            foreach (array_slice($routes, 0, 300) as $route) {
                if (!is_array($route)) continue;
                $sanitized[] = [
                    'name' => (string)($route['name'] ?? ''),
                    'methods' => array_values(array_map('strval', is_array($route['methods'] ?? null) ? $route['methods'] : [])),
                    'path' => (string)($route['path'] ?? ''),
                    'ocs' => (bool)($route['ocs'] ?? false),
                    'variables' => array_values(array_map('strval', is_array($route['variables'] ?? null) ? $route['variables'] : [])),
                    'requirements' => array_map('strval', is_array($route['requirements'] ?? null) ? $route['requirements'] : []),
                ];
            }
            $known[$appId] = ['updated' => time(), 'routes' => $sanitized];
            if (count($known) > 30) {
                uasort($known, static fn (array $a, array $b): int => ((int)($b['updated'] ?? 0)) <=> ((int)($a['updated'] ?? 0)));
                $known = array_slice($known, 0, 30, true);
            }
            $this->config->set('learned_app_apis', json_encode($known, JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable) { /* Learning is best effort. */ }
    }

    private function listLearnedAppApis(): array {
        try {
            $known = json_decode($this->config->get('learned_app_apis'), true);
            return ['ok' => true, 'result' => ['apps' => is_array($known) ? $known : [], 'note' => 'Route metadata is cached per user and may be stale; refresh with discover_app_api before acting.']];
        } catch (\Throwable) { return ['ok' => true, 'result' => ['apps' => []]]; }
    }

    private function callAppApi(array $args): array {
        $appId = strtolower(trim((string)($args['app_id'] ?? '')));
        $method = strtoupper(trim((string)($args['method'] ?? '')));
        $path = trim((string)($args['path'] ?? ''));
        $params = $args['params'] ?? [];
        if (!preg_match('/^[a-z0-9_]+$/', $appId) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => false, 'error' => 'A valid app_id and HTTP method are required.'];
        }
        if (!is_array($params) || count($params) > 50) return ['ok' => false, 'error' => 'params must be an object with at most 50 fields.'];
        $ocsPrefixes = ['/ocs/v1.php/apps/' . $appId . '/', '/ocs/v2.php/apps/' . $appId . '/'];
        $isOcsPath = false;
        foreach ($ocsPrefixes as $prefix) if (str_starts_with($path, $prefix)) $isOcsPath = true;
        if (str_contains($path, '..') || preg_match('/[\r\n]/', $path) || !str_starts_with($path, '/')) {
            return ['ok' => false, 'error' => 'Only same-origin app paths without traversal are allowed.'];
        }
        // Non-OCS routes are accepted only after the agent has explicitly
        // discovered and cached that app's route metadata. This permits
        // unknown apps to be learned safely without turning call_app_api into
        // an arbitrary internal HTTP proxy. OCS paths retain the historical
        // prefix check for backwards compatibility with existing clients.
        if (!$isOcsPath) {
            $knownRoute = false;
            try {
                $learned = json_decode($this->config->get('learned_app_apis'), true);
                $learnedAt = (int)($learned[$appId]['updated'] ?? 0);
                $routes = ($learnedAt > 0 && $learnedAt >= time() - self::LEARNED_API_TTL && is_array($learned[$appId]['routes'] ?? null)) ? $learned[$appId]['routes'] : [];
                foreach ($routes as $route) {
                    if (!is_array($route) || (bool)($route['ocs'] ?? false)) continue;
                    $routePath = (string)($route['path'] ?? '');
                    $methods = is_array($route['methods'] ?? null) ? array_map('strtoupper', $route['methods']) : [];
                    if ($routePath !== '' && $this->matchesDiscoveredRoute($routePath, $path) && ($methods === [] || in_array($method, $methods, true))) {
                        $knownRoute = true;
                        break;
                    }
                }
            } catch (\Throwable) { /* treat malformed learning cache as empty */ }
            if (!$knownRoute) {
                return ['ok' => false, 'error' => 'This non-OCS route has not been discovered yet. Call discover_app_api with include_internal=true first.'];
            }
        }
        try {
            $appManager = Server::get(\OCP\App\IAppManager::class);
            if (!in_array($appId, array_map('strval', $appManager->getEnabledApps()), true) || !$appManager->isEnabledForUser($appId)) {
                return ['ok' => false, 'error' => 'That app is not enabled for the current user.'];
            }
            $request = Server::get(\OCP\IRequest::class);
            $client = Server::get(\OCP\Http\Client\IClientService::class)->newClient();
            $url = Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL($path);
            $headers = ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'];
            foreach (['Authorization', 'Cookie'] as $header) {
                $value = trim((string)$request->getHeader($header));
                if ($value !== '') $headers[$header] = $value;
            }
            // Background workers have no browser cookie. A user may opt in to
            // an encrypted Nextcloud app-password; it is used only for this
            // same-origin request and is never exposed to the model.
            if (!isset($headers['Authorization']) && !isset($headers['Cookie'])) {
                try {
                    $token = Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->getNextcloudToken($this->config->userId() ?? '');
                    $headers['Authorization'] = 'Basic ' . base64_encode(($this->config->userId() ?? '') . ':' . $token);
                } catch (\Throwable) {
                    return ['ok' => false, 'error' => 'No browser session or encrypted Nextcloud app token is available for this API action.'];
                }
            }
            $options = ['headers' => $headers, 'timeout' => self::APP_API_TIMEOUT, 'allow_redirects' => ['max' => 0, 'protocols' => ['https', 'http']]];
            if ($method === 'GET') $options['query'] = $params;
            elseif ($params !== []) $options['body'] = $params;
            $response = match ($method) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
            };
            $body = $response->getBody();
            if (is_resource($body)) $body = stream_get_contents($body);
            $body = mb_substr((string)$body, 0, 50000);
            $decoded = json_decode($body, true);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if ($ok) $this->rememberAppApiPattern($appId, $method, $path, array_keys($params), is_array($decoded) ? $this->shapeOf($decoded) : ['type' => 'string']);
            return ['ok' => $ok, 'result' => ['status' => $status, 'data' => $decoded ?? $body, 'path' => $path, 'method' => $method]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'The app API request failed in the current user context.']; }
    }

    /** Remember only reusable call shape, never parameter values or response data. */
    private function rememberAppApiPattern(string $appId, string $method, string $path, array $paramKeys, array $responseShape = []): void {
        if ($appId === '' || $path === '') return;
        try {
            $known = json_decode($this->config->get('learned_app_apis'), true);
            if (!is_array($known) || !is_array($known[$appId] ?? null)) return;
            $patterns = is_array($known[$appId]['patterns'] ?? null) ? $known[$appId]['patterns'] : [];
            $keys = array_values(array_unique(array_filter(array_map('strval', $paramKeys), static fn(string $key): bool => preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key) === 1)));
            $entry = ['method' => $method, 'path' => $path, 'params' => $keys, 'response_shape' => $responseShape, 'last_used' => time()];
            $fingerprint = $method . ' ' . $path;
            $patterns = array_values(array_filter($patterns, static fn($row): bool => is_array($row) && (($row['method'] ?? '') . ' ' . ($row['path'] ?? '')) !== $fingerprint));
            array_unshift($patterns, $entry);
            $known[$appId]['patterns'] = array_slice($patterns, 0, 50);
            $this->config->set('learned_app_apis', json_encode($known, JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable) { /* Learning is best effort and must not break the action. */ }
    }

    /** Return only JSON shape metadata; never retain response values. */
    private function shapeOf(mixed $value, int $depth = 0): array {
        if ($depth >= 3) return ['type' => is_array($value) ? 'object' : gettype($value)];
        if (!is_array($value)) return ['type' => gettype($value)];
        $keys = [];
        foreach (array_slice($value, 0, 40, true) as $key => $child) {
            $name = (string)$key;
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) !== 1) continue;
            $keys[$name] = $this->shapeOf($child, $depth + 1);
        }
        return ['type' => array_is_list($value) ? 'array' : 'object', 'keys' => $keys];
    }

    /** Match a concrete request path against a Nextcloud route template. */
    private function matchesDiscoveredRoute(string $template, string $path): bool {
        $quoted = preg_quote(rtrim($template, '/'), '#');
        $quoted = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', $quoted) ?? $quoted;
        $pattern = '#^' . $quoted . '/?$#';
        return preg_match($pattern, $path) === 1;
    }

    private function briefingRows(): array {
        $rows = json_decode($this->config->get('proactive_schedules'), true);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function validBriefingFields(string $prompt, string $time, array $days): ?string {
        if ($prompt === '' || mb_strlen($prompt) > 2000) return 'prompt must contain 1-2000 characters.';
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) return 'time must use HH:MM format.';
        $days = array_values(array_unique(array_map('intval', $days)));
        if ($days === [] || count($days) > 7 || array_diff($days, [1, 2, 3, 4, 5, 6, 7]) !== []) return 'days must contain weekdays 1-7.';
        return null;
    }

    private function listScheduledBriefings(): array {
        $rows = $this->briefingRows();
        return ['ok' => true, 'result' => ['briefings' => array_map(static function (array $row): array {
            return ['id' => (string)($row['id'] ?? ''), 'prompt' => (string)($row['prompt'] ?? ''), 'time' => (string)($row['time'] ?? ''), 'days' => array_values(array_map('intval', is_array($row['days'] ?? null) ? $row['days'] : [])), 'enabled' => ($row['enabled'] ?? true) === true, 'allow_actions' => ($row['allow_actions'] ?? false) === true];
        }, $rows)]];
    }

    private function persistBriefings(array $rows): void {
        $this->config->set('proactive_schedules', json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function createScheduledBriefing(array $args): array {
        $prompt = trim((string)($args['prompt'] ?? ''));
        $time = trim((string)($args['time'] ?? ''));
        $days = is_array($args['days'] ?? null) ? array_values(array_unique(array_map('intval', $args['days']))) : [];
        $error = $this->validBriefingFields($prompt, $time, $days);
        if ($error !== null) return ['ok' => false, 'error' => $error];
        $rows = $this->briefingRows();
        if (count($rows) >= 20) return ['ok' => false, 'error' => 'At most 20 scheduled briefings are allowed.'];
        $id = 'briefing-' . bin2hex(random_bytes(5));
        $row = ['id' => $id, 'prompt' => $prompt, 'time' => $time, 'days' => $days, 'enabled' => true, 'allow_actions' => ($args['allow_actions'] ?? false) === true];
        $rows[] = $row; $this->persistBriefings($rows);
        return ['ok' => true, 'result' => ['briefing' => $row]];
    }

    private function updateScheduledBriefing(array $args): array {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($args['briefing_id'] ?? '')) ?? '';
        if ($id === '') return ['ok' => false, 'error' => 'briefing_id is required.'];
        $rows = $this->briefingRows(); $found = false; $updated = null;
        foreach ($rows as &$row) {
            if ((string)($row['id'] ?? '') !== $id) continue;
            $prompt = array_key_exists('prompt', $args) ? trim((string)$args['prompt']) : (string)($row['prompt'] ?? '');
            $time = array_key_exists('time', $args) ? trim((string)$args['time']) : (string)($row['time'] ?? '');
            $days = array_key_exists('days', $args) && is_array($args['days']) ? array_values(array_unique(array_map('intval', $args['days']))) : (array)$row['days'];
            $error = $this->validBriefingFields($prompt, $time, $days);
            if ($error !== null) return ['ok' => false, 'error' => $error];
            $row['prompt'] = $prompt; $row['time'] = $time; $row['days'] = $days;
            if (array_key_exists('enabled', $args)) $row['enabled'] = $args['enabled'] === true;
            if (array_key_exists('allow_actions', $args)) $row['allow_actions'] = $args['allow_actions'] === true;
            $updated = $row; $found = true; break;
        }
        unset($row);
        if (!$found) return ['ok' => false, 'error' => 'Scheduled briefing not found.'];
        $this->persistBriefings($rows);
        return ['ok' => true, 'result' => ['briefing' => $updated]];
    }

    private function deleteScheduledBriefing(array $args): array {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($args['briefing_id'] ?? '')) ?? '';
        if ($id === '') return ['ok' => false, 'error' => 'briefing_id is required.'];
        $rows = $this->briefingRows(); $filtered = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['id'] ?? '') !== $id));
        if (count($filtered) === count($rows)) return ['ok' => false, 'error' => 'Scheduled briefing not found.'];
        $this->persistBriefings($filtered);
        return ['ok' => true, 'result' => ['briefing_id' => $id, 'deleted' => true]];
    }

    /** @return array<array{name:string,path:string,type:string,size?:int}> */
    private function listFiles(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $folder = $this->folderAt($home, $path);
        $rootLen = strlen($folder->getPath()) + 1;
        $out = [];
        $count = 0;
        $this->walk($folder, $out, 0, $count, $rootLen);
        $this->rememberFileLocations($out);
        return ['ok' => true, 'result' => $out];
    }

    private function walk(Folder $folder, array &$out, int $depth, int &$count, int $rootLen): void {
        if ($depth >= self::MAX_LIST_DEPTH || $count >= self::MAX_LIST_ENTRIES) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($count >= self::MAX_LIST_ENTRIES) {
                return;
            }
            $count++;
            $rel = substr($node->getPath(), $rootLen);
            if ($node instanceof File) {
                $out[] = ['name' => $node->getName(), 'path' => $rel, 'type' => 'file', 'size' => $node->getSize()];
            } elseif ($node instanceof Folder) {
                $out[] = ['name' => $node->getName(), 'path' => $rel, 'type' => 'folder'];
                if ($depth + 1 < self::MAX_LIST_DEPTH) {
                    $this->walk($node, $out, $depth + 1, $count, $rootLen);
                }
            }
        }
    }

    /** @return array{ok:true,result:string} */
    private function createFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $content = (string)($args['content'] ?? '');
        if ($path === '' || str_ends_with($path, '/')) {
            return ['ok' => false, 'error' => 'A valid file path is required'];
        }
        if ($content === '') {
            return ['ok' => false, 'error' => 'File content must not be empty'];
        }
        $maxChars = (int)$this->config->get('exec_write_max_chars') ?: 100000;
        if (mb_strlen($content) > $maxChars) {
            return ['ok' => false, 'error' => 'File content exceeds ' . $maxChars . ' characters'];
        }
        if (strpos($content, "\0") !== false) {
            return ['ok' => false, 'error' => 'Only text files can be created'];
        }
        [, $name] = $this->splitPath($path);
        $typeError = $this->checkWriteType($name);
        if ($typeError !== null) {
            return ['ok' => false, 'error' => $typeError];
        }
        [$dir, $name] = $this->splitPath($path);
        $folder = $this->ensureFolderPath($home, $dir);
        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File) {
                $existing->putContent($content);
                return ['ok' => true, 'result' => 'Updated ' . $path];
            }
            return ['ok' => false, 'error' => 'A folder with that name already exists at ' . $path];
        }
        // The ownership marker is recorded BEFORE the file is created and
        // finalized with the resulting node afterwards, so an interruption
        // between the two leaves a pending record that reconciliation resolves
        // instead of a missing grant (Issue #183).
        $this->markOwnedPending($home, $path, function () use ($folder, $name, $content): void {
            $folder->newFile($name, $content);
        });
        return ['ok' => true, 'result' => 'Created ' . $path];
    }

    /** Create several files while preserving per-file validation/results. */
    private function createFiles(Folder $home, array $args): array {
        $files = $args['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 20) return ['ok' => false, 'error' => 'files must contain between 1 and 20 entries'];
        $results = []; $allOk = true;
        foreach ($files as $entry) {
            if (!is_array($entry)) { $results[] = ['ok' => false, 'error' => 'Each entry must contain path and content']; $allOk = false; continue; }
            $result = $this->createFile($home, ['path' => $entry['path'] ?? '', 'content' => $entry['content'] ?? '']);
            $results[] = $result; if (empty($result['ok'])) $allOk = false;
        }
        return ['ok' => $allOk, 'result' => ['files' => $results, 'created' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))), 'failed' => count(array_filter($results, static fn(array $r): bool => empty($r['ok'])))]];
    }

    /** Prüft die konfigurierte Dateityp-Einschränkung; liefert Fehlertext oder null. */
    private function checkWriteType(string $name): ?string {
        $allowed = strtolower(trim((string)$this->config->get('exec_write_types')));
        if ($allowed === '' || $allowed === '*') {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $list = array_map('trim', explode(',', $allowed));
        if (in_array($ext, $list, true)) {
            return null;
        }
        return 'File type .' . ($ext !== '' ? $ext : '?') . ' is not allowed (allowed: ' . $allowed . ')';
    }

    /** @return array{ok:true,result:string} */
    private function createNote(Folder $home, array $args): array {
        $title = trim((string)($args['title'] ?? ''));
        $content = (string)($args['content'] ?? '');
        if ($title === '') {
            return ['ok' => false, 'error' => 'A note title is required'];
        }
        if (!str_ends_with(strtolower($title), '.md')) {
            $title .= '.md';
        }
        $title = $this->cleanName($title);
        return $this->createFile($home, ['path' => self::NOTES_FOLDER . '/' . $title, 'content' => $content]);
    }

    /** @return array{ok:true,result:string} */
    private function createFolder(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'error' => 'Folder path required'];
        }
        $existed = $home->nodeExists($path);
        if (!$existed) {
            // Pending marker before the folder is created, finalize afterwards
            // (Issue #183) — same crash-safe semantics as file creation.
            $this->markOwnedPending($home, $path, function () use ($home, $path): void {
                $this->ensureFolderPath($home, $path);
            });
        } else {
            $this->ensureFolderPath($home, $path);
        }
        return ['ok' => true, 'result' => 'Created folder ' . $path];
    }

    /** @return array{ok:true,result:string} */
    private function renameFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $newName = trim((string)($args['new_name'] ?? ''));
        if ($path === '' || $newName === '' || str_contains($newName, '/') || $newName === '.' || $newName === '..') {
            return ['ok' => false, 'error' => 'Valid path and new_name are required'];
        }
        $node = $this->resolve($home, $path);
        $parent = $node->getParent();
        if ($parent->nodeExists($newName)) {
            return ['ok' => false, 'error' => 'Target name already exists'];
        }
        $node->move($parent->getPath() . '/' . $newName);
        return ['ok' => true, 'result' => 'Renamed to ' . $newName];
    }

    /** Move a file or folder to a new relative path, creating destination folders. */
    private function moveFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $targetPath = $this->cleanPath((string)($args['target_path'] ?? ''));
        if ($path === '' || $targetPath === '' || $path === $targetPath || $targetPath === '/') {
            return ['ok' => false, 'error' => 'Valid, different path and target_path are required'];
        }
        $node = $this->resolve($home, $path);
        if ($node instanceof Folder && str_starts_with($targetPath . '/', $path . '/')) {
            return ['ok' => false, 'error' => 'A folder cannot be moved into itself'];
        }
        [$targetDir, $targetName] = $this->splitPath($targetPath);
        $targetName = $this->cleanName($targetName);
        if ($targetName === '') {
            return ['ok' => false, 'error' => 'A valid target name is required'];
        }
        $destination = $this->ensureFolderPath($home, $targetDir);
        if ($destination->nodeExists($targetName)) {
            return ['ok' => false, 'error' => 'Target already exists'];
        }
        $node->move($destination->getPath() . '/' . $targetName);
        return ['ok' => true, 'result' => 'Moved ' . $path . ' to ' . $targetPath];
    }

    /** Copy a file or folder to a new relative path, creating destination folders. */
    private function copyFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $targetPath = $this->cleanPath((string)($args['target_path'] ?? ''));
        if ($path === '' || $targetPath === '' || $path === $targetPath || $targetPath === '/') {
            return ['ok' => false, 'error' => 'Valid, different path and target_path are required'];
        }
        $node = $this->resolve($home, $path);
        if ($node instanceof Folder && str_starts_with($targetPath . '/', $path . '/')) {
            return ['ok' => false, 'error' => 'A folder cannot be copied into itself'];
        }
        [$targetDir, $targetName] = $this->splitPath($targetPath);
        $targetName = $this->cleanName($targetName);
        if ($targetName === '') return ['ok' => false, 'error' => 'A valid target name is required'];
        $destination = $this->ensureFolderPath($home, $targetDir);
        if ($destination->nodeExists($targetName)) return ['ok' => false, 'error' => 'Target already exists'];
        $node->copy($destination->getPath() . '/' . $targetName);
        return ['ok' => true, 'result' => 'Copied ' . $path . ' to ' . $targetPath];
    }

    /** Return a bounded checksum for post-operation integrity validation. */
    private function fileChecksum(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) return ['ok' => false, 'error' => 'Not a file'];
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) return ['ok' => false, 'error' => 'File too large to checksum'];
        try {
            $content = (string)$node->getContent();
            return ['ok' => true, 'result' => ['path' => $path, 'algorithm' => 'sha256', 'checksum' => hash('sha256', $content), 'size' => (int)$node->getSize(), 'modified' => (int)$node->getMTime()]];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'File checksum could not be calculated'];
        }
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function deleteFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '' || $path === '/') {
            return ['ok' => false, 'error' => 'A valid path is required'];
        }
        $mode = (string)$this->config->get('exec_delete_mode');
        if ($mode === 'off') {
            return ['ok' => false, 'error' => 'Deleting files is disabled in the app settings.'];
        }
        $node = $this->resolve($home, $path);
        if ($mode !== 'all' && !$this->isOwned($home, $node)) {
            return ['ok' => false, 'error' => 'Only files EVA created itself may be deleted (adjust "delete permission" in the app settings to allow more).'];
        }
        if ($node instanceof Folder && $node->getDirectoryListing() !== []) {
            return ['ok' => false, 'error' => 'Folder is not empty'];
        }
        $fileId = (int)$node->getId();
        $node->delete();
        $this->unmarkOwned($home, $fileId);
        return ['ok' => true, 'result' => 'Deleted ' . ($node instanceof Folder ? 'folder ' : 'file ') . $path];
    }

    /** @return array{ok:true,result:array} */
    private function readFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'error' => 'File path required'];
        }
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) {
            return ['ok' => false, 'error' => 'Not a file'];
        }
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) {
            return ['ok' => false, 'error' => 'File too large to read'];
        }
        $content = (string)$node->getContent();
        if (strpos($content, "\0") !== false) {
            return ['ok' => false, 'error' => 'File is not text'];
        }
        $offset = filter_var($args['offset'] ?? 0, FILTER_VALIDATE_INT);
        $maxChars = filter_var($args['max_chars'] ?? self::MAX_READ_CHARS, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0) {
            return ['ok' => false, 'error' => 'offset must be a non-negative integer'];
        }
        if ($maxChars === false || $maxChars < 1 || $maxChars > self::MAX_READ_CHUNK_CHARS) {
            return ['ok' => false, 'error' => 'max_chars must be between 1 and ' . self::MAX_READ_CHUNK_CHARS];
        }
        $totalChars = mb_strlen($content);
        if ($offset > $totalChars) {
            return ['ok' => false, 'error' => 'offset is beyond the end of the file'];
        }
        $page = mb_substr($content, $offset, $maxChars);
        $nextOffset = $offset + mb_strlen($page);
        return ['ok' => true, 'result' => [
            'path' => $path,
            'content' => $page,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'total_chars' => $totalChars,
            'has_more' => $nextOffset < $totalChars,
        ]];
    }

    /** Read several files while preserving per-file pagination and errors. */
    private function readFiles(Folder $home, array $args): array {
        $files = $args['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 20) return ['ok' => false, 'error' => 'files must contain between 1 and 20 entries'];
        $results = []; $allOk = true;
        foreach ($files as $entry) {
            if (!is_array($entry) || trim((string)($entry['path'] ?? '')) === '') { $results[] = ['ok' => false, 'error' => 'Each entry must contain a path']; $allOk = false; continue; }
            $result = $this->readFile($home, $entry); $results[] = $result; if (empty($result['ok'])) $allOk = false;
        }
        return ['ok' => $allOk, 'result' => ['files' => $results, 'read' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))), 'failed' => count(array_filter($results, static fn(array $r): bool => empty($r['ok'])))]];
    }

    /** Extract indexed text from binary/Office formats in bounded pages. */
    private function extractFileText(Folder $home, array $args): array {
        if ($this->indexer === null) return ['ok' => false, 'error' => 'Document extraction is unavailable'];
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        if (!$node instanceof File) return ['ok' => false, 'error' => 'Not a file'];
        if ($node->getSize() > self::MAX_READ_FILE_BYTES) return ['ok' => false, 'error' => 'File too large to extract'];
        $maxChars = filter_var($args['max_chars'] ?? self::MAX_READ_CHARS, FILTER_VALIDATE_INT);
        $offset = filter_var($args['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($maxChars === false || $maxChars < 1 || $maxChars > self::MAX_READ_CHUNK_CHARS || $offset === false || $offset < 0) {
            return ['ok' => false, 'error' => 'offset/max_chars are outside the allowed range'];
        }
        try { $content = $this->indexer->extractTextForAgent($node, 100000); }
        catch (\Throwable $e) { return ['ok' => false, 'error' => 'Document extraction failed: ' . $e->getMessage()]; }
        $total = mb_strlen($content);
        if ($offset > $total) return ['ok' => false, 'error' => 'offset is beyond extracted text'];
        $page = mb_substr($content, $offset, $maxChars); $next = $offset + mb_strlen($page);
        return ['ok' => true, 'result' => ['path' => $path, 'content' => $page, 'offset' => $offset, 'next_offset' => $next, 'total_chars' => $total, 'has_more' => $next < $total, 'mime_type' => (string)$node->getMimeType()]];
    }

    private function inspectFile(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        if ($path === '') return ['ok' => false, 'error' => 'File path required'];
        $node = $this->resolve($home, $path);
        return ['ok' => true, 'result' => [
            'path' => $path,
            'type' => $node instanceof Folder ? 'folder' : 'file',
            'size' => $node instanceof File ? (int)$node->getSize() : null,
            'mime_type' => $node instanceof File ? (string)$node->getMimeType() : null,
            'modified' => (int)$node->getMTime(),
        ]];
    }

    /** @return array{ok:true,result:array} */
    private function searchFiles(Folder $home, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Search query required'];
        }
        $matches = [];
        $visited = 0;
        $truncated = false;
        $this->searchWalk($home, mb_strtolower($query), $matches, $visited, $truncated, 0, '');
        $this->rememberFileLocations($matches);
        return ['ok' => true, 'result' => [
            'query' => $query,
            'matches' => $matches,
            'truncated' => $truncated,
            'limits' => [
                'max_results' => self::MAX_SEARCH_RESULTS,
                'max_nodes' => self::MAX_SEARCH_NODES,
                'max_depth' => self::MAX_SEARCH_DEPTH,
                'max_text_file_bytes' => self::MAX_SEARCH_FILE_BYTES,
            ],
        ]];
    }

    /** Store only paths/types and timestamps; never file contents. */
    private function rememberFileLocations(array $rows): void {
        try {
            $known = json_decode($this->config->get('learned_file_locations'), true);
            $known = is_array($known) ? $known : [];
            $now = time();
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $path = trim((string)($row['path'] ?? ''));
                if ($path === '' || mb_strlen($path) > 1000 || str_contains($path, '..')) continue;
                $known[$path] = ['type' => (string)($row['type'] ?? 'file'), 'last_seen' => $now];
            }
            uasort($known, static fn(array $a, array $b): int => ((int)($b['last_seen'] ?? 0)) <=> ((int)($a['last_seen'] ?? 0)));
            $this->config->set('learned_file_locations', json_encode(array_slice($known, 0, 500, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable) { /* learning is best effort */ }
    }

    private function listLearnedFileLocations(): array {
        try {
            $known = json_decode($this->config->get('learned_file_locations'), true);
            return ['ok' => true, 'result' => ['locations' => is_array($known) ? array_slice($known, 0, 200, true) : [], 'note' => 'Only paths and types are stored; refresh with list_files or search_files when a location may have changed.']];
        } catch (\Throwable) { return ['ok' => true, 'result' => ['locations' => []]]; }
    }

    /**
     * Search names and bounded text content while protecting the request from
     * an unbounded VFS walk or unexpectedly large/binary files.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    private function searchWalk(Folder $folder, string $query, array &$matches, int &$visited, bool &$truncated, int $depth, string $prefix): void {
        if ($depth >= self::MAX_SEARCH_DEPTH || count($matches) >= self::MAX_SEARCH_RESULTS) {
            $truncated = true;
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if (count($matches) >= self::MAX_SEARCH_RESULTS || $visited >= self::MAX_SEARCH_NODES) {
                $truncated = true;
                return;
            }
            $visited++;
            $rel = $prefix === '' ? $node->getName() : $prefix . '/' . $node->getName();
            $nameMatches = str_contains(mb_strtolower($node->getName()), $query);
            if ($node instanceof Folder) {
                if ($nameMatches) {
                    $matches[] = ['path' => $rel, 'reason' => 'filename'];
                }
                $this->searchWalk($node, $query, $matches, $visited, $truncated, $depth + 1, $rel);
                continue;
            }

            $contentMatch = null;
            if ($node instanceof File && $this->isSearchableTextFile($node)) {
                try {
                    $content = (string)$node->getContent();
                    if (strpos($content, "\0") === false) {
                        $position = mb_stripos($content, $query);
                        if ($position !== false) {
                            $contentMatch = $this->searchSnippet($content, $position, mb_strlen($query));
                        }
                    }
                } catch (\Throwable $e) {
                    // A single unreadable file must not abort the complete search.
                }
            }

            if ($nameMatches && $contentMatch !== null) {
                $matches[] = ['path' => $rel, 'reason' => 'filename and content', 'snippet' => $contentMatch];
            } elseif ($nameMatches) {
                $matches[] = ['path' => $rel, 'reason' => 'filename'];
            } elseif ($contentMatch !== null) {
                $matches[] = ['path' => $rel, 'reason' => 'content', 'snippet' => $contentMatch];
            }
        }
    }

    private function isSearchableTextFile(File $file): bool {
        if ($file->getSize() > self::MAX_SEARCH_FILE_BYTES) {
            return false;
        }
        $mime = strtolower((string)$file->getMimeType());
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        return in_array($mime, [
            'application/json', 'application/ld+json', 'application/xml',
            'application/x-yaml', 'application/yaml', 'application/rtf',
            'application/sql', 'application/x-sh', 'application/x-httpd-php',
        ], true);
    }

    private function searchSnippet(string $content, int $position, int $queryLength): string {
        $start = max(0, $position - 120);
        $snippet = mb_substr($content, $start, $queryLength + 240);
        $snippet = preg_replace('/\s+/u', ' ', trim($snippet)) ?? trim($snippet);
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($start + mb_strlen($snippet) < mb_strlen($content)) {
            $snippet .= '…';
        }
        return mb_substr($snippet, 0, 300);
    }

    /** @return array{ok:true,result:array} */
    /** @return array{ok:true,result:array{contacts:list<array>,count:int}} */
    private function listContacts(string $userId): array {
        $out = [];
        $seen = [];
        $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
        foreach ($this->allAddressBookPrincipals($userId) as $principal) {
            // Die System-/System-Adressbuecher enthalten nur Selbst-Kontakte
            // aller Nutzer und gehoeren nicht zu den Kontakten des Users.
            if ($principal === 'principals/system/system') {
                continue;
            }
            foreach ($backend->getAddressBooksForUser($principal) as $book) {
                foreach ($backend->getCards((int)$book['id']) as $card) {
                    $entry = $this->extractContact((string)($card['carddata'] ?? ''));
                    if ($entry === null) {
                        continue;
                    }
                    $dedup = strtolower(($entry['name'] ?? '') . '|' . implode(',', $entry['emails'] ?? []));
                    if ($dedup !== '' && isset($seen[$dedup])) {
                        continue;
                    }
                    $seen[$dedup] = true;
                    $out[] = $entry;
                    if (count($out) >= 100) {
                        break 3;
                    }
                }
            }
        }
        return ['ok' => true, 'result' => ['contacts' => $out, 'count' => count($out)]];
    }

    private function findContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            // Ohne Suchbegriff: alle Kontakte auflisten (Frage "Welche Kontakte habe ich?").
            return $this->listContacts($userId);
        }
        $results = $this->contacts->search($query, ['FN', 'NICKNAME', 'EMAIL', 'ORG']);
        $out = [];
        foreach (array_slice($results, 0, 8) as $c) {
            $out[] = [
                'name' => $c['FN'] ?? $c['NICKNAME'] ?? '',
                'emails' => array_values(array_map('strval', (array)($c['EMAIL'] ?? []))),
                'phones' => array_values(array_map('strval', (array)($c['TEL'] ?? []))),
                'org' => $c['ORG'] ?? '',
            ];
        }
        if ($out === []) {
            $found = $this->findContactCard($userId, $query);
            if ($found !== null) {
                $vc = \Sabre\VObject\Reader::read($found['carddata']);
                $entry = [];
                foreach (['FN' => 'name', 'EMAIL' => 'emails', 'TEL' => 'phones', 'ORG' => 'org'] as $propName => $key) {
                    $vals = [];
                    foreach ($vc->select($propName) as $prop) {
                        $val = trim((string)$prop);
                        if ($val !== '') {
                            $vals[] = $val;
                        }
                    }
                    $entry[$key] = ($propName === 'FN') ? (string)($vals[0] ?? '') : $vals;
                }
                $out[] = $entry;
            }
        }
        return ['ok' => true, 'result' => ['query' => $query, 'contacts' => $out]];

    }

    /**
     * Extrahiert Name/E-Mail/Telefon/Organisation aus einer vCard.
     * Liefert null bei leerer oder unparsbarer Karte.
     */
    private function extractContact(string $carddata): ?array {
        if ($carddata === '') {
            return null;
        }
        try {
            $v = \Sabre\VObject\Reader::read($carddata);
        } catch (\Throwable $e) {
            return null;
        }
        $entry = [];
        foreach (['FN' => 'name', 'EMAIL' => 'emails', 'TEL' => 'phones', 'ORG' => 'org'] as $propName => $key) {
            $vals = [];
            foreach ($v->select($propName) as $prop) {
                $val = trim((string)$prop);
                if ($val !== '') {
                    $vals[] = $val;
                }
            }
            $entry[$key] = ($propName === 'FN') ? (string)($vals[0] ?? '') : $vals;
        }
        if (($entry['name'] ?? '') === '' && ($entry['emails'] ?? []) === [] && ($entry['phones'] ?? []) === []) {
            return null;
        }
        return $entry;
    }

    /** @return array{ok:true,result:string} */
    private function createContact(string $userId, array $args): array {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Contact name required'];
        }
        $email = trim((string)($args['email'] ?? ''));
        $phone = trim((string)($args['phone'] ?? ''));
        $org = trim((string)($args['org'] ?? ''));

        $uid = 'ai-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . $uid . "\r\nFN:" . $name . "\r\nN:" . $name . ";;;;\r\n";
        if ($email !== '') {
            $vcard .= "EMAIL;TYPE=HOME:" . $email . "\r\n";
        }
        if ($phone !== '') {
            $vcard .= "TEL;TYPE=CELL:" . $phone . "\r\n";
        }
        if ($org !== '') {
            $vcard .= "ORG:" . $org . "\r\n";
        }
        $vcard .= "END:VCARD\r\n";

        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $books = $backend->getAddressBooksForUser('principals/users/' . $userId);
            if ($books === []) {
                return ['ok' => false, 'error' => 'No address book found for user'];
            }
            $backend->createCard((int)$books[0]['id'], $uid . '.vcf', $vcard);
            return ['ok' => true, 'result' => 'Created contact "' . $name . '"'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Address book write failed: ' . $e->getMessage()];
        }
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function updateKnowledge(Folder $home, array $args): array {
        $fact = trim((string)($args['fact'] ?? ''));
        if ($fact === '') {
            return ['ok' => false, 'error' => 'A fact is required'];
        }
        if (mb_strlen($fact) > 500) {
            return ['ok' => false, 'error' => 'Fact too long (max 500 characters)'];
        }
        $path = 'KNOWLEDGE.md';
        $line = '- ' . date('Y-m-d') . ': ' . $fact;
        $content = '';
        if ($home->nodeExists($path) && $home->get($path) instanceof File) {
            $content = (string)$home->get($path)->getContent();
        } elseif ($home->nodeExists($path)) {
            return ['ok' => false, 'error' => 'KNOWLEDGE.md exists but is not a file'];
        }
        $content = rtrim($content) . "
" . $line . "
";
        $trimmed = false;
        if (mb_strlen($content) > self::KNOWLEDGE_MAX_CHARS) {
            [$content, $trimmed] = $this->trimKnowledge($content);
        }
        if ($home->nodeExists($path)) {
            $home->get($path)->putContent($content);
        } else {
            $home->newFile($path, $content);
        }
        if ($trimmed) {
            try {
                \OC::$server->get(\Psr\Log\LoggerInterface::class)->warning('eva_ai: knowledge file trimmed; automatic profile section preserved', [
                    'file' => $path,
                ]);
            } catch (\Throwable $e) {
                // Logging must not make a successful knowledge update fail.
            }
        }
        $result = 'Knowledge updated: ' . $line;
        if ($trimmed) {
            $result .= ' Older non-profile entries were trimmed to keep KNOWLEDGE.md bounded; the automatic profile section was preserved.';
        }
        return ['ok' => true, 'result' => $result];
    }

    /**
     * Remove the oldest non-profile lines while retaining the automatic
     * identity block. The block is recognized by its marker (or heading for
     * files created before the marker was introduced).
     *
     * @return array{0:string,1:bool}
     */
    private function trimKnowledge(string $content): array {
        $lines = explode("\n", $content);
        $protected = array_fill(0, count($lines), false);
        $inProfile = false;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === self::KNOWLEDGE_PROFILE_MARKER || $trimmed === '## About me (from my Nextcloud profile)') {
                $inProfile = true;
            }
            if ($inProfile) {
                $protected[$i] = true;
            }
            if ($inProfile && str_starts_with($trimmed, '- Imported automatically on ')) {
                $inProfile = false;
            }
        }

        $removed = array_fill(0, count($lines), false);
        $length = mb_strlen($content);
        foreach ($lines as $i => $line) {
            if ($length <= self::KNOWLEDGE_TARGET_CHARS) {
                break;
            }
            if ($protected[$i]) {
                continue;
            }
            $removed[$i] = true;
            $length -= mb_strlen($line) + ($i < count($lines) - 1 ? 1 : 0);
        }

        $kept = [];
        foreach ($lines as $i => $line) {
            if (!$removed[$i]) {
                $kept[] = $line;
            }
        }
        $updated = implode("\n", $kept);
        return [$updated, $updated !== $content];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    private function readProfile(string $userId): array {
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return ['ok' => false, 'error' => 'User not found'];
        }
        try {
            $account = $this->accounts->getAccount($userObj);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile unavailable: ' . $e->getMessage()];
        }
        $fields = [
            'display_name' => IAccountManager::PROPERTY_DISPLAYNAME,
            'email' => IAccountManager::PROPERTY_EMAIL,
            'phone' => IAccountManager::PROPERTY_PHONE,
            'website' => IAccountManager::PROPERTY_WEBSITE,
            'address' => IAccountManager::PROPERTY_ADDRESS,
            'organisation' => IAccountManager::PROPERTY_ORGANISATION,
            'role' => IAccountManager::PROPERTY_ROLE,
            'headline' => IAccountManager::PROPERTY_HEADLINE,
            'biography' => IAccountManager::PROPERTY_BIOGRAPHY,
            'pronouns' => IAccountManager::PROPERTY_PRONOUNS,
        ];
        $profile = [];
        foreach ($fields as $label => $prop) {
            $value = $account->getProperty($prop)->getValue();
            if ($value !== '' && $value !== null) {
                $profile[$label] = $value;
            }
        }
        return ['ok' => true, 'result' => ['profile' => $profile]];
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function updateProfile(string $userId, array $args): array {
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return ['ok' => false, 'error' => 'User not found'];
        }
        try {
            $account = $this->accounts->getAccount($userObj);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile unavailable: ' . $e->getMessage()];
        }
        $map = [
            'display_name' => [IAccountManager::PROPERTY_DISPLAYNAME, IAccountManager::VERIFIED],
            'email' => [IAccountManager::PROPERTY_EMAIL, IAccountManager::NOT_VERIFIED],
            'phone' => [IAccountManager::PROPERTY_PHONE, IAccountManager::NOT_VERIFIED],
            'website' => [IAccountManager::PROPERTY_WEBSITE, IAccountManager::NOT_VERIFIED],
            'address' => [IAccountManager::PROPERTY_ADDRESS, IAccountManager::NOT_VERIFIED],
            'organisation' => [IAccountManager::PROPERTY_ORGANISATION, IAccountManager::NOT_VERIFIED],
            'role' => [IAccountManager::PROPERTY_ROLE, IAccountManager::NOT_VERIFIED],
            'headline' => [IAccountManager::PROPERTY_HEADLINE, IAccountManager::NOT_VERIFIED],
            'biography' => [IAccountManager::PROPERTY_BIOGRAPHY, IAccountManager::NOT_VERIFIED],
            'pronouns' => [IAccountManager::PROPERTY_PRONOUNS, IAccountManager::NOT_VERIFIED],
        ];
        $changed = [];
        foreach ($map as $key => [$prop, $verified]) {
            if (!array_key_exists($key, $args) || !is_string($args[$key])) {
                continue;
            }
            $account->setProperty($prop, trim($args[$key]), IAccountManager::SCOPE_LOCAL, $verified);
            $changed[] = $key;
        }
        if ($changed === []) {
            return ['ok' => false, 'error' => 'No profile fields to update'];
        }
        try {
            $this->accounts->updateAccount($account);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Updated profile fields: ' . implode(', ', $changed)];
    }

    /** @return array{bookId:int,uri:string,carddata:string,principaluri:string}|null */
    private function findContactCard(string $userId, string $query): ?array {
        $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
        foreach ($this->allAddressBookPrincipals($userId) as $principal) {
            foreach ($backend->getAddressBooksForUser($principal) as $book) {
            foreach ($backend->getCards((int)$book['id']) as $card) {
                $carddata = (string)($card['carddata'] ?? '');
                if ($carddata === '') {
                    continue;
                }
                try {
                    $v = \Sabre\VObject\Reader::read($carddata);
                } catch (\Throwable $e) {
                    continue;
                }
                $hayParts = [];
                foreach (['FN', 'EMAIL', 'TEL', 'ORG'] as $propName) {
                    foreach ($v->select($propName) as $prop) {
                        $val = trim((string)$prop);
                        if ($val !== '') {
                            $hayParts[] = $val;
                        }
                    }
                }
                $hay = strtolower(implode(' ', $hayParts));
                if (str_contains($hay, strtolower($query))) {
                    return [
                        'bookId' => (int)$book['id'],
                        'uri' => (string)($card['uri'] ?? ''),
                        'carddata' => $carddata,
                        'principaluri' => (string)($book['principaluri'] ?? ''),
                    ];
                }
            }
            }
        }
        return null;
    }

    /**
     * Whether the current user may mutate a given address book.
     * Only the user's own personal address books are writable; shared,
     * group/circle and system books must never be modified through the raw
     * DAV backend without an explicit write grant (Issue #11).
     * @param array{bookId:int,principaluri?:string} $found
     */
    private function addressBookWritable(string $userId, array $found): bool {
        $principal = (string)($found['principaluri'] ?? '');
        if ($principal === 'principals/users/' . $userId) {
            return true;
        }
        // Shared books are only writable with an explicit write grant.
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            foreach ($backend->getShares((int)$found['bookId']) as $share) {
                $href = (string)($share['href'] ?? '');
                if ($href === 'principal:principals/users/' . $userId) {
                    return empty($share['readOnly']);
                }
            }
        } catch (\Throwable $e) {
            // fall through: unknown -> not writable
        }
        return false;
    }

    /**
     * Alle Adressbuch-Prinzipalen, auf die der Nutzer Zugriff hat:
     * eigene, geteilte (ueber Shares im eigenen Principal), Gruppen-Adressbuecher,
     * Circles/Teams sowie das System-Adressbuch.
     * @return list<string>
     */
    private function allAddressBookPrincipals(string $userId): array {
        $principals = ['principals/users/' . $userId];
        $user = Server::get(\OCP\IUserManager::class)->get($userId);
        if ($user !== null) {
            foreach (Server::get(\OCP\IGroupManager::class)->getUserGroupIds($user) as $gid) {
                $principals[] = 'principals/groups/' . $gid;
            }
        }
        if (Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('circles')) {
            $principals[] = 'principals/circles/' . $userId;
        }
        $principals[] = 'principals/system/system';
        return array_values(array_unique($principals));
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function updateContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Contact query required'];
        }
        $found = $this->findContactCard($userId, $query);
        if ($found === null) {
            return ['ok' => false, 'error' => 'Contact not found'];
        }
        if (!$this->addressBookWritable($userId, $found)) {
            return ['ok' => false, 'error' => 'Contact lives in a read-only address book (shared or system). Only your own address books can be modified.'];
        }
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $vc = \Sabre\VObject\Reader::read($found['carddata']);
            foreach (['FN' => 'name', 'EMAIL' => 'email', 'TEL' => 'phone', 'ORG' => 'org'] as $prop => $key) {
                if (!array_key_exists($key, $args) || !is_string($args[$key])) {
                    continue;
                }
                $value = trim($args[$key]);
                $vc->remove($prop);
                if ($value !== '') {
                    $vc->add($prop, $value);
                }
            }
            $backend->updateCard($found['bookId'], $found['uri'], $vc->serialize());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Contact update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Updated contact "' . $query . '"'];
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    private function deleteContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Contact query required'];
        }
        $found = $this->findContactCard($userId, $query);
        if ($found === null) {
            return ['ok' => false, 'error' => 'Contact not found'];
        }
        if (!$this->addressBookWritable($userId, $found)) {
            return ['ok' => false, 'error' => 'Contact lives in a read-only address book (shared or system). Only your own address books can be modified.'];
        }
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $backend->deleteCard($found['bookId'], $found['uri']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Contact delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted contact'];
    }

    // ---- Marker für "von der KI erstellt" ----

    private function marksFile(string $userId): \OCP\Files\SimpleFS\ISimpleFile {
        // IAppDataFactory wird lazy geholt statt per Konstruktor injiziert:
        // die Aufloesung blockiert im CLI/taskprocessing-Worker.
        $appdata = \OC::$server->get(\OCP\AppFramework\Services\IAppDataFactory::class)->get('eva_ai');
        try {
            $dir = $appdata->getFolder('ai-marks');
        } catch (\OCP\Files\NotFoundException $e) {
            $dir = $appdata->newFolder('ai-marks');
        }
        // Collision-free per-user namespace (SHA-256 of the exact user ID).
        // Legacy lossy-slug folders are migrated lazily so existing markers
        // are preserved (Issue #8).
        $ns = substr(hash('sha256', $userId), 0, 40);
        try {
            $uid = $dir->getFolder($ns);
        } catch (\OCP\Files\NotFoundException $e) {
            $legacy = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
            try {
                $legacyFolder = $dir->getFolder($legacy);
                $uid = $dir->newFolder($ns);
                if ($legacyFolder->fileExists('created.json')) {
                    $uid->newFile('created.json', $legacyFolder->getFile('created.json')->getContent());
                }
                $legacyFolder->delete();
            } catch (\OCP\Files\NotFoundException $e2) {
                $uid = $dir->newFolder($ns);
            }
        }
        if (!$uid->fileExists('created.json')) {
            $uid->newFile('created.json', '[]');
        }
        return $uid->getFile('created.json');
    }

    private function userNameOf(Folder $home): string {
        $owner = $home->getOwner();
        if ($owner !== null && $owner->getUID() !== '') {
            return $owner->getUID();
        }
        $parts = explode('/', trim($home->getPath(), '/'));
        return $parts[0] ?? '';
    }

    private function ownershipStore(Folder $home): FileOwnershipStore {
        $userId = $this->userNameOf($home);
        if ($userId === '') {
            throw new \RuntimeException('No ownership namespace');
        }
        return new FileOwnershipStore($this->marksFile($userId), $home, $this->lockingProvider, $userId);
    }

    /**
     * Run a filesystem-creating action between a pending ownership marker and
     * its finalize (Issue #183):
     * - beginPending runs before the action, so a crash leaves a truthful
     *   pending record instead of a missing grant;
     * - a failed action cancels the record;
     * - a finalize failure keeps the pending record, which reconciliation
     *   resolves on the next ownership check.
     */
    private function markOwnedPending(Folder $home, string $path, callable $action): void {
        $store = $this->ownershipStore($home);
        $token = $store->beginPending($path);
        try {
            $action();
            try {
                $store->finalizePending($token, $home->get($path));
            } catch (\Throwable $e) {
                // The file exists and the pending record survives; the next
                // ownership check reconciles it into a grant.
            }
        } catch (\Throwable $e) {
            try {
                $store->cancelPending($token);
            } catch (\Throwable $ignored) {
                // Never mask the original action failure.
            }
            throw $e;
        }
    }

    private function unmarkOwned(Folder $home, int $fileId): void {
        try {
            $this->ownershipStore($home)->forget($fileId);
        } catch (\Throwable $e) {
            // Stale IDs never authorize a replacement file and are pruned on next access.
        }
    }

    private function isOwned(Folder $home, \OCP\Files\Node $node): bool {
        try {
            return $this->ownershipStore($home)->contains($node);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- Helfer ----

    private function cleanName(string $name): string {
        $name = str_replace(['/', '\\', '..', "\0"], '-', $name);
        return trim($name, " \t.-");
    }

    /** Entfernt .., führende Slashes und leere Segmente; darf nicht aus dem Home raus. */
    private function cleanPath(string $path): string {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /** @return array{0:string,1:string} */
    private function splitPath(string $path): array {
        $pos = strrpos($path, '/');
        if ($pos === false) {
            return ['', $path];
        }
        return [substr($path, 0, $pos), substr($path, $pos + 1)];
    }

    private function folderAt(Folder $home, string $path): Folder {
        if ($path === '') {
            return $home;
        }
        $node = $home->get($path);
        if (!$node instanceof Folder) {
            throw new NotPermittedException('Not a folder');
        }
        return $node;
    }

    private function ensureFolderPath(Folder $home, string $path): Folder {
        if ($path === '') {
            return $home;
        }
        $current = $home;
        foreach (explode('/', $path) as $seg) {
            if ($seg === '') {
                continue;
            }
            if (!$current->nodeExists($seg)) {
                $current->newFolder($seg);
            }
            $node = $current->get($seg);
            if (!$node instanceof Folder) {
                throw new NotPermittedException('Path component is not a folder: ' . $seg);
            }
            $current = $node;
        }
        return $current;
    }

    private function resolve(Folder $home, string $path): \OCP\Files\Node {
        if ($path === '') {
            return $home;
        }
        return $home->get($path);
    }

    private function currentTime(string $userId): array {
        $tz = 'Europe/Berlin';
        try {
            $tz = \OCP\Server::get(\OCP\IConfig::class)->getUserValue($userId, 'core', 'timezone', 'Europe/Berlin');
        } catch (\Throwable $e) {
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        $names = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return [
            'ok' => true,
            'result' => [
                'datetime' => $now->format('Y-m-d H:i:s'),
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i'),
                'weekday' => $names[(int)$now->format('w')],
                'iso8601' => $now->format('c'),
                'timezone' => $tz,
                'unix' => $now->getTimestamp(),
            ],
        ];
    }

    private function serverStatus(string $userId): array {
        $version = implode('.', \OCP\Util::getVersion());
        $quota = null;
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            $quota = ['free_bytes' => $home->getFreeSpace(), 'used_bytes' => (int)$home->getSize()];
        } catch (\Throwable $e) {
        }
        $dbName = '';
        try {
            $dbName = \OC::$server->get(\OC\SystemConfig::class)->getValue('dbtype', 'sqlite');
        } catch (\Throwable $e) {
        }
        return ['ok' => true, 'result' => [
            'user' => $userId,
            'nextcloud' => $version,
            'php' => PHP_VERSION,
            'database' => $dbName,
            'ollama_url' => $this->config->get('ollama_url'),
            'chat_model' => $this->config->get('chat_model'),
            'embedding_model' => $this->config->get('embedding_model'),
            'quota' => $quota,
            'mail_index_enabled' => $this->config->get('mail_index_enabled') === '1',
        ]];
    }

    private function runSafeCommand(array $args): array {
        if ($this->config->get('safe_commands_enabled') !== '1') return ['ok' => false, 'error' => 'Safe local commands are disabled in EVA settings.'];
        $name = trim((string)($args['command'] ?? ''));
        $commands = [
            'date' => ['date'], 'uptime' => ['uptime'], 'php_version' => ['php', '-v'],
            'node_version' => ['node', '--version'], 'disk_free' => ['df', '-h'],
            'memory_free' => ['free', '-h'], 'eva_git_status' => ['git', '-C', __DIR__ . '/../../', 'status', '--short'],
        ];
        if (!isset($commands[$name])) return ['ok' => false, 'error' => 'Command is not on the safe diagnostic allowlist.'];
        $pipes = [];
        $process = proc_open($commands[$name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../../');
        if (!is_resource($process)) return ['ok' => false, 'error' => 'Could not start the diagnostic command.'];
        stream_set_timeout($pipes[1], 5); stream_set_timeout($pipes[2], 5);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        return ['ok' => $exit === 0, 'result' => ['command' => $name, 'output' => mb_substr(trim((string)$stdout), 0, 10000), 'error_output' => mb_substr(trim((string)$stderr), 0, 2000), 'exit_code' => $exit]];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    private function searchMails(string $userId, array $args): array {
        try {
            $res = $this->email->search($userId, (string)($args['query'] ?? ''), max(1, (int)($args['limit'] ?? 10)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['mails' => $res]];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    private function listMails(string $userId, array $args): array {
        try {
            $res = $this->email->listMessages($userId, max(1, (int)($args['limit'] ?? 15)), !empty($args['unread_only']));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['mails' => $res]];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    private function readMail(string $userId, array $args): array {
        try {
            return $this->email->readMessage($userId, (int)($args['message_id'] ?? 0));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
        }
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    private function unreadMailCount(string $userId): array {
        try {
            $n = $this->email->unreadCount($userId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['unread' => $n]];
    }

    /**
     * Pictures for the answer, not pages about them.
     *
     * A text search returns pages, so a model asked to "show pictures of X"
     * used to answer that it cannot display images. This returns pictures the
     * model can embed, each with a caption and the page it came from, and the
     * source list below the answer shows where they came from.
     */
    private function runImageSearch(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required'];
        }
        $count = isset($args['count']) ? (int)$args['count'] : null;
        $result = $this->webSearch->searchImages($query, $count);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The image search failed.')];
        }
        return [
            'ok' => true,
            'result' => [
                'query' => $query,
                'provider' => $result['provider'],
                // `external: true` marks these as links outside the Nextcloud
                // instance so callers never confuse them with indexed files.
                'external' => true,
                'images' => array_map(static fn(array $image): array => [
                    'url' => $image['url'],
                    'preview' => $image['preview'],
                    'title' => $image['title'],
                    'page' => $image['page'],
                ], $result['images']),
                'markdown' => implode("\n", array_map(static fn(array $image): string =>
                    '![' . str_replace([']', '['], '', (string)$image['title']) . '](' . (string)$image['url'] . ')',
                    array_slice($result['images'], 0, 4)
                )),
            ],
        ];
    }

    /**
     * Read one page in full for the model.
     *
     * Search results only carry a bounded excerpt, so a detail that sits deeper
     * in a page (a figure, a date, a quotation) would otherwise be guessed at.
     * The URL is validated by the service, so a tool call can never make the
     * server fetch an internal or non-http address.
     */
    private function openWebsite(array $args): array {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'url required'];
        }
        $query = trim((string)($args['query'] ?? ''));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $maxChars = isset($args['max_chars']) ? (int)$args['max_chars'] : null;
        $page = $this->webSearch->openPage($url, $query, $offset, $maxChars);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => (string)($page['error'] ?? 'The page could not be opened.')];
        }
        return [
            'ok' => true,
            'result' => [
                'url' => $page['url'],
                'title' => $page['title'],
                'external' => true,
                'published' => $page['published'],
                'truncated' => $page['truncated'],
                'offset' => $page['offset'],
                'next_offset' => $page['next_offset'],
                'total_chars' => $page['total_chars'],
                'has_more' => $page['has_more'],
                'highlights' => $page['highlights'],
                'images' => $page['images'],
                'text' => $page['text'],
            ],
        ];
    }

    /**
     * The user's Talk rooms, so the model can pick the right one by name.
     */
    private function listTalkRooms(string $userId, array $args): array
    {
        $limit = (int)($args['limit'] ?? 25);
        $rooms = $this->talkChat->rooms($userId, $limit);
        if ($rooms === []) {
            return [
                'ok' => false,
                'error' => 'No Nextcloud Talk rooms found. Either Talk is not installed, or the user is not a member of any room.',
            ];
        }
        return ['ok' => true, 'result' => $rooms];
    }

    /**
     * Read one Talk room's recent messages. The room is resolved against the
     * user's own room list, so a name from the model can never reach a room the
     * user is not in.
     */
    private function readTalkChat(string $userId, array $args): array
    {
        $room = trim((string)($args['room'] ?? ''));
        if ($room === '') {
            return ['ok' => false, 'error' => 'room required'];
        }
        $limit = (int)($args['limit'] ?? 50);
        $result = $this->talkChat->read($userId, $room, $limit, !empty($args['unread_only']));
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The chat could not be read.')];
        }
        return [
            'ok' => true,
            'result' => [
                'room' => $result['room'],
                'messages' => $result['messages'],
                'unreadOnly' => (bool)($result['unreadOnly'] ?? false),
                'lastReadMessage' => $result['lastReadMessage'] ?? null,
                'text' => $result['text'],
            ],
        ];
    }

    /**
     * Post into a Talk room as the asking user.
     */
    private function sendTalkMessage(string $userId, array $args): array
    {
        $room = trim((string)($args['room'] ?? ''));
        $message = trim((string)($args['message'] ?? ''));
        if ($room === '' || $message === '') {
            return ['ok' => false, 'error' => 'room and message required'];
        }
        $result = $this->talkChat->send($userId, $room, $message);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The message could not be posted.')];
        }
        return [
            'ok' => true,
            'result' => [
                'room' => $result['room'],
                'messageId' => $result['messageId'],
                'sentAt' => $result['sentAt'],
            ],
        ];
    }

    /**
     * Ground an answer with external search results (Issue #187). The model
     * decides when to call this; a failed search is reported as a normal tool
     * error so the answer still falls back to the local sources.
     */
    private function runWebSearch(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required'];
        }
        $mode = trim((string)($args['mode'] ?? 'web'));
        if (!in_array($mode, WebSearchService::MODES, true)) {
            $mode = 'web';
        }
        $result = $this->webSearch->search($query, null, $mode);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'Web search failed.')];
        }
        // The service already ranks and bounds the list; this only guards the
        // tool result against a misconfigured limit.
        $results = array_slice($result['results'], 0, 20);
        if ($results === []) {
            return ['ok' => true, 'result' => ['query' => $query, 'provider' => $result['provider'], 'results' => []]];
        }
        return [
            'ok' => true,
            'result' => [
                'query' => $query,
                'mode' => $result['mode'] ?? $mode,
                'provider' => $result['provider'],
                // `external: true` marks these as links outside the Nextcloud
                // instance so callers never confuse them with indexed files.
                'external' => true,
                'results' => $results,
            ],
        ];
    }

    private function connectorRows(): array {
        $user = $this->config->userId() ?? '';
        if ($user === '') return [];
        $raw = Server::get(\OCP\IConfig::class)->getUserValue($user, AppConfig::APP, 'external_connectors', '{}');
        $rows = json_decode($raw, true);
        return is_array($rows) ? $rows : [];
    }

    private function listExternalConnectors(): array {
        $out = [];
        foreach ($this->connectorRows() as $id => $row) {
            if (!is_array($row)) continue;
            $out[] = ['id' => (string)$id, 'name' => (string)($row['name'] ?? $id), 'base_url' => (string)($row['base_url'] ?? ''), 'token_configured' => !empty($row['token_configured']), 'updated_at' => (int)($row['updated_at'] ?? 0), 'discovered_endpoint_count' => is_array($row['openapi']['endpoints'] ?? null) ? count($row['openapi']['endpoints']) : 0, 'openapi_updated_at' => (int)($row['openapi']['updated_at'] ?? 0)];
        }
        return ['ok' => true, 'result' => ['connectors' => $out]];
    }

    private function discoverExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? ''))); $row = $this->connectorRows()[$id] ?? null;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !is_array($row) || !$this->safeConnectorUrl((string)($row['base_url'] ?? ''))) return ['ok' => false, 'error' => 'Connector is not configured.'];
        $user = $this->config->userId() ?? ''; $headers = ['Accept' => 'application/json'];
        try {
            if (!empty($row['token_configured'])) $headers['Authorization'] = 'Bearer ' . Server::get(ProviderCredentials::class)->getCustom($user, 'connector_' . $id);
            $client = Server::get(\OCP\Http\Client\IClientService::class)->newClient(); $found = null; $source = null;
            foreach (['/openapi.json', '/swagger.json', '/.well-known/openapi.json'] as $candidate) {
                $url = rtrim((string)$row['base_url'], '/') . $candidate; if (!$this->safeConnectorUrl($url)) continue;
                $response = $client->get($url, ['headers' => $headers, 'timeout' => 15, 'allow_redirects' => ['max' => 0]]);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) continue;
                $body = $response->getBody(); if (is_resource($body)) $body = stream_get_contents($body); $decoded = json_decode(mb_substr((string)$body, 0, 200000), true);
                if (is_array($decoded) && is_array($decoded['paths'] ?? null)) { $found = $decoded; $source = $candidate; break; }
            }
            if ($found === null) return ['ok' => false, 'error' => 'No OpenAPI or Swagger description was found.'];
            $endpoints = [];
            foreach (array_slice($found['paths'], 0, 100, true) as $path => $operations) {
                if (!is_string($path) || !is_array($operations) || !str_starts_with($path, '/')) continue;
                foreach ($operations as $method => $operation) if (in_array(strtoupper((string)$method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $meta = ['path' => mb_substr($path, 0, 300), 'method' => strtoupper((string)$method), 'operation_id' => is_array($operation) ? mb_substr((string)($operation['operationId'] ?? ''), 0, 120) : ''];
                    if (is_array($operation) && is_array($operation['parameters'] ?? null)) {
                        $params = [];
                        foreach (array_slice($operation['parameters'], 0, 20) as $parameter) {
                            if (!is_array($parameter)) continue;
                            $name = (string)($parameter['name'] ?? '');
                            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) !== 1) continue;
                            $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];
                            $params[] = ['name' => $name, 'in' => in_array(($parameter['in'] ?? ''), ['query', 'path', 'header', 'cookie'], true) ? (string)$parameter['in'] : 'query', 'required' => !empty($parameter['required']), 'type' => preg_match('/^[A-Za-z0-9_.-]{1,40}$/', (string)($schema['type'] ?? 'string')) === 1 ? (string)($schema['type'] ?? 'string') : 'string'];
                        }
                        if ($params !== []) $meta['parameters'] = $params;
                    }
                    $endpoints[] = $meta;
                }
            }
            $rows = $this->connectorRows(); $rows[$id]['openapi'] = ['source' => $source, 'version' => mb_substr((string)($found['openapi'] ?? $found['swagger'] ?? ''), 0, 30), 'endpoints' => array_slice($endpoints, 0, 200), 'updated_at' => time()];
            Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
            return ['ok' => true, 'result' => ['connector' => $id, 'source' => $source, 'title' => mb_substr((string)($found['info']['title'] ?? ''), 0, 160), 'endpoints' => array_slice($endpoints, 0, 200)]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'External API discovery failed.']; }
    }

    private function configureExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? '')));
        $base = rtrim(trim((string)($args['base_url'] ?? '')), '/');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !$this->safeConnectorUrl($base)) return ['ok' => false, 'error' => 'Connector id or base_url is invalid; use public HTTPS or a local HTTP(S) host.'];
        $name = trim((string)($args['name'] ?? $id));
        if ($name === '') $name = $id;
        $rows = $this->connectorRows();
        $rows[$id] = ['name' => mb_substr($name, 0, 120), 'base_url' => $base, 'token_configured' => isset($args['token']) && trim((string)$args['token']) !== '', 'updated_at' => time()];
        $user = $this->config->userId() ?? '';
        Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
        if (array_key_exists('token', $args)) Server::get(ProviderCredentials::class)->saveCustom($user, 'connector_' . $id, trim((string)$args['token']));
        return ['ok' => true, 'result' => ['id' => $id, 'name' => $name, 'base_url' => $base, 'token_configured' => $rows[$id]['token_configured']]];
    }

    private function callExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? ''))); $path = trim((string)($args['path'] ?? '')); $method = strtoupper(trim((string)($args['method'] ?? ''))); $params = $args['params'] ?? [];
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !is_array($params) || count($params) > 50 || $path === '' || str_contains($path, '..') || preg_match('/[\r\n]/', $path)) return ['ok' => false, 'error' => 'Invalid connector request.'];
        $row = $this->connectorRows()[$id] ?? null; if (!is_array($row) || !$this->safeConnectorUrl((string)($row['base_url'] ?? ''))) return ['ok' => false, 'error' => 'Connector is not configured or its host is no longer allowed.'];
        $url = rtrim((string)$row['base_url'], '/') . '/' . ltrim($path, '/');
        if (!$this->safeConnectorUrl($url)) return ['ok' => false, 'error' => 'Connector path leaves the configured HTTPS host.'];
        try {
            $client = Server::get(\OCP\Http\Client\IClientService::class)->newClient(); $headers = ['Accept' => 'application/json']; $user = $this->config->userId() ?? '';
            if (!empty($row['token_configured'])) $headers['Authorization'] = 'Bearer ' . Server::get(ProviderCredentials::class)->getCustom($user, 'connector_' . $id);
            $options = ['headers' => $headers, 'timeout' => 20, 'allow_redirects' => ['max' => 0]];
            if ($method === 'GET') $options['query'] = $params; elseif ($params !== []) { $headers['Content-Type'] = 'application/json'; $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $options['headers'] = $headers; }
            $response = $client->{strtolower($method)}($url, $options); $body = $response->getBody(); if (is_resource($body)) $body = stream_get_contents($body); $body = mb_substr((string)$body, 0, 50000); $data = json_decode($body, true);
            return ['ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300, 'result' => ['status' => $response->getStatusCode(), 'data' => $data ?? $body, 'connector' => $id, 'method' => $method, 'path' => $path]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'External connector request failed.']; }
    }

    private function safeConnectorUrl(string $url): bool {
        $parts = parse_url($url); $host = strtolower((string)($parts['host'] ?? '')); $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return false;
        if (filter_var($host, FILTER_VALIDATE_IP) === false && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return false;
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        $isLocalName = $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.lan');
        $isPrivateIp = filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && !str_starts_with($ip, '169.254.');
        $local = $isLocalName || $isPrivateIp;
        if ($scheme === 'http' && !$local) return false;
        if ($local) return $isLocalName || $isPrivateIp;
        // For hostnames gethostbyname() must resolve (the `$ip !== $host`
        // condition); literal public IP addresses are already validated above.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function weather(array $args): array {
        $loc = trim((string)($args['location'] ?? ''));
        if ($loc === '') {
            return ['ok' => false, 'error' => 'location required'];
        }
        $geo = $this->httpGet('https://geocoding-api.open-meteo.com/v1/search?count=1&language=de&format=json&name=' . rawurlencode($loc));
        if ($geo === null) {
            return ['ok' => false, 'error' => 'Weather service unreachable.'];
        }
        $g = json_decode($geo, true);
        $lat = $g['results'][0]['latitude'] ?? null;
        $lon = $g['results'][0]['longitude'] ?? null;
        $name = (string)($g['results'][0]['name'] ?? $loc);
        if ($lat === null || $lon === null) {
            return ['ok' => false, 'error' => 'Place not found: ' . $loc];
        }
        $f = $this->httpGet('https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon . '&daily=temperature_2m_max,temperature_2m_min,weathercode&forecast_days=3&timezone=auto');
        if ($f === null) {
            return ['ok' => false, 'error' => 'Weather service unreachable.'];
        }
        $j = json_decode($f, true);
        $codes = [
            0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
            45 => 'Fog', 48 => 'Rime fog',
            51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle',
            61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
            71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow',
            80 => 'Light showers', 81 => 'Showers', 82 => 'Heavy showers',
            95 => 'Thunderstorm', 96 => 'Thunderstorm with hail', 99 => 'Thunderstorm with hail',
        ];
        $days = [];
        foreach (($j['daily']['time'] ?? []) as $i => $day) {
            $code = (int)($j['daily']['weathercode'][$i] ?? 0);
            $days[] = [
                'date' => (string)$day,
                'forecast' => (string)($codes[$code] ?? 'Unknown'),
                'max' => ($j['daily']['temperature_2m_max'][$i] ?? null),
                'min' => ($j['daily']['temperature_2m_min'][$i] ?? null),
            ];
        }
        return ['ok' => true, 'result' => ['location' => $name, 'days' => $days]];
    }

    private function httpGet(string $url, int $timeout = 8): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'EvaAi/1.0',
        ]);
        $r = curl_exec($ch);
        $err = curl_errno($ch);
        // No curl_close(): the handle is freed automatically (PHP 8.0+) and the
        // function is deprecated in PHP 8.5.
        return $err === 0 && is_string($r) && $r !== '' ? $r : null;
    }
}
