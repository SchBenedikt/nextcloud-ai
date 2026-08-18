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
    private const MAX_LIST_DEPTH = 2;
    private const MAX_LIST_ENTRIES = 300;
    private const MAX_READ_CHARS = 20000;
    private const MAX_WRITE_CHARS = 100000;
    private const NOTES_FOLDER = 'Notes';

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
        private ToolPolicy $toolPolicy
    ) {
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
                'description' => 'Create (or overwrite) a text file anywhere in the user\'s Nextcloud home, e.g. for drafts, notes, plans or documents. Only configured text file types are allowed. The content must be plain text.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from the home folder, e.g. "Documents/Plan.md" or "Report.txt".'],
                    'content' => ['type' => 'string', 'description' => 'The full text content to write.'],
                ], 'required' => ['path', 'content']],
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
                'name' => 'delete_file',
                'description' => 'Delete a file or an empty folder in the user\'s home. Use only when the user explicitly asks to delete something. Depending on the app settings you may only delete files EVA created itself.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path of the file or folder to delete.'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'read_file',
                'description' => 'Read the text content of a file in the user\'s home (max 20k characters).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path, e.g. "Documents/Notes.md".'],
                ], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_files',
                'description' => 'Search the user\'s entire Nextcloud home for files by name or content keywords.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Keyword to look for in file and folder names (case-insensitive).'],
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
                'name' => 'find_contact',
                'description' => 'Search the user\'s contacts (address books) by name, e-mail or organisation. Returns matching contact details.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Name, e-mail or organisation to search for.'],
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
                'name' => 'server_status',
                'description' => 'Get technical status info of the Nextcloud server (version, PHP, database, app version, Ollama connectivity, user). Use when the user asks about the system, server or setup.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
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
        ];
        // Ollama akzeptiert leere "properties" nur als leeres OBJEKT {}
        foreach ($output as &$t) {
            $t['function']['parameters']['properties'] = (array)$t['function']['parameters']['properties'] === []
                ? (object)[]
                : $t['function']['parameters']['properties'];
        }
        unset($t);
        return $output;
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
        $this->config->setUserId($userId);
        // Centralized tool permission check
        $policy = $this->toolPolicy->check($name);
        if (!$policy['allowed']) {
            return ['ok' => false, 'error' => $policy['reason'] ?? 'Tool not allowed'];
        }
        if (($policy['requiresConfirmation'] ?? false) && !$confirmed) {
            return [
                'ok' => false,
                'confirmation_required' => true,
                'tool' => $name,
                'risk' => (string)($policy['risk'] ?? ToolPolicy::RISK_MUTATING),
                'error' => 'This action requires explicit user confirmation before it can be executed.',
            ];
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
            'list_files', 'create_file', 'create_note', 'create_folder',
            'rename_file', 'delete_file', 'read_file', 'search_files',
            'update_knowledge',
        ];
        if (in_array($name, $fileTools, true) && $home === null) {
            return ['ok' => false, 'error' => 'File tools are not available in the background worker (CLI). Ask in the web chat instead.'];
        }

        try {
            return match ($name) {
                'list_files' => $this->listFiles($home, $args),
                'create_file' => $this->createFile($home, $args),
                'create_note' => $this->createNote($home, $args),
                'create_folder' => $this->createFolder($home, $args),
                'rename_file' => $this->renameFile($home, $args),
                'delete_file' => $this->deleteFile($home, $args),
                'read_file' => $this->readFile($home, $args),
                'search_files' => $this->searchFiles($home, $args),
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
                'server_status' => $this->serverStatus($userId),
                'update_knowledge' => $this->updateKnowledge($home, $args),
                default => ['ok' => false, 'error' => 'Unknown tool: ' . $name],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<array{name:string,path:string,type:string,size?:int}> */
    private function listFiles(Folder $home, array $args): array {
        $path = $this->cleanPath((string)($args['path'] ?? ''));
        $folder = $this->folderAt($home, $path);
        $rootLen = strlen($folder->getPath()) + 1;
        $out = [];
        $count = 0;
        $this->walk($folder, $out, 0, $count, $rootLen);
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
                $this->markOwned($home, $path);
                return ['ok' => true, 'result' => 'Updated ' . $path];
            }
            return ['ok' => false, 'error' => 'A folder with that name already exists at ' . $path];
        }
        $folder->newFile($name, $content);
        $this->markOwned($home, $path);
        return ['ok' => true, 'result' => 'Created ' . $path];
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
        $this->ensureFolderPath($home, $path);
        $this->markOwned($home, $path);
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
        $this->unmarkOwned($home, $path);
        $node->move($parent->getPath() . '/' . $newName);
        $this->markOwned($home, rtrim($this->cleanPath(dirname($path)), '/.') . '/' . $newName);
        return ['ok' => true, 'result' => 'Renamed to ' . $newName];
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
        if ($mode === 'own' && !$this->isOwned($home, $path)) {
            return ['ok' => false, 'error' => 'Only files EVA created itself may be deleted (adjust "delete permission" in the app settings to allow more).'];
        }
        $node = $this->resolve($home, $path);
        if ($node instanceof Folder && $node->getDirectoryListing() !== []) {
            return ['ok' => false, 'error' => 'Folder is not empty'];
        }
        $node->delete();
        $this->unmarkOwned($home, $path);
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
        if ($node->getSize() > self::MAX_READ_CHARS * 4) {
            return ['ok' => false, 'error' => 'File too large to read'];
        }
        $content = (string)$node->getContent();
        if (strpos($content, "\0") !== false) {
            return ['ok' => false, 'error' => 'File is not text'];
        }
        if (mb_strlen($content) > self::MAX_READ_CHARS) {
            $content = mb_substr($content, 0, self::MAX_READ_CHARS) . "\n…(truncated)";
        }
        return ['ok' => true, 'result' => ['path' => $path, 'content' => $content]];
    }

    /** @return array{ok:true,result:array} */
    private function searchFiles(Folder $home, array $args): array {
        $query = mb_strtolower(trim((string)($args['query'] ?? '')));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Search query required'];
        }
        $matches = [];
        $this->searchWalk($home, $query, $matches, 0, '');
        return ['ok' => true, 'result' => ['matches' => $matches]];
    }

    private function searchWalk(Folder $folder, string $query, array &$matches, int $depth, string $prefix): void {
        if ($depth >= self::MAX_SEARCH_DEPTH || count($matches) >= 50) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if (count($matches) >= 50) {
                return;
            }
            $rel = $prefix === '' ? $node->getName() : $prefix . '/' . $node->getName();
            if ($node instanceof Folder) {
                $this->searchWalk($node, $query, $matches, $depth + 1, $rel);
            }
            if (str_contains(mb_strtolower($node->getName()), $query)) {
                $matches[] = ['path' => $rel, 'reason' => 'filename'];
            }
        }
    }

    /** @return array{ok:true,result:array} */
    private function findContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Contact query required'];
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
        $newLen = mb_strlen($content);
        if ($newLen > 60000) {
            $overflow = $content;
            while (mb_strlen($overflow) > 45000) {
                $nl = strpos($overflow, "
");
                if ($nl === false) {
                    break;
                }
                $overflow = substr($overflow, $nl + 1);
            }
            $content = $overflow;
        }
        if ($home->nodeExists($path)) {
            $home->get($path)->putContent($content);
        } else {
            $home->newFile($path, $content);
        }
        return ['ok' => true, 'result' => 'Knowledge updated: ' . $line];
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

    private function ownedList(Folder $home): array {
        $userId = $this->userNameOf($home);
        if ($userId === '') {
            return [];
        }
        try {
            $raw = $this->marksFile($userId)->getContent();
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function markOwned(Folder $home, string $path): void {
        $userId = $this->userNameOf($home);
        if ($userId === '') {
            return;
        }
        $list = $this->ownedList($home);
        $path = $this->cleanPath($path);
        if (!in_array($path, $list, true)) {
            $list[] = $path;
        }
        try {
            $this->marksFile($userId)->putContent(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            /* Marker optional */
        }
    }

    private function unmarkOwned(Folder $home, string $path): void {
        $userId = $this->userNameOf($home);
        if ($userId === '') {
            return;
        }
        $list = array_values(array_filter($this->ownedList($home), static fn($p) => $p !== $path));
        try {
            $this->marksFile($userId)->putContent(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            /* Marker optional */
        }
    }

    private function isOwned(Folder $home, string $path): bool {
        return in_array($this->cleanPath($path), $this->ownedList($home), true);
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
        return $this->email->readMessage($userId, (int)($args['message_id'] ?? 0));
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

    private function weather(array $args): array {
        $loc = trim((string)($args['location'] ?? ''));
        if ($loc === '') {
            return ['ok' => false, 'error' => 'location required'];
        }
        $geo = $this->httpGet('https://geocoding-api.open-meteo.com/v1/search?count=1&language=de&format=json&name=' . rawurlencode($loc));
        if ($geo === null) {
            return ['ok' => false, 'error' => 'Wetterdienst nicht erreichbar (offline?).'];
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
        curl_close($ch);
        return $err === 0 && is_string($r) && $r !== '' ? $r : null;
    }
}
