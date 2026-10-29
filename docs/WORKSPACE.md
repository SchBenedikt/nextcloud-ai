# Workspace settings

Only the selected section is rendered. Shared form state survives section
switches. Opening AI models does not fetch connector lists, extensions, API keys,
briefing history or personal knowledge.

| Section | Contents |
| --- | --- |
| AI models | Chat profiles and the Ollama connection |
| Documents | Retrieval quality, indexing scope, limits and exclusions |
| Tools & permissions | Actions, terminal permissions, Talk, notifications and web search |
| Automation | Briefing schedules, channels and history |
| Personal & data | Retention, personal knowledge, privacy and export |
| Advanced | Connectors, extensions, programmatic keys, diagnostics and usage |

## Saving

Core fields and account tokens stay as unsaved edits until you select **Save
changes**. The header shows whether changes are pending, saving, or confirmed.
Pending values survive navigation between EVA views. Leaving or reloading the
Nextcloud page prompts before discarding them.
Validation errors appear above the selected section. Provider profiles are
saved explicitly with their keys in the same request. Cancelling their dialog
does not activate the draft.
If Nextcloud rejects a value that passes the browser's basic checks, the
server's field-level reason is shown above the form so you can correct the
specific setting.

Credentials are encrypted separately per user. The browser only receives
presence flags for stored keys. Never put a key in a URL or profile name.

## Busy states

Indexing locks settings that would change the active job. Scheduled briefings
have a separate lock so their schedule can be managed without changing the
index configuration. Destructive operations still require confirmation.

## Advanced tools

Usage metrics and background tasks are reached through Advanced. Their direct
URLs continue to work. Diagnostics load when Advanced opens or when you request
a refresh, rather than on every settings visit.

See the [configuration reference](CONFIGURATION.md) for keys, defaults and limits.

## Native navigation and layout

The home screen contains a large message field and recent conversations.
Recent conversations use native Nextcloud dashboard list items. It reuses the chat list without requesting diagnostics.
Settings categories live in the app sidebar, replacing the secondary tab bar.
`/settings?section=knowledge` links directly to Documents; browser history
preserves section changes. Sections and provider rows use `NcSettingsSection`
and `NcListItem`, with theme colors and controls supplied by Nextcloud.

## Document library

Document filters use native Nextcloud controls. Search and pagination remain
bounded to 100 documents per page. Text previews load in pages of 200 chunks;
failed requests can be retried without discarding already loaded text. Refresh
clears expanded previews, so reindexed text is read again. The list revalidates
cached responses with its ETag rather than hiding updates for five minutes.
On mobile, secondary MIME/date columns are hidden while file names, sizes and
chunk counts stay visible.
