## Settings page

The web UI presents the configuration in these groups:

- **Connection & models** — Ollama URL, embedding model, chat model, and a connection test.
- **Safety & actions** — file actions, write limits, delete permission, and completion notifications.
- **Search & answer quality** — source count, model context size, and temperature.
- **Indexing & scope** — folder scope, file/run limits, chunking, Mail indexing, and excluded folders.
- **Talk & notifications** — Talk history size and the trigger name.

Use **Save changes** to persist the form. **Save & start indexing** saves the settings first and stops if saving fails. The UI displays `max_file_size` in MB while the app stores it as bytes. The sidebar provides native chat search, a primary **New chat** action directly below it, and per-chat rename/delete controls. Chat-history deletion is separate from index deletion: deleting the index removes indexed documents and vectors, not chats or original files in Nextcloud.

# EVA — Configuration reference

All settings are stored in `oc_appconfig` under the app ID `eva_ai`. You can
change them via:

```bash
sudo -u www-data php occ config:app:set eva_ai <key> --value=<value>
```

or in the app's **Settings** tab. Values are plain strings; booleans use `1`/`0`.

## Ollama / model settings

| Key | Default | Description |
|---|---|---|
| `ollama_url` | `http://127.0.0.1:11434` | Base URL of the Ollama HTTP API. Trailing slashes are stripped. |
| `chat_model` | `gemma4:cloud` | Model used for chat/generation. |
| `embedding_model` | `nomic-embed-text` | Model used to embed chunks. |
| `temperature` | `0.1` | Sampling temperature (creativity). Lower = more deterministic. |
| `context_size` | `12288` | Context window size passed to Ollama (`num_ctx`). |

## Retrieval & indexing

| Key | Default | Description |
|---|---|---|
| `top_k` | `6` | Number of RAG hits fed to the model (per retrieval). |
| `chunk_size` | `900` | Target size of a text chunk (characters). |
| `chunk_overlap` | `120` | Overlap between consecutive chunks. |
| `max_file_size` | `20971520` (20 MB) | Files larger than this are skipped. |
| `max_files_per_run` | `40` | Files processed per indexing pass (protects against long jobs). |
| `scope_path` | `''` | Only index files below this path (e.g. `/Documents`). Empty = entire home. |
| `exclude_paths` | `''` | Comma-separated path prefixes to skip (e.g. `/.trash,/Photos`). |
| `index_user` | `''` | Optional instance-wide legacy background-job user; normal users cannot change it from Settings. |
| `index_enabled` | `0` | Instance-wide legacy indexer switch; per-user **Start indexing** does not change it. |
| `mail_index_enabled` | `1` | Index emails (subject, sender, body) into RAG. |
| `mail_index_max` | `25` | Emails indexed per pass (limits resource usage). |

## Chat tools / actions

| Key | Default | Description |
|---|---|---|
| `actions_enabled` | `1` | Enable chat tools. `0` = read-only chat (no tools). |
| `exec_write_types` | `''` (all) | Comma-separated allowed file types for AI-created files, e.g. `md,txt`. |
| `exec_write_max_chars` | `100000` | Maximum size of AI-created file contents. |
| `exec_delete_mode` | `own` | `own` = only delete files EVA created; `off` = deletion disabled entirely. |

## Notifications & Talk

| Key | Default | Description |
|---|---|---|
| `notify_on_complete` | `1` | Send "AI answer ready" notification (requires the Notifications app). |
| `talk_history_size` | `50` | Number of chat messages sent to the Talk bot as context. |
| `talk_bot_trigger` | `Eva` | Trigger word the Talk bot reacts to. |

## Privacy-relevant defaults at a glance

| Setting | Default | Privacy impact |
|---|---|---|
| `mail_index_enabled` | `1` | Emails are indexed. Set `0` to disable. |
| `actions_enabled` | `1` | Model may modify data (with confirmation). Set `0` for read-only. |
| `exec_delete_mode` | `own` | Only EVA-created files can be deleted. `off` disables deletion. |
| `exec_write_types` | `''` | Restrict creatable file types. |
| `index_user` | `''` | Optional instance-wide legacy background-job user; leave empty for per-user indexing. |

## Reading the current configuration

```bash
sudo -u www-data php occ config:list apps --app=eva_ai
```

## Troubleshooting

- **First answer is slow** → the model may still be loading; run
  `ollama pull <model>` beforehand, or pre-warm with a short test query.
- **No answers / connection refused** → check `ollama_url` and that Ollama is
  reachable from the web server user: `sudo -u www-data curl http://127.0.0.1:11434`.
- **Chat has no tools** → verify `actions_enabled=1` and that `index_enabled=1`
  (a RAG index is still required for file-grounded answers).
- **Talk bot does not appear** → run `occ eva_ai:talk:setup`, then activate the
  bot per conversation (Talk admin UI or OCS API).
