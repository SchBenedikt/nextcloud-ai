# EVA (eva_ai) — Private RAG assistant for Nextcloud

Chat with a **locally running** LLM (Ollama) about your own Nextcloud files. EVA
indexes your files, splits them into chunks, creates embeddings and makes them
searchable via **hybrid retrieval** (vector + BM25 with RRF fusion). Answers
always cite the source file path the model took the information from.

> 🔒 **Privacy by design:** everything runs on your own server — no external RAG
> service, no external database, no cloud, no API keys. Only Ollama is involved,
> and it runs locally too.

---

## Features

- **Local & private**: pure PHP, no external AI service; only a local Ollama instance
- **Hybrid retrieval**: vector search + lexical search (BM25), fused with RRF
- **Broad format support**: text (txt, md, code, csv, tsv, html, json, xml, yaml,
  toml, rtf, sql, …), PDF, Office (docx/xlsx/pptx incl. macro/template variants),
  OpenDocument (odt, ods, odp), EPUB and more
- **Chat tools** (toggleable, risk-classified — see [Security](docs/SECURITY.md)):
  - **Files**: list, create, rename, delete, read, search, notes, personal knowledge base (`KNOWLEDGE.md`)
  - **Contacts**: find, create, update, delete (own, shared, group and Circles address books)
  - **Calendar**: list calendars/events, create/update/delete events, reminders, free-slot search
  - **Mail** (if the Mail app is installed): search, list, read, unread count — plus optional indexing of emails into the RAG index
  - **Shares**: list (outgoing + incoming), create link/user/group shares with password, expiry and note; update and delete
  - **Tasks**: list, create, update, complete, delete (VTODO, all task-capable calendars)
  - **Profile**: read and update the own Nextcloud profile
  - **Utility**: activity feed, server status, current time (user timezone), weather (Open-Meteo)
- **TaskProcessing providers**: 13 providers for the Assistant app (chat, summary, headline, topics, translate, reformulate, proofread, reformat, change tone, context write, …)
- **Talk bot**: optional Nextcloud Talk integration — EVA answers in conversations
- **File-context chat**: right-click a file in the Files app → "Open with EVA"
- **Responsive chat and document UI**: fluid layouts for mobile and wide desktop screens, with explicit chunk loading, empty, error and retry states when inspecting indexed documents
- **Reliable indexing requests**: request parameters use Nextcloud's native access with a single non-recursive JSON fallback, preserving POST bodies while avoiding recursive input handling and memory failures; duplicate starts are idempotent while genuine worker-lock conflicts remain explicit 409 responses
- **Persistent settings**: folder exclusions are saved immediately, and connection checks use the configured HTTP/HTTPS Ollama endpoint for every user
- **Incremental document browsing**: large knowledge bases load in manageable pages with a "Load more" action and clear progress/end state
- **Unified workspace navigation**: a responsive shared content width that expands on large screens while remaining mobile-safe with an always-visible native chat search, a native primary New chat action using the native wide modifier and a block-level full navigation-item width matching Documents and Settings directly below the always-visible native chat search, with a standard comfortable touch target and reduced horizontal sidebar padding, a clean native three-dot rename/delete menu, and Markdown export
- **"AI answer ready" notifications** via the Notifications app
- **No configuration needed**: models come from the local Ollama instance

---

## Requirements

| Component | Requirement |
|---|---|
| Nextcloud | **30 – 35** (tested with 35) |
| PHP | ≥ 8.2 (module `curl` required, standard on Nextcloud installs) |
| Ollama | reachable from the web server, default `http://127.0.0.1:11434` |

Recommended models:

```bash
ollama pull gemma4:cloud            # chat model (default)
ollama pull nomic-embed-text:latest # embeddings (default)
```

---

## Installation

### 1. Install the app

The folder must be named `eva_ai` (app ID):

```bash
cd /var/www/nextcloud/apps
sudo git clone https://github.com/SchBenedikt/nextcloud-ai.git eva_ai
sudo chown -R www-data:www-data eva_ai
```

### 2. Enable the app

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:enable eva_ai
```

### 3. Prepare Ollama

```bash
ollama pull gemma4:cloud
ollama pull nomic-embed-text:latest
```

If Ollama runs on another machine or port:

```bash
sudo -u www-data php occ config:app:set eva_ai ollama_url --value=http://192.168.1.50:11434
```

### 4. First start & indexing

1. Open `/apps/eva_ai` as a user. On the first authenticated app request, EVA creates a small editable `KNOWLEDGE.md` profile section in that user's home from the Nextcloud user ID, display name and (when available) email. It never overwrites existing knowledge and does not import address, phone or biography automatically.
2. In **Settings** start the index with the **"Start indexing"** button. Starting an index explicitly enrolls your account in the recurring background schedule, even when the first pass finds zero documents. You can disable future scheduled passes with **Keep indexing this account in the background** in Settings. Per-user indexing uses the currently authenticated user. The optional instance-wide
   legacy background job can be limited with `index_user`. Each pass also
   indexes emails (subject, sender, body) if `mail_index_enabled` is on.
3. Then chat. Tools are enabled by default; every answer names which file it used.

### 5. TaskProcessing / Assistant (optional, recommended)

Use EVA from the Assistant text field (AI app in the menu). Enable the task type —
a worker cron processes the tasks (without a worker they stay "scheduled", HTTP 417):

```bash
sudo -u www-data php occ taskprocessing:task-type:set-enabled core:text2text:chat 1
```

Cron entry (`/etc/cron.d/eva_ai-taskprocessing`):

```
* * * * * www-data /usr/bin/php -d error_reporting=0 /var/www/nextcloud/occ taskprocessing:worker -t 60 -i 2 >/dev/null 2>&1
```

### 6. Notifications (optional)

Bell notification "AI answer ready" after finished answers:

```bash
sudo -u www-data php occ app:enable notifications
sudo -u www-data php occ config:app:set eva_ai notify_on_complete --value=1
```

### 7. Nextcloud Talk bot (optional, recommended)

If Talk (spreed) is installed, EVA registers itself as a bot automatically on every
app boot. Open a Talk conversation as admin and the bot appears in the bot list with
the name **"Eva"** — activate it per conversation in the conversation settings.

Explicit (re-)registration, e.g. after a manual DB cleanup:

```bash
sudo -u www-data php occ eva_ai:talk:setup                          # register/update (idempotent)
sudo -u www-data php occ eva_ai:talk:setup --remove                 # remove the bot
sudo -u www-data php occ eva_ai:talk:setup --name="EVA" --description="Local RAG assistant"
```

The bot answers based on the indexed documents of the user who added it, and it
receives the last `talk_history_size` messages as context.

### 8. File-context chat from the Files app (optional)

Right-click a file → **"Open with EVA"** (single file) or **"Chat about these N
files with EVA"** (multi-select). The chat answers strictly based on those files'
chunks — no global index lookup. The files must already be indexed.

---

## Upgrading from an older version

### From `eva-ai` to `eva_ai`

Do not delete the old app directory or its configuration first. The repair step
copies the legacy app and per-user settings to the new ID before removing the old
configuration namespace; the database tables remain available to the schema
migrations and chat data is migrated lazily when a user opens it.

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:disable eva-ai
cd /var/www/nextcloud/apps
sudo mv eva-ai eva_ai
sudo chown -R www-data:www-data eva_ai
cd /var/www/nextcloud
sudo -u www-data php occ app:enable eva_ai
sudo -u www-data php occ upgrade
sudo -u www-data php occ maintenance:repair
```

If the old directory is no longer present, copy the app into `eva_ai` instead of
removing the old database/configuration first. Make a database and `data/appdata_*`
backup before upgrading; restore those backups and re-enable the old app ID if a
rollback is required.

### Legacy index names

If you ran a version **before 1.4.0** (e.g. `ragchat` or early `eva-ai`), the database
may contain literal hyphenated index names such as `eva-ai_doc_user_file`. **MySQL,
MariaDB and PostgreSQL do not accept these names reliably**. The repair migration
renames them to `eva_ai_doc_user_file`, `eva_ai_doc_user` and `eva_ai_chunk_doc` and
is idempotent for already repaired installations.

```bash
cd /var/www/nextcloud
sudo -u www-data php occ upgrade
sudo -u www-data php occ maintenance:repair
```

Verify no obsolete indexes remain:

```sql
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='nextcloud' AND (INDEX_NAME LIKE 'eva-ai%' OR INDEX_NAME LIKE 'ragchat%');
-- expect 0 rows
```

---

## Configuration

Instance defaults live in `oc_appconfig`; settings changed in the app UI are stored
per user in Nextcloud's user configuration. Users cannot read or change another user's
settings. Defaults are shown in brackets.

| Setting | Default | Description |
|---|---|---|
| `ollama_url` | `http://127.0.0.1:11434` | Base URL of the Ollama instance |
| `chat_model` | `gemma4:cloud` | Chat/LLM model |
| `embedding_model` | `nomic-embed-text` | Embedding model |
| `temperature` | `0.1` | LLM creativity |
| `context_size` | `12288` | Context window length |
| `top_k` | `6` | Number of RAG hits retrieved |
| `chunk_size` | `900` | Text chunk size for indexing |
| `chunk_overlap` | `120` | Chunk overlap |
| `max_file_size` | `20971520` (20 MB) | Largest file to index |
| `max_files_per_run` | `40` | Files processed per indexing pass |
| `scope_path` | `''` | Only index files under this path (empty = whole home) |
| `exclude_paths` | `''` | Comma-separated path prefixes to skip |
| `index_user` | `''` | Optional instance-wide legacy background-job user; normal users cannot change it from Settings |
| `index_enabled` | `0` | Instance-wide legacy indexer switch; normal users cannot change it from Settings |
| `actions_enabled` | `1` | Enable chat tools (0 = read-only chat) |
| `exec_write_types` | `''` (all) | Restrict creatable file types, e.g. `md,txt` |
| `exec_write_max_chars` | `100000` | Max characters for AI-created files |
| `exec_delete_mode` | `own` | `own` = only files EVA created; `off` = deletion disabled |
| `mail_index_enabled` | `1` | Index emails into RAG (0 = off) |
| `mail_index_max` | `25` | Emails per indexing pass |
| `notify_on_complete` | `1` | "AI answer ready" notification |
| `talk_history_size` | `50` | Chat messages sent to the Talk bot as history |
| `talk_bot_trigger` | `Eva` | Bot trigger word |

Example:

```bash
sudo -u www-data php occ config:app:set eva_ai chat_model --value=gemma4:cloud
sudo -u www-data php occ config:app:set eva_ai top_k --value=6
sudo -u www-data php occ config:app:set eva_ai mail_index_enabled --value=0
```

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for details.

---

### Using the Settings page

The Settings page groups configuration into five areas: **Connection & models**, **Safety & actions**, **Search & answer quality**, **Indexing & scope**, and **Talk & notifications**. Changes are saved explicitly with **Save changes**; **Save & start indexing** saves first and will not start a run if saving fails.

Use **Check connection** to test the configured Ollama server, embedding model, and chat model. The indexing scope is relative to the user's Files root. The maximum file size is shown in MB in the UI and stored internally as bytes. EVA's recommended delete permission is **Only EVA-created files**; choosing **Any file in my Files** should only be done when that risk is understood. The **Chat history** section can permanently delete all saved EVA chats without touching your files or the document index; the chat sidebar refreshes immediately after deletion.

## Calendar tools: supported time formats

The chat interprets times in the user's timezone (e.g. `Europe/Berlin`):

- ISO with/without `Z`: `2026-08-20T16:00:00Z`, `2026-08-20 16:00` (a `Z` is read as local time for non-UTC zones)
- German formats: `20.08.2026 16:00`, `20/08/2026 16:00`
- Relative: `morgen 09:00` (tomorrow), `in 2 Tagen um 10:30`
- A plain date → all-day event
- Reminders via the `reminder_minutes` parameter (e.g. 30, 60)


## Data lifecycle & privacy

EVA operates on your personal Nextcloud data. A complete overview of what is
stored, where, and how to remove it lives in [docs/PRIVACY.md](docs/PRIVACY.md).

Quick reference:

| Data | Location | Retention |
|---|---|---|
| Document metadata | DB (`eva_ai_documents`) | Until file deleted or index reset |
| Text chunks & embeddings | DB (`eva_ai_chunks`) | Same as parent document |
| Indexed emails | DB (`eva_ai_documents`) | Until email changes or index reset |
| Chat history | AppData (`eva_ai/chats/<user namespace>/chats.json`) | Until the user deletes chats or app data is removed |
| AI-created file markers | app-data (`ai-marks/`) | Until file deleted or cleaned |
| Knowledge base (`KNOWLEDGE.md`), including the optional first-run profile section | User home | Until the user edits/deletes it or removes the file |
| First-run knowledge marker | Per-user Nextcloud user configuration | Until the app is reset/uninstalled or the user changes it |
| Agent conversation state | DB (`eva_ai_agent_store`) | Per conversation token; deleted on reset |
| App configuration | `oc_appconfig` | Persistent until changed |

**Reset a single user:**

```bash
sudo -u www-data php occ eva_ai:reset --user=username
```

**Reset all users:**

```bash
sudo -u www-data php occ eva_ai:reset --all
```

**Remove only AI-created file markers:**

```bash
sudo -u www-data php occ eva_ai:reset --user=username --marks-only
```

**Uninstall completely:**

```bash
sudo -u www-data php occ app:remove eva_ai
```

---

## Security model

All tools are classified by **risk** (readonly / mutating / destructive) and restricted
by **execution surface** (web chat, Talk, RAG, TaskProcessing). Mutating and destructive
tools always require explicit user confirmation. See [docs/SECURITY.md](docs/SECURITY.md).

---

## Commands

| Command | Purpose |
|---|---|
| `occ eva_ai:index [user]` | Index a user's files for RAG |
| `occ eva_ai:reset [--user] [--all] [--marks-only]` | Delete index data |
| `occ eva_ai:mounts` | List file mounts visible to a user (debug) |
| `occ eva_ai:tool` | Run a single EVA tool for a user (test) |
| `occ eva_ai:talk:setup [--remove] [--name] [--description]` | Register/remove the Talk bot |

---

## Development

Legacy configuration migration processes each user immediately during the Nextcloud user-manager traversal, keeping memory usage bounded on large installations.

- **Tests**: `composer test` (PHPUnit) — security, provider, settings and migration regressions
- **Frontend build**: `npm ci && npm run build`; CI also verifies that the expected generated bundles are emitted
- **CI**: GitHub Actions on PHP 8.2 / 8.3 / 8.4 (see [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)), dependency security audits (`quality.yml`) and nightly Nextcloud compatibility checks across all supported release lines (`nightly.yml`)
- **FAQ**: see [docs/FAQ.md](docs/FAQ.md) for setup, indexing and troubleshooting
- **Architecture**: see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

---

## License

AGPL-3.0-or-later — see [COPYING](COPYING).

*Bugs & feature requests:* https://github.com/SchBenedikt/nextcloud-ai/issues
