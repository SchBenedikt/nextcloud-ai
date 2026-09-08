# EVA — Configuration reference

All settings are stored in `oc_appconfig` under the app ID `eva_ai`. Personal
settings can be overridden per user (from the app's **Settings** tab), but when
a user has no personal value they fall back to the admin-configured instance
value, and only if that is empty to the built-in default (Issue #73).

```bash
# Read one value
sudo -u www-data php occ config:app:get eva_ai <key>

# Set an instance-wide value (used by every user who did not choose their own)
sudo -u www-data php occ config:app:set eva_ai <key> --value=<value>

# Reset to the built-in default / clear a personal override:
# instance-wide: delete the app value
sudo -u www-data php occ config:app:delete eva_ai <key>
# per-user override: remove only that user's personal value
sudo -u www-data php occ config:user:delete <user> eva_ai <key>
```

Values are plain strings; booleans use `1`/`0`; sizes are stored in **bytes**
(the settings UI shows MB). Invalid values are rejected by the Settings form;
`occ` does not validate, so keep values inside the documented limits.

## Key reference

Legend: **P** = personal setting (per-user override possible),
**I** = instance-wide only, **S** = internal per-user runtime state,
**G** = global scheduler state.

### Connection & models

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `ollama_url` | P | `http://127.0.0.1:11434` | plain `http(s)://host[:port]`, no path/credentials | – | Base URL of the Ollama HTTP API; trailing slashes are stripped. |
| `chat_model` | P | `gemma4:cloud` | non-empty string | – | Model used for chat/generation. |
| `chat_model_fallback` | P | `''` | comma-separated model names | – | Models tried in order when the primary chat model is unavailable (Issue #86). |
| `embedding_model` | P | `nomic-embed-text` | non-empty string | – | Model used to embed chunks and queries. |
| `embedding_model_fallback` | P | `''` | comma-separated model names | – | Models tried in order when the primary embedding model is unavailable (Issue #86). |
| `summary_model` | P | `''` | model name or empty | – | Optional dedicated model for heavy text tasks (summarize, translate, proofread, …). Empty = the chat model chain is used (Issue #86). |
| `temperature` | P | `0.1` | `0`–`2` | – | Sampling temperature passed to Ollama (`options.temperature`). Lower = more deterministic. |
| `context_size` | P | `12288` | `256`–`131072` | tokens | Context window passed to Ollama (`options.num_ctx`). |

### Retrieval & indexing

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `top_k` | P | `6` | `1`–`8` | chunks | Number of RAG hits fed to the model per retrieval. |
| `chunk_size` | P | `900` | `128`–`10000` | characters | Target size of a text chunk. |
| `chunk_overlap` | P | `120` | `0`–`5000` | characters | Overlap between consecutive chunks. |
| `max_file_size` | P | `20971520` | `1048576`–`2147483648` | bytes | Files larger than this are skipped during indexing. |
| `max_files_per_run` | P | `40` | `1`–`10000` | files | Files processed per indexing pass (bounds job duration). |
| `embed_batch_size` | P | `24` | `1`–`200` | chunks | Chunks embedded per batch (bounds peak memory independent of library size). |
| `scope_path` | P | `''` | path, no `..` | – | Only index files below this path (e.g. `/Documents`). Empty = entire home. |
| `exclude_paths` | P | `''` | comma-separated paths, no `..` | – | Path prefixes to skip (e.g. `/.trash,/Photos`). |
| `index_user` | I | `''` | user id | – | Legacy instance-wide background-job user; not changeable from Settings. |
| `index_enabled` | I | `0` | `1`/`0` | – | Legacy instance-wide indexer switch. |
| `mail_index_enabled` | P | `1` | `1`/`0` | – | Index emails into RAG. |
| `mail_index_max` | P | `25` | `1`–`500` | emails/pass | Emails indexed per pass. |

Embedding vectors are cached in Nextcloud's distributed cache for up to 30 days
(user-isolated, content-derived keys; document text is never stored in the key).
Resetting a user's index clears that user's cached vectors.

### Chat tools / actions

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `actions_enabled` | P | `1` | `1`/`0` | – | `1` = chat tools enabled; `0` = read-only chat. |
| `exec_write_types` | P | `''` (all) | `*`, empty, or ≤32 extensions `md,txt,…` | – | Allowed extensions for AI-created files. |
| `exec_write_max_chars` | P | `100000` | `1`–`10000000` | characters | Maximum size of AI-created file contents. |
| `exec_delete_mode` | P | `own` | `off`/`own`/`all` | – | `own` = delete only EVA-created files; `all` = also user files (with confirmation); `off` = deletion disabled. |

### Notifications, Talk & privacy

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `notify_on_complete` | P | `1` | `1`/`0` | – | Send an "AI answer ready" notification (Notifications app). |
| `talk_history_size` | P | `50` | `1`–`500` | messages | Number of previous Talk messages sent as bot context. |
| `talk_bot_trigger` | P | `Eva` | non-empty string | – | Trigger word (with `@`) the Talk bot reacts to. |
| `talk_classify_all` | P | `0` | `1`/`0` | – | `0` = heuristic pre-filter decides before any LLM call (Issue #77, default); `1` = classify every room message via the LLM (legacy, higher cost/privacy exposure). |
| `weather_tool_enabled` | P | `1` | `1`/`0` | – | `0` disables the weather tool (external Open-Meteo requests) everywhere (Issue #69). |
| `index_enrolled` | P/S | `0` | `1`/`0` | – | Per-user opt-in for recurring background indexing. |
| `chat_retention_days` | P | `0` | `0`–`3650` | days | Automatically delete chats not used for this many days (`0` = keep everything). The daily `ChatCleanupJob` applies it per user. |

### Internal per-user runtime state (S)

Managed by the indexer; never user-facing configuration and never inherited
from an instance-wide value.

| Key | Meaning |
|---|---|
| `index_running` | `1` while a per-user index pass holds its claim. |
| `index_started` / `index_heartbeat` | Unix timestamps of claim start / last progress. |
| `index_finished` | `1` after the last completed pass. |
| `last_index_processed` / `last_index_total` | Progress counters of the last pass. |
| `last_index_error` | Message of the last failed pass. |
| `last_index_cache_hits` / `last_index_cache_misses` / `last_index_ollama_requests` | Embedding-cache and request counters of the last pass. |
| `index_config_hash` | Hash of the user's indexing settings; a change forces re-embedding. |
| `index_mode` | `idle`/`running`/`stopping` display state. |
| `index_cancel_requested` | `1` when the user asked to stop a running pass. |
| `index_run_id` | Unique id of the current/last pass. |
| `index_enrolled` | Opt-in for recurring background indexing (see above). |
| `knowledge_initialized` | `1` once the per-user `KNOWLEDGE.md` has been created. |

### Global scheduler state (G)

| Key | Meaning |
|---|---|
| `index_job_running` | `1` while a periodic `IndexJob` run is active. |
| `index_job_started` | Unix timestamp when the current run claimed the scheduler lock. |
| `index_job_max_seconds` | Wall-clock budget (seconds, default `50`) one periodic run may spend before the next cron tick continues (Issue #112). |
| `index_job_last_user` | Last user finished by a periodic run; the next run rotates past it for fairness (Issue #112). |
| `index_max_concurrent` | I | `2` | `1`–`16` | passes | Maximum index passes running concurrently across all users (Issue #142). Set via `occ config:app:set eva_ai index_max_concurrent …`. |
| `index_scheduler_active` | JSON map `user → heartbeat` of currently running index slots (default `{}`, Issue #142); stale slots are reclaimed after 15 minutes. |
| `index_scheduler_queue` | JSON FIFO list of users waiting behind the concurrency limit (default `[]`, Issue #142). |
| `index_job_stop_requested` | Durable stop request (default `0`) for the periodic background run: the running tick aborts at the next user boundary and the following tick acknowledges (clears) the flag without starting. Set via the admin dashboard “Stop background indexing” action. |

## Settings page

The web UI presents the configuration in groups: **Connection & models**,
**Safety & actions**, **Search & answer quality**, **Indexing & scope**, and
**Talk & notifications**. Use **Save changes** to persist the form;
**Save & start indexing** saves first and stops if saving fails. The UI shows
`max_file_size` in MB while the app stores bytes. Chat-history deletion is
separate from index deletion (deleting the index removes indexed documents and
vectors, not chats or original files).

## Reading the current configuration

```bash
sudo -u www-data php occ config:list apps --app=eva_ai
```

## Troubleshooting

- **First answer is slow** → the model may still be loading; `ollama pull <model>`
  or pre-warm with a short test query.
- **No answers / connection refused** → check `ollama_url` and reachability from
  the web-server user: `sudo -u www-data curl http://127.0.0.1:11434`.
- **Chat has no tools** → verify `actions_enabled=1`.
- **Talk bot does not appear** → run `occ eva_ai:talk:setup`, then activate the
  bot per conversation. If the bot stays silent on ordinary messages, check
  `talk_classify_all` (default `0` keeps human smalltalk away from the LLM).
