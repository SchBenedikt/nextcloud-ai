# EVA — Privacy & data lifecycle

EVA operates on your personal Nextcloud data. This document describes exactly
what data the app stores, where it is processed, how long it is retained and how
you can remove it.

## 1. What data does EVA store?

| Data Type | Storage Location | Retention |
|---|---|---|
| Indexed document metadata (file path, name, hash, MIME type, size) | Nextcloud database (`eva_ai_documents`) | Until the file is deleted from Nextcloud or the index is reset |
| Text chunks (split from indexed files) | Nextcloud database (`eva_ai_chunks`) | Same as parent document |
| Embedding vectors (numerical representations of chunks) | Nextcloud database (`eva_ai_chunks`) | Same as parent document |
| Indexed email metadata (subject, sender, body excerpt) | Nextcloud database (`eva_ai_documents`) | Until the email changes or the index is reset |
| Email chunks and embeddings | Nextcloud database (`eva_ai_chunks`) | Same as parent email document |
| Chat history (conversation messages) | Nextcloud AppData (`eva_ai/chats/<user namespace>/chats.json`) | Per user; retained until the user deletes it or app data is removed |
| AI-created file markers (tracking files EVA created) | Nextcloud app-data (`ai-marks/`) | Until the file is deleted or manually cleaned |
| Knowledge base entries (`KNOWLEDGE.md`) | User's Nextcloud home folder | Until the user deletes the file |
| Agent conversation state | Nextcloud database (`eva_ai_agent_store`) | Per conversation token; deleted on reset |
| App configuration | Nextcloud database (`oc_appconfig`) | Persistent until manually changed |

## 2. Where is data processed?

- **All processing is local**: file indexing, text extraction, chunking, embedding
  generation and LLM chat all happen on your own server.
- **Ollama** is the only AI component — it runs locally on your machine. No file
  contents, emails or personal data are ever sent to third-party services.
- **Weather queries** use the free Open-Meteo API (no API key; only the requested
  location is transmitted, no user data).

## 3. What happens when a file is modified or deleted?

- **File modified**: the next indexing pass detects the content hash change,
  re-chunks the file, generates new embeddings and replaces the old index entry.
- **File deleted from Nextcloud**: the next indexing pass detects the missing
  file ID and automatically removes the document and all its chunks from the index.
- **File becomes unreadable / non-indexable**: stale index entries are removed
  during reconciliation (the file ID appears in the filesystem but extraction fails).
- **Shared-file access revoked**: EVA only indexes files the user can currently
  read. Revoked shares are not indexed; previously indexed content from revoked
  shares is removed during the next indexing pass.
- **Email deleted**: the next email indexing pass skips deleted emails; old
  indexed email content is removed when the email entry is no longer found.

## 4. How to delete all EVA data

**Reset a single user's index:**

```bash
sudo -u www-data php occ eva_ai:reset --user=username
```

This removes the indexed documents and chunks for the specified user. It does not delete the user's saved chat history or original Nextcloud files.

Users can delete one conversation from the chat sidebar using its rename/delete menu, or permanently delete all saved EVA conversations from **Settings → Chat history**. These UI actions affect chat history only; they do not delete Nextcloud files or indexed documents.

**Reset ALL users:**

```bash
sudo -u www-data php occ eva_ai:reset --all
```

**Remove only the AI-created file markers:**

```bash
sudo -u www-data php occ eva_ai:reset --user=username --marks-only
```

**Uninstall the app completely:**

```bash
sudo -u www-data php occ app:remove eva_ai
```

This drops all database tables and removes the app-data folder.

## 5. Configuration defaults affecting privacy

| Setting | Default | Privacy Impact |
|---|---|---|
| `mail_index_enabled` | `1` (on) | Emails are indexed into RAG. Set to `0` to disable. |
| `actions_enabled` | `1` (on) | The AI can create/rename/delete files. Set to `0` for read-only chat. |
| `exec_delete_mode` | `own` | Only files EVA created itself can be deleted. Set to `off` to disable deletion entirely. |
| `exec_write_types` | `''` (all) | Restrict which file types EVA can create (e.g. `md,txt`). |
| `index_user` | `''` (current user) | Only this user's files are indexed. Leave empty for per-user indexing. |
| `scope_path` / `exclude_paths` | `''` | Limit or exclude folders from indexing. |
| `talk_history_size` | `50` | Number of chat messages sent to the Talk bot as context. |

## 6. Notes

- The index lives in the Nextcloud database (per-user rows), not in an external
  database or cloud service.
- Contacts and calendars are read from all address books/calendars the user can
  see (own, shared, group and Circles/Teams calendars); writes go to the user's
  personal books.
- The tools call your local Ollama; the app never sends file contents to third
  parties.
