# EVA (eva-ai) — Local RAG assistant for Nextcloud

Chat with a locally running Large Language Model about your own Nextcloud files. The app indexes files, splits them into text chunks, creates embeddings and makes them searchable via RAG. Answers always name the source (file path).

- Pure PHP, no external RAG service, no external database
- Supported formats: text (txt, md, code, csv, tsv, html, json, xml, yaml, toml, rtf, sql, ...), PDF, Office (docx/xlsx/pptx incl. macro/template variants), OpenDocument (odt, ods, odp), EPUB and more
- Hybrid search: vector search + lexical search (BM25) with RRF fusion
- Tools in chat:
  - Files: list, create, rename, delete, read, search, notes, knowledge base (KNOWLEDGE.md)
  - Contacts: find, create, update, delete (own, shared, group, Circles address books)
  - Calendar: list calendars/events, create/update/delete events, reminders (local time formats)
  - Mail (Mail app installed): search, list, read, unread count — plus optional indexing of emails into the RAG index (toggle in Settings)
  - Shares: list (outgoing + incoming), create link/user/group shares with password, expiry and note, update and delete
  - Tasks/to-dos: list, create, update, complete, delete (VTODO, all task-capable calendars)
  - Activity feed of all apps, server status (version, PHP, DB, quotas, Ollama)
  - Weather (Open-Meteo); current time in the user's timezone
- TaskProcessing provider (IDs `eva-ai:text2text`), compatible with the Assistant app
- "AI answer ready" notification via the Notifications app
- No configuration needed: embedding and chat model come from the local Ollama instance

## Requirements

- Nextcloud 30–34 (tested with 34)
- PHP extension `curl` (standard on Nextcloud installations)
- Ollama, reachable from the web server. Default URL `http://127.0.0.1:11434` (no API key needed in a classic setup), with these models:
  - `gemma4:cloud` (chat, default)
  - `nomic-embed-text:latest` (embeddings)

## Installation

### 0. Upgrade an existing installation (important!)

If you ran a version **before 1.4.0** (e.g. `ragchat` or early `eva-ai`), the
database may contain index names with a hyphen (`eva-ai_doc_user_file`,
`eva-ai_chunk_doc`, …). **MySQL/MariaDB and PostgreSQL do not accept hyphens
in index names** — those indexes were never created, and the broken schema can
make the TaskProcessing/Assistant integration fail.

From 1.4.0 on, a repair migration fixes this automatically:

1. Deploy the new code:
   ```bash
   cd /var/www/nextcloud/apps
   sudo git pull        # (inside the eva-ai folder)
   sudo chown -R www-data:www-data eva-ai
   ```
2. Run the upgrade (executes the repair migration, renames/creates the
   indexes and drops obsolete `ragchat_*` leftovers):
   ```bash
   cd /var/www/nextcloud
   sudo -u www-data php occ upgrade
   ```
3. Verify no hyphenated/obsolete indexes are left:
   ```bash
   sudo -u www-data php occ maintenance:mimetype:update-db  # optional
   # or check via mysql: SELECT INDEX_NAME FROM information_schema.STATISTICS
   # WHERE TABLE_SCHEMA='nextcloud' AND (INDEX_NAME LIKE 'eva-ai%' OR INDEX_NAME LIKE 'ragchat%');
   -- expect 0 rows
   ```

### 1. Install the app

The folder must be named `eva-ai` (app ID):

```bash
cd /var/www/nextcloud/apps
sudo git clone https://github.com/SchBenedikt/nextcloud-ai.git eva-ai
sudo chown -R www-data:www-data eva-ai
```

### 2. Enable the app

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:enable eva-ai
```

### 3. Prepare Ollama

```bash
ollama pull gemma4:cloud
ollama pull nomic-embed-text:latest
```

If Ollama runs on another machine / port, e.g.:

```bash
sudo -u www-data php occ config:app:set eva-ai ollama_url --value=http://192.168.1.50:11434
```

Further settings (defaults in brackets) — optional:

```bash
sudo -u www-data php occ config:app:set eva-ai chat_model       --value=gemma4:cloud      # chat model (gemma4:cloud)
sudo -u www-data php occ config:app:set eva-ai embedding_model  --value=nomic-embed-text  # embeddings (nomic-embed-text)
sudo -u www-data php occ config:app:set eva-ai temperature      --value=0.1               # creativity (0.1)
sudo -u www-data php occ config:app:set eva-ai context_size     --value=12288             # context length (12288)
sudo -u www-data php occ config:app:set eva-ai top_k            --value=6                 # RAG hits (6)
sudo -u www-data php occ config:app:set eva-ai chunk_size       --value=900               # chunk size (900)
sudo -u www-data php occ config:app:set eva-ai chunk_overlap    --value=120               # overlap (120)
sudo -u www-data php occ config:app:set eva-ai index_enabled    --value=1                 # 0 = index disabled
sudo -u www-data php occ config:app:set eva-ai actions_enabled  --value=1                 # 0 = no chat tools
sudo -u www-data php occ config:app:set eva-ai mail_index_enabled --value=1                 # 0 = emails not indexed
sudo -u www-data php occ config:app:set eva-ai mail_index_max   --value=25                 # emails per indexing pass
```

### 4. TaskProcessing / Assistant (optional, recommended)

To use the app from the Assistant text field (AI app in the menu), enable the task type. A worker cron processes the tasks — without a worker they stay "scheduled" (HTTP 417 in the UI polling):

```bash
sudo -u www-data php occ taskprocessing:task-type:set-enabled core:text2text:chat 1
```

Cron file `/etc/default.d/eva-ai-taskprocessing`:

```
* * * * * www-data /usr/bin/php -d error_reporting=0 /var/www/nextcloud/occ taskprocessing:worker -t 60 -i 2 >/dev/null 2>&1
```

### 5. Notifications (optional)

Bell notification "AI answer ready" after finished answers:

```bash
sudo -u www-data php occ app:enable notifications
sudo -u www-data php occ config:app:set eva-ai notify_on_complete --value=1
```

### 6. First start & indexing

1. Open `/apps/eva-ai` as a user.
2. In Settings start the index with the "Start indexing" button (the user whose home is indexed is set via `index_user` — empty means the current user). Each pass also indexes emails (subject, sender, body) if enabled.
3. Then chat. Tools (files, contacts, calendar, mail, shares, tasks, weather, time) are enabled by default; every answer names which file it used.
3. Then chat. Tools (files, contacts, calendar, weather, time) are enabled by default; every answer names which file it used.

### 7. Nextcloud Talk bot (optional, recommended)

If the Nextcloud Talk (spreed) app is installed, EVA registers itself automatically as a chat bot on every app boot. You usually do **not** need to do anything — open a Talk conversation as admin and the bot will appear in the bot list (`/`) with the name "EVA AI".

If the bot does not show up (for example after a manual database cleanup), register or remove it explicitly:

```bash
# Register or update the bot (idempotent — safe to run multiple times)
sudo -u www-data php occ eva-ai:talk:setup

# Remove the bot again
sudo -u www-data php occ eva-ai:talk:setup --remove

# Customise name/description shown to admins
sudo -u www-data php occ eva-ai:talk:setup --name="EVA" --description="Local RAG assistant"
```

After registration, **activate the bot per conversation**:

- Talk admin UI → open the conversation → conversation settings → Bots → add "EVA AI".
- Or programmatically via the Talk OCS API: `POST /ocs/v2.php/apps/spreed/api/v1/room/{token}/bot/{botId}`.

Once active, EVA answers in that Talk conversation based on the indexed documents of the user who added it. The bot is sent the last 15 chat messages as history so it can respond to follow-up questions like "what did I just ask?".

### 8. File-context chat from the Files app (optional)

Right-click any file in the Files app → "Open with EVA" (single file) or "Chat about these N files with EVA" (multi-select). The selected files open the EVA app on the file-context view and the chat answers strictly based on those files' chunks — no global index lookup. Files must already be indexed for the bot to find chunks.

## Calendar tools: supported time formats

The chat interprets times in the user's timezone (e.g. Europe/Berlin):

- ISO with/without Z: `2026-08-20T16:00:00Z`, `2026-08-20 16:00` (a Z is read as local time for non-UTC zones)
- German formats: `20.08.2026 16:00`, `20/08/2026 16:00`
- Relative: `morgen 09:00` (tomorrow), `in 2 Tagen um 10:30`
- A plain date → all-day event
- Reminders via the `reminder_minutes` parameter (e.g. 30, 60)

## Data Lifecycle & Privacy

EVA operates on your personal Nextcloud data. This section documents what data is stored,
how long it is retained, and how to remove it.

### What data does EVA store?

| Data Type | Storage Location | Retention |
|---|---|---|
| Indexed document metadata (file path, name, hash, MIME type, size) | Nextcloud database (`eva_ai_documents`) | Until the file is deleted from Nextcloud or index is reset |
| Text chunks (split from indexed files) | Nextcloud database (`eva_ai_chunks`) | Same as parent document |
| Embedding vectors (numerical representations of chunks) | Nextcloud database (`eva_ai_chunks`) | Same as parent document |
| Indexed email metadata (subject, sender, body excerpt) | Nextcloud database (`eva_ai_documents`) | Until the email changes or index is reset |
| Email chunks and embeddings | Nextcloud database (`eva_ai_chunks`) | Same as parent email document |
| Chat history (conversation messages) | Nextcloud database (`eva_ai_chat_history`) | Stored per user; deleted on index reset |
| AI-created file markers (tracking which files EVA created) | Nextcloud app-data (`ai-marks/`) | Until the file is deleted or manually cleaned |
| Knowledge base entries (KNOWLEDGE.md) | User's Nextcloud home folder | Until the user deletes the file |
| Agent conversation state | Nextcloud database (`eva_ai_agent_store`) | Per conversation token; deleted on reset |
| App configuration | Nextcloud database (`oc_appconfig`) | Persistent until manually changed |

### Where is data processed?

- **All processing is local**: file indexing, text extraction, chunking, embedding generation,
  and LLM chat all happen on your own server.
- **Ollama** is the only external component — it runs locally on your machine.
  No file contents, emails, or personal data are ever sent to third-party services.
- **Weather queries** use the free Open-Meteo API (no API key, no user data sent).

### What happens when a file is modified or deleted?

- **File modified**: The next indexing pass detects the content hash change,
  re-chunks the file, generates new embeddings, and replaces the old index entry.
- **File deleted from Nextcloud**: The next indexing pass detects the missing file ID
  and automatically removes the document and all its chunks from the index.
- **File becomes unreadable/non-indexable**: Stale index entries are removed during
  reconciliation (the file ID appears in the filesystem but extraction fails).
- **Shared file access revoked**: EVA only indexes files the user can currently read.
  Revoked shares are not indexed; previously indexed content from revoked shares
  is removed during the next indexing pass.
- **Email deleted**: The next email indexing pass skips deleted emails. Old indexed
  email content is removed when the email entry is no longer found.

### How to delete all EVA data

**Reset a single user's index:**

```bash
sudo -u www-data php occ eva-ai:reset --user=username
```

This removes all documents, chunks, chat history, agent state, and AI-created file
markers for the specified user.

**Reset ALL users:**

```bash
sudo -u www-data php occ eva-ai:reset --all
```

**Remove the AI-created file markers only:**

```bash
sudo -u www-data php occ eva-ai:reset --user=username --marks-only
```

**Uninstall the app completely:**

```bash
sudo -u www-data php occ app:remove eva-ai
```

This drops all database tables and removes the app-data folder.

### Configuration defaults affecting privacy

| Setting | Default | Privacy Impact |
|---|---|---|
| `mail_index_enabled` | `1` (on) | Emails are indexed into RAG. Set to `0` to disable. |
| `actions_enabled` | `1` (on) | The AI can create/rename/delete files. Set to `0` for read-only chat. |
| `exec_delete_mode` | `own` | Only files EVA created itself can be deleted. Set to `off` to disable deletion entirely. |
| `exec_write_types` | `''` (all) | Restrict which file types EVA can create (e.g. `md,txt`). |
| `index_user` | `''` (current user) | Only this user's files are indexed. Leave empty for per-user indexing. |

## Notes

- The index lives in the Nextcloud app-data folder, not in an external database.
- Known dependency: Ollama must have the models loaded locally (`ollama pull`), otherwise the first answer is slow.
- Contacts and calendars are read from all address books / calendars the user can see (own, shared, group and Circles/Teams calendars); writes go to the user's personal books.
- The tools are online-calls to your local Ollama; the app never sends file contents to third parties.
