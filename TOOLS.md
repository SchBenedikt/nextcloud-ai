# Eva Tools Reference

Eva (the Nextcloud AI assistant) can call the following tools during a conversation. Every tool executes inside the logged-in user's own Nextcloud account — files, contacts, calendars, mails, tasks and shares are always the user's own.

Tool usage is proposed by the assistant and **confirmed by the user** before anything is executed.

The web sidebar's chat search, per-chat rename/delete actions, and Settings' **Delete all chats** control are UI operations, not LLM tools. They only manage the logged-in user's saved chat history.

---

## File tools

### list_files
Lists files and folders inside the logged-in user's Nextcloud home. Use it to find out what the user has stored.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | no | Optional folder, e.g. `Documents`. Empty means the home root. |

Results are returned recursively (bounded by depth/entry limits), each entry with `name`, `path`, `type` (`file`/`folder`) and `size`.

### create_file
Creates (or overwrites) a text file anywhere in the user's Nextcloud home — for drafts, notes, plans or documents. Only configured text file types are allowed and the content must be plain text (max. 100,000 characters).

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Relative path from the home folder, e.g. `Documents/Plan.md` or `Report.txt`. |
| `content` | string | yes | The full text content to write. |

### create_note
Creates a Markdown note in the standard Notes folder of the user (visible in the Nextcloud Notes app). Perfect for quick notes, meeting minutes or to-dos.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | yes | Title of the note without extension, e.g. `Meeting minutes`. |
| `content` | string | yes | The Markdown body of the note. |

### create_folder
Creates a new folder anywhere in the user's Nextcloud home.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Relative folder path, e.g. `Projekte/2026`. |

### rename_file
Renames a file or folder in the user's home. The new name must stay in the same directory.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Current relative path, e.g. `Drafts/old.md`. |
| `new_name` | string | yes | New file or folder name including extension, e.g. `final.md`. |

### delete_file
Deletes a file or an empty folder in the user's home. Used only when the user explicitly asks to delete something. Depending on the app settings, Eva may only be allowed to delete files it created itself.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Relative path of the file or folder to delete. |

### read_file
Reads the text content of a file in the user's home (max. 20,000 characters are returned).

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Relative path, e.g. `Documents/Notes.md`. |

### search_files
Searches the user's entire Nextcloud home for file/folder names and bounded readable text content. The walk is capped at 2,000 nodes and depth 5, returns at most 50 matches, and reads at most 1 MB per text file; the result includes `truncated` when a limit is reached. Binary and unsupported files are skipped.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Keyword to look for in file/folder names and bounded readable text content (case-insensitive). |

### update_knowledge
Appends personal facts about the user to the knowledge file `KNOWLEDGE.md` in the home folder (e.g. name, family, work, preferences, allergies, plans). Eva calls it whenever the user shares such information explicitly. The file is read before every answer, so the fact is considered in all future chats. Facts are appended as one bullet per entry; when the size limit is reached, only old non-profile lines are trimmed and the automatic identity section is preserved.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `fact` | string | yes | Short, factual sentence about the user, e.g. `Likes green tea, no milk`. |

---

## Contacts

### find_contact
Searches the user's contacts (address books) by name, e-mail or organisation. Returns matching contact details.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Name, e-mail or organisation to search for. |

### create_contact
Adds a new contact to the user's personal address book (CardDAV).

| Parameter | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Full display name of the contact. |
| `email` | string | no | Optional e-mail address. |
| `phone` | string | no | Optional phone number. |
| `org` | string | no | Optional organisation. |

### update_contact
Updates an existing contact of the user (address book). The contact is identified with `query`.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Contact name, e-mail or organisation of the existing contact. |
| `name` | string | no | Optional new full display name. |
| `email` | string | no | Optional new e-mail address (empty to remove). |
| `phone` | string | no | Optional new phone number (empty to remove). |
| `org` | string | no | Optional new organisation (empty to remove). |

### delete_contact
Deletes a contact from the user's address book. Used only when the user explicitly asks to delete it.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Contact name, e-mail or organisation to delete. |

---

## Profile

### read_profile
Reads the logged-in user's own Nextcloud profile (display name, e-mail, phone, website, address, organisation, role, headline, biography, pronouns). No parameters.

### update_profile
Updates the logged-in user's own Nextcloud profile. Only the fields that should change are passed; an empty string clears a field.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `display_name` | string | no | New display name. |
| `email` | string | no | New primary e-mail address. |
| `phone` | string | no | Phone number. |
| `website` | string | no | Website URL. |
| `address` | string | no | Postal address. |
| `organisation` | string | no | Organisation / company. |
| `role` | string | no | Job title / role. |
| `headline` | string | no | Short headline or tagline. |
| `biography` | string | no | About / biography text. |
| `pronouns` | string | no | Pronouns, e.g. `he/him`. |
---

## Calendar

### list_calendars
Lists all Nextcloud calendars of the user with their ids. No parameters.

### list_calendar_events
Lists calendar events in a time window. Default: today up to the next 60 days.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `days` | integer | no | Convenience: include the next N days starting today (1–60). Equivalent to `end_date = today+N`. |
| `past_days` | integer | no | Include the past N days (0–30). Default 0. |
| `start_date` | string | no | Optional start of the window, ISO-8601 like `2026-08-09`. |
| `end_date` | string | no | Optional end of the window, ISO-8601. |
| `calendar` | string | no | Optional calendar name to limit the search. |
| `categories` | string | no | Optional comma-separated category filter, e.g. `arbeit,privat`. |

### create_calendar_event
Creates a new calendar event (meetings, appointments, reminders). Times **without** a `Z` suffix are interpreted in the user's timezone — write local times like `2026-08-20 16:00` or `20.08.2026 16:00` or `morgen 10:00`, never append `Z`. Append `Z` only if the user explicitly talks about UTC. A plain date creates an all-day event. Before calculating dates, Eva calls `current_time` to get the actual date.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `summary` | string | yes | Event title, e.g. `Team meeting`. |
| `start` | string | yes | Start time in any supported format. |
| `end` | string | no | Optional end time. Default: 1 hour later (all-day: next day). |
| `duration_minutes` | integer | no | Optional duration in minutes. Default 60 (or 1 day for all-day). Ignored if `end` is set. |
| `location` | string | no | Optional location / place. |
| `description` | string | no | Optional description or agenda. |
| `reminder_minutes` | integer | no | Optional reminder X minutes before the event, e.g. 15 or 60. |
| `categories` | string | no | Optional comma-separated categories/tags. |
| `calendar` | string | no | Optional calendar name or id; default is the first calendar. |

### update_calendar_event
Updates an existing calendar event (title, times, location, description, categories, reminder). Use the event id from `list_calendar_events`.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `event_id` | string | yes | Id like `personal/event.ics` as returned by `list_calendar_events`. |
| `summary` | string | no | New title. |
| `start` | string | no | New start, ISO-8601 UTC or plain date. |
| `end` | string | no | New end. |
| `location` | string | no | New location (empty string removes it). |
| `description` | string | no | New description (empty string removes it). |
| `categories` | string | no | New categories (comma separated). Empty string removes them. |
| `reminder_minutes` | integer | no | Replace reminder with a single VALARM that fires X minutes before the event. 0 removes the reminder. |

### delete_calendar_event
Deletes a calendar event. Used only when the user explicitly asks to delete it.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `event_id` | string | yes | Id like `personal/event.ics` as returned by `list_calendar_events`. |

### find_free_slots
Finds free time slots in the user's calendar within the next N days, respecting the configured working hours (default 09:00–18:00 in the user's timezone). Returns at most 10 slots with length ≥ `min_minutes`. Useful before scheduling a meeting.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `days` | integer | no | How many days to look ahead (1–30). Default 7. |
| `min_minutes` | integer | no | Minimum slot length in minutes (5–480). Default 30. |
| `workday_start` | string | no | Working day start `HH:MM`. Default 09:00. |
| `workday_end` | string | no | Working day end `HH:MM`. Default 18:00. |
| `calendar` | string | no | Optional calendar name or id; default = all user calendars. |

---

## Mail

Requires the Nextcloud **Mail** app (and the Mail API / background index) to be enabled.

### search_mails
Searches emails of the user's mail account (subject, sender, preview). Typical use: *find the mail about X* or *show my latest mails*.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Search text, e.g. `Rechnung` or `alice@example.com`. |
| `limit` | integer | no | Optional max results (default 10). |

### list_mails
Lists the most recent emails of the user (latest first). Use when the user asks about their mail without a concrete topic.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `limit` | integer | no | Optional max mails (default 15). |
| `unread_only` | boolean | no | Optional: only unread mails. |

### read_mail
Reads the full content of a single email by its id (ids come from `list_mails` / `search_mails`).

| Parameter | Type | Required | Description |
|---|---|---|---|
| `message_id` | integer | yes | Id of the email. |

### unread_mail_count
Gets how many unread emails the user currently has. No parameters.

---

## Shares

### list_shares
Lists file/folder shares of the user: outgoing and incoming shares with expiry and note. Existing public-link tokens and URLs are redacted; a newly created link returns its URL once.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `limit` | integer | no | Optional max entries (default 100). |

### create_share
Creates a new share for a file or folder in the user's Nextcloud (public link or share with a user/group). Use for *share this file with X* or *make a download link*.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `path` | string | yes | Relative path of the file/folder, e.g. `Documents/Plan.pdf`. |
| `type` | string | no | Share type: `link` (default, public link), `user` or `group`. |
| `target` | string | no | For user/group shares: the user id or group id to share with. |
| `write` | boolean | no | Optional: allow editing (default read-only). |
| `password` | string | no | Optional password for link shares. |
| `expiration` | string | no | Optional expiration date, ISO like `2026-12-31`. |
| `note` | string | no | Optional note / message for the share. |

### update_share
Updates an existing share (note, expiration date, permissions). Use share ids from `list_shares`.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `share_id` | string | yes | Id from `list_shares`. |
| `note` | string | no | New note (empty removes it). |
| `expiration` | string | no | Optional expiration date ISO, empty removes it. |
| `permissions` | string | no | Comma list: `read,write,create,delete,share`. |

### delete_share
Deletes an existing share. Used only when the user explicitly asks to remove a share or link.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `share_id` | string | yes | Id from `list_shares`. |

---

## Tasks

### list_tasks
Lists to-do items / tasks of the user. Open tasks first, then by due date.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | no | Optional filter by status, e.g. `NEEDS-ACTION` or `NEEDS-ACTION,IN-PROCESS`. |
| `category` | string | no | Optional filter by category/tag. |
| `overdue_only` | boolean | no | If true, return only tasks with due date in the past that are not completed. |

### create_task
Creates a new to-do item / task for the user in their default task list.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | yes | Task title. |
| `due` | string | no | Optional due date, any supported format, e.g. `2026-08-20 16:00` or `morgen`. |
| `description` | string | no | Optional longer description / notes. |
| `priority` | integer | no | Optional priority 1–9 (1 highest). |
| `categories` | string | no | Optional comma separated categories/tags. |

### update_task
Updates a task (title, status, due date, description, categories, priority). Use task ids from `list_tasks`.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `task_id` | string | yes | Id like `personal/task.ics` from `list_tasks`. |
| `title` | string | no | New title. |
| `status` | string | no | New status: `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED`, `CANCELLED`. |
| `due` | string | no | New due date, ISO or relative. |
| `description` | string | no | New description (empty removes it). |
| `categories` | string | no | New categories (comma separated). Empty removes them. |
| `priority` | integer | no | New priority 1–9 (1 highest). 0 removes the priority. |

### complete_task
Marks a task as completed. Use when the user says a task is done.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `task_id` | string | yes | Task id from `list_tasks`. |

### delete_task
Deletes a task permanently. Used only when the user explicitly asks to delete it.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `task_id` | string | yes | Task id from `list_tasks`. |

---

## Activity, system and time

### recent_activity
Lists the recent Nextcloud activity feed of the user (files changed, shares, events) across all apps.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `limit` | integer | no | Optional max entries (default 25). |

### server_status
Gets technical status info of the Nextcloud server (version, PHP, database, app version, Ollama connectivity, user). Use when the user asks about the system, server or setup. No parameters.

### current_time
Gets the current date and time in the user's timezone. **IMPORTANT:** as an AI model Eva does not know today's date — it always calls this tool before computing dates, deadlines, appointments or relative times. No parameters.

### weather
Gets the weather forecast (today + 2 days) for a place. Useful for planning outdoor appointments.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `location` | string | yes | City or place, e.g. `Berlin` or `München`. |

---

## Notes on behaviour

- **Read-only vs. write tools:** mutating or destructive operations (create/update/delete, shares, file writes) always require an explicit user confirmation in the chat before they are executed.
- **Background worker (CLI) limitation:** file tools (`list_files`, `create_file`, `create_note`, `create_folder`, `rename_file`, `delete_file`, `read_file`, `search_files`, `update_knowledge`) are not available in background `taskprocessing:worker` runs, because the user's file mount is not set up there. They work in the regular web chat.
- **Multi-step tasks:** Eva combines several tools in one task run (up to 4 tool rounds) until it can answer.

## Safe tool exposure

EVA can expose registered read-only tools such as file search/read, calendar and mail lookup, contacts lookup, shares/tasks listing, recent activity, server status, time, and weather according to the active surface. State-changing and destructive operations remain policy-controlled and require explicit confirmation on interactive surfaces; they are not enabled automatically by provider-label or layout changes. Live web search is tracked separately in issue #54.
