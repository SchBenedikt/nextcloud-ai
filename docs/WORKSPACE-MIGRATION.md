# Focused workspace migration

The workspace changes presentation and loading behavior while retaining stored
conversations, document indexes and existing integration routes.

## Moved controls

- Home starts conversations directly instead of showing a system dashboard.
- Exports and the prompt library moved into dialogs in Conversation actions.
- Settings are grouped by task. Advanced links usage metrics and background tasks.
- Profile dialogs replace provider JSON editing, using the same stored setting.
- Stale folder-management and drag handlers were removed from navigation.
  Folder metadata and routes remain available for existing clients.

## Provider compatibility

Existing profiles omit `auth` and keep API-key authentication. Local profiles
can explicitly use `none`. IDs `ollama` and `groq` remain reserved. Blank key
fields preserve an existing key; profile JSON does not contain credentials.

## Deployment checks

Publish the complete rebuilt `js/` directory with the source. Settings sections
add lazy chunks. Omitting them causes section-loading failures even if the main
bundle loads. See [Development](DEVELOPMENT.md).

After deployment, verify a new chat, existing conversation, provider setup,
document list, index progress and export against your real Nextcloud version,
provider and cron setup. UI grouping and the read-only progress route need no
database migration. Keep normal backups before production updates.
