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
use OCP\EventDispatcher\IEventDispatcher;
use OCA\EvaAi\Event\ToolPluginRegisterEvent;

/**
 * Führt "AI Actions" auf dem gesamten Benutzer-Dateibereich aus.
 *
 * Anders als die frühere Sandbox-Variante darf das Modell Dateien im ganzen
 * Home-Verzeichnis des eingeloggten Benutzers anlegen, umbenennen, lesen,
 * durchsuchen und löschen - plus Notizen (Notes-Ordner) und Kontakte
 * (CardDAV-Adressbuch des Benutzers).
 */
class ActionExecutor {
    private ToolDomainRegistry $domainRegistry;
    private ExternalToolExecutor $externalExecutor;
    private const LEARNED_API_TTL = 2592000; // refresh route metadata monthly
    private const APP_API_TIMEOUT = 30;
    private const APP_API_BATCH_BUDGET = 20;
    private bool $pluginsLoaded = false;

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
        'update_note' => ['path', 'content'],
        'list_notes' => [],
        'read_note' => ['path'],
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
        'call_app_api_batch' => ['calls'],
        'run_safe_command' => ['command'],
        'run_terminal_command' => ['command'],
        'run_terminal_sequence' => ['commands'],
        'configure_external_connector' => ['id', 'base_url'],
        'diagnose_external_connector' => ['id'],
        'call_external_connector' => ['id', 'path', 'method'],
        'call_external_connector_batch' => ['calls'],
        'create_scheduled_briefing' => ['prompt', 'time', 'days'],
        'update_scheduled_briefing' => ['briefing_id'],
        'delete_scheduled_briefing' => ['briefing_id'],
        'list_scheduled_assignments' => [],
        'create_scheduled_assignment' => ['prompt', 'recurrence'],
        'update_scheduled_assignment' => ['assignment_id'],
        'delete_scheduled_assignment' => ['assignment_id'],
        'create_sticker' => ['prompt'],
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
        private ?\OCP\SystemTag\ISystemTagManagerFactory $systemTagFactory = null,
        private ?UsageMetrics $usageMetrics = null,
        private ?ToolPluginRegistry $pluginRegistry = null,
        private ?IEventDispatcher $eventDispatcher = null,
        private ?OpenAICompatible $imageProvider = null,
        private ?Ollama $ollama = null,
        private ?ScheduledAssignmentService $scheduledAssignments = null,
    ) {
        $this->domainRegistry = new ToolDomainRegistry();
        $this->externalExecutor = new ExternalToolExecutor($this->config);
        $this->registerDomainHandlers();
    }

    private function registerDomainHandlers(): void {
        $this->domainRegistry->registerExecutor(new CalendarToolExecutor($this->calendar));
        $this->domainRegistry->registerExecutor(new ShareToolExecutor($this->shares));
        $this->domainRegistry->registerExecutor(new EmailToolExecutor($this->email, $this->ollama));
        $this->domainRegistry->registerExecutor(new BriefingToolExecutor($this->config, $this->scheduledAssignments));
        $this->domainRegistry->registerExecutor(new TalkToolExecutor($this->talkChat));
        $this->domainRegistry->registerExecutor(new TerminalToolExecutor($this->config));
        $this->domainRegistry->registerExecutor(new ContactsToolExecutor($this->contacts, $this->accounts, $this->userManager));
        $this->domainRegistry->registerExecutor(new FileToolExecutor($this->rootFolder, $this->config, $this->indexer));
        $this->domainRegistry->registerExecutor($this->externalExecutor);
        $this->domainRegistry->registerExecutor(new FileMetadataToolExecutor($this->rootFolder, $this->userManager, $this->config, $this->commentsFactory, $this->systemTagFactory));
        $this->domainRegistry->register('current_time', fn(string $user, array $args): array => $this->currentTime($user));
        $this->domainRegistry->register('server_status', fn(string $user, array $args): array => $this->serverStatus($user));
        $this->domainRegistry->register('weather', fn(string $user, array $args): array => $this->weather($args));
        $this->domainRegistry->register('web_search', fn(string $user, array $args): array => $this->runWebSearch($args));
        $this->domainRegistry->register('search_images', fn(string $user, array $args): array => $this->runImageSearch($args));
        $this->domainRegistry->register('open_website', fn(string $user, array $args): array => $this->openWebsite($args));
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
        $surface = $this->toolPolicy->getSurface();
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
                'description' => 'Create (or overwrite) a file anywhere in the user\'s Nextcloud home. Use content for text; use content_base64 for validated binary/ZIP-based files such as generated Office documents.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from the home folder, e.g. "Documents/Plan.md" or "Report.txt".'],
                    'content' => ['type' => 'string', 'description' => 'Full UTF-8 text content.'],
                    'content_base64' => ['type' => 'string', 'description' => 'Optional strict base64-encoded binary content (mutually exclusive with content).'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_files',
                'description' => 'Create or update up to 20 related plain-text files in one agent step. Each file is validated with the same allowed-type and size limits as create_file; failures are returned per file so successful files are not lost.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'files' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                        'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'],
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
                'name' => 'list_notes',
                'description' => 'List all Markdown notes in the user\'s Notes folder (Nextcloud Notes app). Returns file names and modification dates.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Optional search term to filter notes by title.'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_note',
                'description' => 'Read the content of a specific Markdown note from the Notes folder.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the note, e.g. "Meeting minutes.md" or "Notes/My Note.md".'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_note',
                'description' => 'Update the content of an existing Markdown note. The file must already exist.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the note, e.g. "Meeting minutes.md".'],
                    'content' => ['type' => 'string', 'description' => 'The new Markdown content to replace the existing content.'],
                ], 'required' => ['path', 'content']],
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
                'description' => 'Search the user\'s Nextcloud files by name or content keywords, including readable text and common unindexed PDF, DOCX, XLSX, PPTX, ODF and EPUB files. Narrow the bounded scan with an optional folder path and file extension for faster results; use force_refresh when a file was just uploaded or changed. This never starts a full indexing run.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Keyword to look for in file and folder names and in bounded text-file content (case-insensitive).'],
                    'path' => ['type' => 'string', 'description' => 'Optional folder to search below, e.g. "Documents/2026".'],
                    'extension' => ['type' => 'string', 'description' => 'Optional file extension filter, e.g. "pdf" or ".docx".'],
                    'force_refresh' => ['type' => 'boolean', 'description' => 'Skip the short-lived search cache and inspect the current filesystem immediately.'],
                    'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Optional scan depth (default 5). Increase this for deeply nested folders; the hard maximum is 10.'],
                    'max_nodes' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 10000, 'description' => 'Optional maximum filesystem nodes to inspect (default 2000).'],
                    'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Optional maximum matches to return (default 50).'],
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
                'description' => 'List calendar events across ALL calendars the user can see (including shared/read-only calendars). Default: today up to the next 60 days. Pass calendar only when the user names a specific calendar.',
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
                'name' => 'summarize_emails',
                'description' => 'Summarize recent or matching emails from the Nextcloud Mail app. Includes key points, action items and deadlines without inventing details.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Maximum emails to include (1-20, default 8).'],
                    'unread_only' => ['type' => 'boolean', 'description' => 'Only include unread emails.'],
                    'query' => ['type' => 'string', 'description' => 'Optional subject/sender/body search text.'],
                    'focus' => ['type' => 'string', 'description' => 'Optional focus such as action items, deadlines or decisions.'],
                ]],
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
                'name' => 'run_terminal_command',
                'description' => 'Run one explicitly confirmed terminal command on the Nextcloud host. The command is parsed without a shell; its executable must be in the user-configured allowlist unless the user explicitly enables custom-executable mode. Optional stdin can answer a bounded interactive prompt. Output and time are bounded. Disabled by default.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Executable plus arguments, for example "git status --short". Shell operators, pipes, redirects, substitutions and newlines are rejected.'],
                    'stdin' => ['type' => 'string', 'maxLength' => 4000, 'description' => 'Optional bounded input for a program prompt. It is sent through a pipe, never interpreted by a shell and redacted from persisted traces.'],
                    'timeout_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'description' => 'Optional hard timeout, default 10 seconds.'],
                ], 'required' => ['command']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'run_terminal_sequence',
                'description' => 'Run up to five explicitly confirmed terminal commands sequentially on the Nextcloud host. Each command is parsed without a shell, uses the same allowlist/custom-executable setting, and stops after the first failure or timeout. Useful for a short diagnostic workflow; shell operators, pipes and redirects are never accepted.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'commands' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000]],
                    'stdin' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => 4000], 'description' => 'Optional input per command, matched by index. Each value is bounded, sent without shell interpretation and followed by EOF.'],
                    'timeout_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'description' => 'Optional hard timeout per command, default 10 seconds.'],
                ], 'required' => ['commands']],
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
                'description' => 'Call a discovered endpoint of an enabled Nextcloud app in the current user session. OCS and other same-origin app routes are supported when discovered first. Do not use this tool for configured external connectors such as TrueNAS or Home Assistant; use call_external_connector for those. Read methods are allowed; POST, PUT, PATCH and DELETE always require explicit confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'app_id' => ['type' => 'string', 'description' => 'Enabled Nextcloud app id, e.g. deck or bookmarks.'],
                    'path' => ['type' => 'string', 'description' => 'Same-origin route path returned by discover_app_api. OCS paths begin with /ocs/v1.php/apps/{app_id}/ or /ocs/v2.php/apps/{app_id}/; internal app routes must have been discovered with include_internal=true.'],
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                    'params' => ['type' => 'object', 'description' => 'Query/body parameters for the OCS endpoint. Never include credentials.'],
                ], 'required' => ['app_id', 'path', 'method']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'call_app_api_batch',
                'description' => 'Call up to 10 previously discovered, read-only Nextcloud app API routes in one agent step. Only GET requests are accepted; use this to gather related data efficiently before planning a change.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'calls' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object', 'properties' => [
                        'app_id' => ['type' => 'string'], 'path' => ['type' => 'string'], 'params' => ['type' => 'object'],
                    ], 'required' => ['app_id', 'path']]],
                ], 'required' => ['calls']],
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
                'name' => 'list_scheduled_assignments',
                'description' => 'List all Nextcloud Assistant "Geplante Aufgaben" (scheduled assignments) for the user. These are recurring AI tasks that run automatically on a schedule.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_scheduled_assignment',
                'description' => 'Create a new Nextcloud Assistant "Geplante Aufgabe" (scheduled assignment). This creates a recurring AI task that runs automatically on a schedule. Use natural language for recurrence like "täglich", "wöchentlich", "alle 2 Tage", "jeden Montag".',
                'parameters' => ['type' => 'object', 'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'The prompt/instruction for the AI to execute on schedule.'],
                    'recurrence' => ['type' => 'string', 'description' => 'When to run: "täglich", "wöchentlich", "monatlich", "alle N Tage/Wochen", "jeden Montag", etc.'],
                    'starts_at' => ['type' => 'string', 'description' => 'Optional start time in ISO format or relative like "morgen 09:00". Defaults to now.'],
                    'timezone' => ['type' => 'string', 'description' => 'Optional timezone, e.g. "Europe/Berlin". Defaults to user timezone.'],
                ], 'required' => ['prompt', 'recurrence']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_scheduled_assignment',
                'description' => 'Update an existing Nextcloud Assistant scheduled assignment.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'assignment_id' => ['type' => 'string', 'description' => 'Id from list_scheduled_assignments.'],
                    'prompt' => ['type' => 'string', 'description' => 'New prompt/instruction.'],
                    'recurrence' => ['type' => 'string', 'description' => 'New recurrence rule.'],
                    'starts_at' => ['type' => 'string', 'description' => 'New start time.'],
                    'timezone' => ['type' => 'string', 'description' => 'New timezone.'],
                ], 'required' => ['assignment_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_scheduled_assignment',
                'description' => 'Delete a Nextcloud Assistant scheduled assignment.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'assignment_id' => ['type' => 'string', 'description' => 'Id from list_scheduled_assignments.'],
                ], 'required' => ['assignment_id']],
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
                'name' => 'create_sticker',
                'description' => 'Generate a sticker image from a prompt and save it in the user\'s EVA folder. Requires explicit confirmation and a configured OpenAI-compatible image provider.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'What the sticker should depict. Avoid private or identifying personal details.'],
                ], 'required' => ['prompt']],
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
                'name' => 'diagnose_external_connector',
                'description' => 'Check connectivity from the Nextcloud server to a configured connector. Reports DNS/HTTP status, resolved address, latency and a safe error category without revealing credentials. Use this when a Mac, NAS or other host may not be reachable from the server.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Configured connector id.'],
                ], 'required' => ['id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'configure_external_connector',
                'description' => 'Create or update a named external connector. Public HTTPS and explicitly local HTTP(S) services are supported. Choose no auth, bearer token, basic username/password or API key; all secrets are encrypted and never shown to EVA.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Stable connector id, lowercase letters, numbers, underscore or hyphen (max 40).'],
                    'name' => ['type' => 'string', 'description' => 'Human-readable connector name.'],
                    'base_url' => ['type' => 'string', 'description' => 'Base URL. Public services must use HTTPS; local private/loopback hosts may use HTTP or HTTPS, e.g. http://homeassistant.local:8123 or https://192.168.1.20.'],
                    'openapi_url' => ['type' => 'string', 'description' => 'Optional same-host OpenAPI/Swagger JSON URL when the service publishes its schema at a custom path.'],
                    'token' => ['type' => 'string', 'description' => 'Optional bearer token; encrypted at rest and never returned.'],
                    'auth_type' => ['type' => 'string', 'enum' => ['none', 'bearer', 'basic', 'api_key'], 'description' => 'Authentication scheme.'],
                    'username' => ['type' => 'string', 'description' => 'Optional username for basic authentication; encrypted at rest.'],
                    'password' => ['type' => 'string', 'description' => 'Optional password for basic authentication; encrypted at rest.'],
                    'api_key' => ['type' => 'string', 'description' => 'Optional API key; encrypted at rest.'],
                    'api_key_header' => ['type' => 'string', 'description' => 'Header for API keys, for example X-API-Key (default).'],
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
            ['type' => 'function', 'function' => [
                'name' => 'call_external_connector_batch',
                'description' => 'Call up to 8 discovered external connector GET endpoints in one step. Use this to gather related data efficiently; write methods remain confirmation-gated through call_external_connector.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'calls' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string'], 'path' => ['type' => 'string'], 'params' => ['type' => 'object'],
                    ], 'required' => ['id', 'path']]],
                ], 'required' => ['calls']],
            ]],
        ];
        // Ollama akzeptiert leere "properties" nur als leeres OBJEKT {}
        foreach ($output as &$t) {
            $t['function']['parameters']['properties'] = (array)$t['function']['parameters']['properties'] === []
                ? (object)[]
                : $t['function']['parameters']['properties'];
        }
        unset($t);

        // Third-party apps can extend EVA without patching this class. Plugin
        // tools are appended after built-ins and still pass the normal surface
        // and confirmation checks in run().
        $output = array_merge($output, $this->pluginDefinitions());

        // Tool definitions are filtered at the same policy boundary as
        // execution. This is important for callers such as Talk and
        // TaskProcessing: a provider must never expose a tool merely because
        // a caller supplied or requested its name.
        $output = array_values(array_filter($output, function (array $tool): bool {
            $name = (string)($tool['function']['name'] ?? '');
            return $name !== '' && (($this->toolPolicy->check($name)['allowed'] ?? false)
                || $this->pluginRegistryOrNull()?->get($name) !== null);
        }));

        return $output;
    }

    /** @return list<array{type:string,function:array<string,mixed>}> */
    private function pluginDefinitions(): array {
        $this->loadPlugins();
        return $this->pluginRegistryOrNull()?->definitionsForSurface($this->toolPolicy->getSurface()) ?? [];
    }

    private function pluginRegistryOrNull(): ?ToolPluginRegistry {
        return isset($this->pluginRegistry) ? $this->pluginRegistry : null;
    }

    private function eventDispatcherOrNull(): ?IEventDispatcher {
        return isset($this->eventDispatcher) ? $this->eventDispatcher : null;
    }

    private function loadPlugins(): void {
        $registry = $this->pluginRegistryOrNull();
        $dispatcher = $this->eventDispatcherOrNull();
        if ($this->pluginsLoaded || $registry === null || $dispatcher === null) return;
        $this->pluginsLoaded = true;
        try {
            $dispatcher->dispatchTyped(new ToolPluginRegisterEvent($registry));
        } catch (\Throwable $e) {
            // A broken optional plugin must never remove EVA's built-in tools.
        }
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

    /** Safe catalog for the settings UI; schemas contain no credentials. */
    public function pluginCatalog(): array {
        $registry = $this->pluginRegistryOrNull();
        return array_map(function (array $tool) use ($registry): array {
            $fn = $tool['function'] ?? [];
            $entry = $registry?->get((string)($fn['name'] ?? ''));
            $definition = is_array($entry['definition'] ?? null) ? $entry['definition'] : [];
            return [
                'name' => (string)($fn['name'] ?? ''),
                'description' => (string)($fn['description'] ?? ''),
                'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                'risk' => (string)($definition['risk'] ?? ToolPolicy::RISK_READONLY),
                'surfaces' => array_values(array_map('strval', (array)($definition['surfaces'] ?? []))),
                'requiresConfirmation' => (bool)($definition['requiresConfirmation'] ?? false),
            ];
        }, $this->toolsForSurface(ToolPolicy::SURFACE_WEB));
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
        $startedAt = microtime(true);
        $this->setUserId($userId);
        $snapshotConfirmed = false;
        if ($confirmed && isset($args['_eva_expected_sha256'])) {
            $snapshot = $this->validateFileChangeSnapshot($userId, $name, $args);
            if (empty($snapshot['ok'])) {
                return $snapshot;
            }
            $snapshotConfirmed = true;
            if (array_key_exists('_eva_selected_hunks', $args)) {
                $selected = is_array($args['_eva_selected_hunks']) ? $args['_eva_selected_hunks'] : [];
                if ($selected === []) {
                    return ['ok' => false, 'error' => 'No diff blocks were approved; no file changes were applied.'];
                }
                $oldContent = (string)($snapshot['content'] ?? '');
                if ($name === 'delete_file') {
                    $proposedContent = '';
                } elseif (array_key_exists('content_base64', $args)) {
                    $proposedContent = base64_decode((string)$args['content_base64'], true);
                    if ($proposedContent === false) {
                        return ['ok' => false, 'error' => 'The proposed file content could not be verified; no changes were applied.'];
                    }
                } else {
                    $proposedContent = (string)($args['content'] ?? '');
                }
                // Rebuild the diff from the snapshot and proposed content. The
                // browser sends selected IDs, but its preview payload is not a
                // trusted source for the lines that may be written.
                $preview = (new DiffService())->generateDiff($oldContent, $proposedContent);
                if (empty($preview['previewable']) || empty($preview['hunks'])) {
                    return ['ok' => false, 'error' => 'The diff blocks could not be verified; no changes were applied.'];
                }
                try {
                    $newContent = (new DiffService())->applySelectedHunks(
                        $oldContent,
                        $preview['hunks'],
                        $selected,
                        $preview['ends_with_newline'],
                        $preview['old_ends_with_newline'],
                    );
                } catch (\InvalidArgumentException $e) {
                    return ['ok' => false, 'error' => $e->getMessage()];
                }
                if ($name === 'delete_file' && $newContent !== '') {
                    $name = 'create_file';
                    $args['content'] = $newContent;
                } elseif ($name !== 'delete_file') {
                    $args['content'] = $newContent;
                }
                unset($args['content_base64']);
            }
            unset($args['_eva_expected_sha256'], $args['_eva_preview_path'], $args['_eva_preview']);
            unset($args['_eva_selected_hunks']);
        }
        // Normalize a common model mistake before policy/confirmation is
        // evaluated. Connected external services are not Nextcloud apps;
        // presenting call_app_api here used to show the wrong confirmation
        // dialog and then fail with a confusing OCS 400 response. Resolve the
        // connector alias centrally so both the dialog and execution use the
        // external-connector policy and authentication path.
        if ($name === 'call_app_api') {
            $alias = strtolower(trim((string)($args['app_id'] ?? '')));
            if ($alias !== '' && $this->externalExecutor->hasConnector($alias)) {
                $name = 'call_external_connector';
                $args['id'] = $alias;
                unset($args['app_id']);
            }
        }
        if ($name === 'call_app_api_batch' && is_array($args['calls'] ?? null)) {
            $calls = $args['calls'];
            $connectorCalls = [];
            $hasAppCall = false;
            foreach ($calls as $call) {
                $alias = is_array($call) ? strtolower(trim((string)($call['app_id'] ?? ''))) : '';
                if ($alias !== '' && $this->externalExecutor->hasConnector($alias)) {
                    $connectorCalls[] = ['id' => $alias, 'path' => $call['path'] ?? '', 'params' => $call['params'] ?? []];
                } else {
                    $hasAppCall = true;
                }
            }
            if ($connectorCalls !== [] && $hasAppCall) {
                return ['ok' => false, 'error' => 'Do not mix Nextcloud app routes and external connector routes in one batch. Use call_external_connector_batch for connector calls.'];
            }
            if ($connectorCalls !== []) {
                $name = 'call_external_connector_batch';
                $args = ['calls' => $connectorCalls];
            }
        }
        // Centralized tool permission check
        $this->loadPlugins();
        $registry = $this->pluginRegistryOrNull();
        $plugin = $registry?->get($name);
        if ($plugin !== null) {
            $definition = $plugin['definition'];
            if (!in_array($this->toolPolicy->getSurface(), $definition['surfaces'], true)) {
                return ['ok' => false, 'error' => 'Plugin tool is not available on this execution surface.'];
            }
            if (!empty($definition['requiresConfirmation']) && !$confirmed) {
                return ['ok' => false, 'confirmation_required' => true, 'tool' => $name, 'arguments' => $args,
                    'risk' => $definition['risk'], 'error' => 'This plugin action requires explicit confirmation before it can be executed.'];
            }
            $result = $registry->execute($userId, $name, $args);
            $this->recordToolMetric($userId, $name, $startedAt, (bool)($result['ok'] ?? false));
            return $result;
        }
        $policy = $this->toolPolicy->check($name);
        if (!$policy['allowed']) {
            return ['ok' => false, 'error' => $policy['reason'] ?? 'Tool not allowed'];
        }
        // File writes and deletes in interactive chat must be reviewed before
        // execution. Bind the approval to the file version shown in the diff.
        if (!$snapshotConfirmed && in_array($name, ['create_file', 'create_note', 'update_note', 'delete_file'], true)) {
            if ($this->toolPolicy->getSurface() !== ToolPolicy::SURFACE_WEB) {
                return ['ok' => false, 'error' => 'File changes require a preview and explicit approval in web chat.'];
            }
            $missing = $this->missingRequiredArgs($name, $args);
            if ($missing !== []) {
                return ['ok' => false, 'confirmation_required' => true, 'tool' => $name,
                    'arguments' => $args, 'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                    'missing' => $missing, 'error' => 'This action needs more information before it can run: ' . implode(', ', $missing)];
            }
            return $this->buildFileChangeConfirmation($userId, $name, $args, (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING));
        }
        if (($policy['requiresConfirmation'] ?? false) && !$confirmed) {
            // Generic app API calls are never auto-approved, even when the
            // web surface has complete arguments: the model may have learned
            // an unfamiliar endpoint and the user must review its exact
            // method, path and parameters first.
            if ($name === 'call_app_api' || $name === 'run_safe_command' || $name === 'run_terminal_command' || $name === 'run_terminal_sequence') {
                return [
                    'ok' => false,
                    'confirmation_required' => true,
                    'tool' => $name,
                    'arguments' => $args,
                    'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                    'error' => $name === 'call_app_api'
                        ? 'Generic app API calls always require explicit user confirmation.'
                        : 'Terminal commands always require explicit user confirmation.',
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
                        'arguments' => $args,
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
                    'arguments' => $args,
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
            'list_files', 'create_file', 'create_files', 'create_note', 'list_notes', 'read_note', 'update_note',
            'create_folder', 'rename_file', 'move_file', 'copy_file', 'file_checksum', 'delete_file', 'read_file',
            'read_files', 'inspect_file', 'search_files', 'extract_file_text', 'create_sticker', 'update_knowledge',
        ];
        if (in_array($name, $fileTools, true) && $home === null) {
            return ['ok' => false, 'error' => 'File tools are not available in the background worker (CLI). Ask in the web chat instead.'];
        }

        try {
            $result = $this->domainRegistry->execute($name, $userId, $args);
            if ($result === null) $result = match ($name) {
                'create_sticker' => $this->createSticker($home, $args),
                'recent_activity' => $this->activity->recent($userId, $args),
                'list_nextcloud_capabilities' => $this->listNextcloudCapabilities(),
                'discover_app_api' => $this->discoverAppApi($args),
                'list_learned_app_apis' => $this->listLearnedAppApis(),
                'call_app_api' => $this->callAppApi($args),
                'call_app_api_batch' => $this->callAppApiBatch($args),
                default => ['ok' => false, 'error' => 'Unknown tool: ' . $name],
            };
        } catch (\Throwable $e) {
            $this->recordToolMetric($userId, $name, $startedAt, false);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (($policy['risk'] ?? '') === ToolPolicy::RISK_DESTRUCTIVE) {
            try {
                \OC::$server->get(\Psr\Log\LoggerInterface::class)->notice('eva_ai destructive tool action', [
                    'user' => $userId,
                    'tool' => $name,
                    'ok' => (bool)($result['ok'] ?? false),
                    'target' => $this->auditTarget($args),
                ]);
            } catch (\Throwable) {
            }
        }
        $this->recordToolMetric($userId, $name, $startedAt, (bool)($result['ok'] ?? false));
        return $result;
    }

    private function auditTarget(array $args): string {
        foreach (['path', 'share_id', 'event_id', 'task_id', 'comment_id', 'query', 'briefing_id', 'assignment_id'] as $key) {
            if (isset($args[$key]) && is_scalar($args[$key])) {
                return $key . '=' . mb_substr((string)$args[$key], 0, 200);
            }
        }
        return 'target=redacted';
    }

    private function recordToolMetric(string $userId, string $name, float $startedAt, bool $ok): void {
        if ($this->usageMetrics === null) return;
        try { $this->usageMetrics->recordTool($userId, $name, (int)round((microtime(true) - $startedAt) * 1000), $ok); } catch (\Throwable) { }
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
            $appMetadata = [];
            try {
                $manager = Server::get(\OCP\App\IAppManager::class);
                foreach ($apps as $app) {
                    $info = method_exists($manager, 'getAppInfo') ? $manager->getAppInfo($app) : [];
                    if (!is_array($info)) $info = [];
                    $appMetadata[$app] = [
                        'name' => (string)($info['name'] ?? $app),
                        'version' => (string)($info['version'] ?? ''),
                        'description' => mb_strimwidth((string)($info['description'] ?? ''), 0, 240, '…'),
                    ];
                }
            } catch (\Throwable) { /* metadata is optional on older NC versions */ }
            return [
                'ok' => true,
                'enabled_apps' => $apps,
                'app_metadata' => $appMetadata,
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

    private function getLearnedApis(): array {
        if ($this->learnedApisCache !== null) {
            return $this->learnedApisCache;
        }
        $raw = $this->config->get('learned_app_apis');
        $decoded = json_decode($raw, true);
        $this->learnedApisCache = is_array($decoded) ? $decoded : [];
        return $this->learnedApisCache;
    }

    private function rememberAppApi(string $appId, array $routes): void {
        if ($appId === '') return;
        try {
            $known = $this->getLearnedApis();
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
            $this->learnedApisCache = $known;
        } catch (\Throwable) { /* Learning is best effort. */ }
    }

    private function listLearnedAppApis(): array {
        try {
            $known = $this->getLearnedApis();
            return ['ok' => true, 'result' => ['apps' => $known, 'note' => 'Route metadata is cached per user and may be stale; refresh with discover_app_api before acting.']];
        } catch (\Throwable) { return ['ok' => true, 'result' => ['apps' => []]]; }
    }

    private function callAppApi(array $args): array {
        $appId = strtolower(trim((string)($args['app_id'] ?? '')));
        $method = strtoupper(trim((string)($args['method'] ?? '')));
        $path = trim((string)($args['path'] ?? ''));
        $params = $args['params'] ?? [];
        // Models sometimes classify an explicitly connected appliance as an
        // "app" because its API is app-shaped. Route that alias through the
        // connector implementation so host allow-listing, discovered-route
        // checks, bearer-token handling and GET retries remain enforced.
        if ($appId !== '' && $this->externalExecutor->hasConnector($appId)) {
            return $this->externalExecutor->execute('call_external_connector', $this->config->userId() ?? '', ['id' => $appId, 'method' => $method, 'path' => $path, 'params' => $params]);
        }
        if (!preg_match('/^[a-z0-9_]+$/', $appId) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => false, 'error' => 'A valid app_id and HTTP method are required.'];
        }
        if (!is_array($params) || count($params) > 50) return ['ok' => false, 'error' => 'params must be an object with at most 50 fields.'];
        $split = $this->splitRequestPath($path, $params);
        if ($split === null) return ['ok' => false, 'error' => 'The app API path or query string is invalid.'];
        [$path, $params] = $split;
        $ocsPrefixes = ['/ocs/v1.php/apps/' . $appId . '/', '/ocs/v2.php/apps/' . $appId . '/'];
        $isOcsPath = false;
        foreach ($ocsPrefixes as $prefix) if (str_starts_with($path, $prefix)) $isOcsPath = true;
        if (str_contains($path, '..') || preg_match('/[\r\n]/', $path) || !str_starts_with($path, '/')) {
            return ['ok' => false, 'error' => 'Only same-origin app paths without traversal are allowed.'];
        }
        // Nextcloud app routes are exposed externally below
        // /apps/{app_id}/..., while models naturally return the app-relative
        // route (/api/v1/people). Match and execute both forms consistently.
        $absolutePath = $path;
        if (!$isOcsPath && str_starts_with($path, '/api/')) {
            $absolutePath = '/apps/' . $appId . $path;
        }
        // Every generic route must be present in the user's recent discovery
        // snapshot.  Prefix-only checks are not sufficient: an enabled app can
        // expose administrative or destructive endpoints under the same OCS
        // prefix.  Requiring an exact discovered route keeps the learning
        // loop useful while preventing arbitrary app API probing.
        $knownRoute = false;
        try {
            $learned = $this->getLearnedApis();
            $learnedAt = (int)($learned[$appId]['updated'] ?? 0);
            $routes = ($learnedAt > 0 && $learnedAt >= time() - self::LEARNED_API_TTL && is_array($learned[$appId]['routes'] ?? null)) ? $learned[$appId]['routes'] : [];
            foreach ($routes as $route) {
                if (!is_array($route)) continue;
                $routePath = (string)($route['path'] ?? '');
                $methods = is_array($route['methods'] ?? null) ? array_map('strtoupper', $route['methods']) : [];
                $routeComparable = str_starts_with($routePath, '/apps/' . $appId . '/')
                    ? substr($routePath, strlen('/apps/' . $appId)) : $routePath;
                if ($routePath !== '' && ($this->matchesDiscoveredRoute($routeComparable, $path) || $this->matchesDiscoveredRoute($routePath, $absolutePath)) && ($methods === [] || in_array($method, $methods, true))) {
                    $knownRoute = true;
                    break;
                }
            }
        } catch (\Throwable) { /* treat malformed learning cache as empty */ }
        if (!$knownRoute) {
            // Discovery is read-only and safe. Perform it transparently on
            // the first confirmed call so app integrations (for example
            // integration_immich) do not require the model to know an
            // internal discover-then-call dance.
            if (($args['_auto_discover'] ?? true) === true) {
                $discovered = $this->discoverAppApi(['app_id' => $appId, 'include_internal' => true]);
                if (($discovered['ok'] ?? false) === true) {
                    $args['_auto_discover'] = false;
                    return $this->callAppApi($args);
                }
            }
            return ['ok' => false, 'error' => $isOcsPath
                ? 'This OCS route has not been discovered recently. Call discover_app_api first.'
                : 'This non-OCS route has not been discovered yet. Call discover_app_api with include_internal=true first.'];
        }
        // Replace discovered {path} placeholders from the supplied parameter
        // object before constructing the same-origin URL. Path parameters are
        // never forwarded as query/body values and must be scalar to avoid
        // ambiguous or unsafe route expansion.
        $pathTemplate = $path;
        $path = $this->expandConnectorPath($pathTemplate, $params);
        if ($path === null) return ['ok' => false, 'error' => 'A required path parameter is missing or invalid.'];
        $params = $this->removePathParameters($pathTemplate, $params);
        if (!$isOcsPath && str_starts_with($path, '/api/')) $absolutePath = '/apps/' . $appId . $path;
        try {
            $appManager = Server::get(\OCP\App\IAppManager::class);
            if (!in_array($appId, array_map('strval', $appManager->getEnabledApps()), true) || !$appManager->isEnabledForUser($appId)) {
                return ['ok' => false, 'error' => 'That app is not enabled for the current user.'];
            }
            $request = Server::get(\OCP\IRequest::class);
            $client = Server::get(\OCP\Http\Client\IClientService::class)->newClient();
            $url = Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL($absolutePath);
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
            elseif ($params !== []) {
                if ($isOcsPath) {
                    // OCS endpoints conventionally consume form-style fields.
                    $options['body'] = $params;
                } else {
                    // Internal app REST routes are commonly JSON APIs. Using
                    // an explicit JSON string avoids client-dependent array
                    // coercion and matches the generic connector adapter.
                    try {
                        $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $options['headers']['Content-Type'] = 'application/json';
                    } catch (\Throwable) {
                        return ['ok' => false, 'error' => 'App API parameters could not be encoded as JSON.'];
                    }
                }
            }
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
            // Generic app APIs may return credentials or session material even
            // on an otherwise harmless GET. Keep the adapter useful while
            // ensuring obvious secret-shaped fields never reach the model.
            $safeData = is_array($decoded) ? $this->redactApiPayload($decoded) : $body;
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if ($ok) $this->rememberAppApiPattern($appId, $method, $path, array_keys($params), is_array($safeData) ? $this->shapeOf($safeData) : ['type' => 'string']);
            return ['ok' => $ok, 'result' => ['status' => $status, 'data' => $safeData, 'path' => $path, 'method' => $method]];
        } catch (\Throwable $e) {
            $detail = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
            return ['ok' => false, 'error' => 'The app API request failed in the current user context.' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 220) : '')];
        }
    }

    /** Execute bounded GET calls while reusing the same discovery/security path. */
    private function callAppApiBatch(array $args): array {
        $calls = $args['calls'] ?? null;
        if (!is_array($calls) || $calls === [] || count($calls) > 10) {
            return ['ok' => false, 'error' => 'calls must contain between 1 and 10 requests.'];
        }
        $results = [];
        $batchDeadline = microtime(true) + self::APP_API_BATCH_BUDGET;
        foreach ($calls as $call) {
            if (!is_array($call)) {
                $results[] = ['ok' => false, 'error' => 'Each batch item must be an object.'];
                continue;
            }
            if (microtime(true) >= $batchDeadline) {
                $results[] = ['ok' => false, 'error' => 'App API batch time budget reached; retry the remaining read requests separately.'];
                break;
            }
            $results[] = $this->callAppApi([
                'app_id' => $call['app_id'] ?? '',
                'path' => $call['path'] ?? '',
                'method' => 'GET',
                'params' => is_array($call['params'] ?? null) ? $call['params'] : [],
            ]);
        }
        return ['ok' => !in_array(false, array_map(static fn(array $row): bool => (bool)($row['ok'] ?? false), $results), true), 'result' => ['calls' => $results, 'count' => count($results)]];
    }

    /** Remember only reusable call shape, never parameter values or response data. */
    private function rememberAppApiPattern(string $appId, string $method, string $path, array $paramKeys, array $responseShape = []): void {
        if ($appId === '' || $path === '') return;
        try {
            $known = $this->getLearnedApis();
            if (!is_array($known[$appId] ?? null)) $known[$appId] = ['updated' => time(), 'routes' => []];
            $patterns = is_array($known[$appId]['patterns'] ?? null) ? $known[$appId]['patterns'] : [];
            $keys = array_values(array_unique(array_filter(array_map('strval', $paramKeys), static fn(string $key): bool => preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key) === 1)));
            $entry = ['method' => $method, 'path' => $path, 'params' => $keys, 'response_shape' => $responseShape, 'last_used' => time()];
            $fingerprint = $method . ' ' . $path;
            $patterns = array_values(array_filter($patterns, static fn($row): bool => is_array($row) && (($row['method'] ?? '') . ' ' . ($row['path'] ?? '')) !== $fingerprint));
            array_unshift($patterns, $entry);
            $known[$appId]['patterns'] = array_slice($patterns, 0, 50);
            $known[$appId]['updated'] = time();
            if (count($known) > 30) {
                uasort($known, static fn (array $a, array $b): int => ((int)($b['updated'] ?? 0)) <=> ((int)($a['updated'] ?? 0)));
                $known = array_slice($known, 0, 30, true);
            }
            $this->config->set('learned_app_apis', json_encode($known, JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->learnedApisCache = $known;
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

    /** Remove credential-like fields from arbitrary JSON returned by an app. */
    private function redactApiPayload(mixed $value, int $depth = 0): mixed {
        if ($depth > 8) return '[redacted depth]';
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $child) {
            $name = strtolower((string)$key);
            if (preg_match('/(?:pass(word)?|token|secret|api[_-]?key|authorization|cookie|private[_-]?key)/i', $name) === 1) {
                $out[$key] = '[redacted]';
            } else {
                $out[$key] = $this->redactApiPayload($child, $depth + 1);
            }
        }
        return $out;
    }

    /** Match a concrete request path against a Nextcloud route template. */
    private function matchesDiscoveredRoute(string $template, string $path): bool {
        $quoted = preg_quote(rtrim($template, '/'), '#');
        $quoted = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', $quoted) ?? $quoted;
        return preg_match('#^' . $quoted . '/?$#', $path) === 1;
    }

    private function expandConnectorPath(string $template, array $params): ?string {
        $expanded = preg_replace_callback('/\{([A-Za-z0-9_.-]{1,80})\}/', static function (array $match) use ($params): string {
            $name = $match[1];
            if (!array_key_exists($name, $params) || is_array($params[$name]) || is_object($params[$name])) return $match[0];
            $value = trim((string)$params[$name]);
            return $value === '' ? $match[0] : rawurlencode($value);
        }, $template);
        if (!is_string($expanded) || preg_match('/\{[A-Za-z0-9_.-]{1,80}\}/', $expanded)) return null;
        return $expanded;
    }

    private function removePathParameters(string $template, array $params): array {
        preg_match_all('/\{([A-Za-z0-9_.-]{1,80})\}/', $template, $matches);
        foreach (($matches[1] ?? []) as $name) unset($params[$name]);
        return $params;
    }

    /** @return array{0:string,1:array}|null */
    private function splitRequestPath(string $path, array $params): ?array {
        if (str_contains($path, '#')) return null;
        $question = strpos($path, '?');
        if ($question === false) return [$path, $params];
        $clean = substr($path, 0, $question);
        $query = substr($path, $question + 1);
        if ($clean === '' || strlen($query) > 4000) return null;
        $parsed = [];
        if ($query !== '') {
            parse_str($query, $parsed);
            if (!is_array($parsed)) return null;
        }
        foreach ($parsed as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $key) !== 1) return null;
            if (!array_key_exists($key, $params)) $params[$key] = $value;
        }
        return [$clean, $params];
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

    /** Generate one confirmed sticker and keep it in the user's EVA folder. */
    private function createSticker(?Folder $home, array $args): array {
        if (!$home instanceof Folder || $this->imageProvider === null) {
            return ['ok' => false, 'error' => 'Sticker generation requires a configured OpenAI-compatible image provider.'];
        }
        $prompt = trim((string)($args['prompt'] ?? ''));
        if ($prompt === '') return ['ok' => false, 'error' => 'prompt required'];
        try {
            $images = $this->imageProvider->generateImages(
                'Create a single friendly sticker with a transparent background, bold clean outline, no watermark and no readable text: ' . mb_substr($prompt, 0, 1000),
                1,
                180,
            );
            $folder = $home->nodeExists('EVA') ? $home->get('EVA') : $home->newFolder('EVA');
            if (!$folder instanceof Folder) return ['ok' => false, 'error' => 'The EVA folder exists but is not a folder.'];
            $name = 'eva-sticker-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';
            $file = $folder->newFile($name, $images[0]['bytes']);
            return ['ok' => true, 'result' => ['path' => 'EVA/' . $name, 'file_id' => (int)$file->getId(), 'mime' => $images[0]['mime']]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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

    private ?array $connectorRowsCache = null;
    private ?array $learnedApisCache = null;

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
