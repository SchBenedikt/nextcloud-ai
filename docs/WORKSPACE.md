# Workspace settings

Only the selected section is rendered. Shared form state survives section
switches. Opening Models does not fetch connector lists, extensions, API keys,
briefing history or personal knowledge.

| Section | Contents |
| --- | --- |
| Models | Chat profiles and the Ollama connection |
| Documents | Retrieval quality, indexing scope, limits and exclusions |
| Tools & permissions | Actions, terminal permissions, Talk, notifications and web search |
| Automation | Briefing schedules, channels and history |
| Personal & data | Retention, personal knowledge, privacy and export |
| Advanced | Connectors, extensions, programmatic keys, diagnostics and usage |

## Saving

Core fields save automatically after editing. Validation errors appear above
the selected section. Provider profiles are saved explicitly with their keys
in the same request. Cancelling their dialog does not activate the draft.

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
