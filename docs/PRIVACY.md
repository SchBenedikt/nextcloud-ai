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
| Knowledge base entries (`KNOWLEDGE.md`), including the optional first-run identity section | User's Nextcloud home folder | Until the user edits/deletes the section or file |
| First-run knowledge marker | Per-user Nextcloud user configuration | Until the app is reset/uninstalled or the user changes it |
| Agent conversation state | Nextcloud database (`eva_ai_agent_store`) | Per conversation token; deleted on reset |
| App configuration | Nextcloud database (`oc_appconfig`) | Persistent until manually changed |
| Optional vector generation metadata and durable synchronization intent | Nextcloud database (`eva_ai_vec_generations`, `eva_ai_vec_outbox`) | Pending/failed erasure intent must survive until cleanup is confirmed; automatic journal pruning is not implemented yet |
| Optional storage targets and encrypted credentials | Nextcloud database (`eva_ai_vec_targets`) | Instance infrastructure configuration; retained destinations can be needed for outstanding cleanup and are not removed with one user's account |
| Vector ownership fence (purpose-separated owner hash, state, revision, timestamp) | Nextcloud database (`eva_ai_vec_owners`) | Open state while in use; blocked fingerprint intentionally retained after account erasure to prevent stale recreation; no automatic expiry/unblock exists yet |

## 2. Where is data processed?

- **First app start**: EVA may add only the user's Nextcloud ID, display name and non-empty email to that user's own `KNOWLEDGE.md`. Existing content is preserved; address, phone, biography and groups are not imported automatically.
- **Extraction and default storage**: EVA extracts/chunks files on the Nextcloud
  server and stores the current index in Nextcloud SQL. An external vector server
  is optional and disabled by default; the development stack does not yet enable
  chunk synchronization or external primary search.
- **Inference**: chat prompts, selected file excerpts and tool results go to the
  configured model provider. Ollama can run local models or cloud models; the
  default `gemma4:cloud` chat model is a cloud model even when the Ollama endpoint
  is localhost. Groq and OpenAI-compatible providers can also process data remotely.
  Embeddings currently use the configured Ollama embedding model/endpoint. Choose
  and verify local models if inference must remain on your infrastructure.
- **Integrations**: web search, connectors and other external tools contact their
  configured services when used. Weather queries send the requested location to
  Open-Meteo. Review provider and tool settings for your deployment rather than
  assuming an endpoint on localhost means all subsequent processing stays local.

The vector owner fingerprint is pseudonymous personal data, not an anonymous
record. It contains no account name, document text, embedding or credential, but
can still be linked by someone who knows the Nextcloud user ID. The personal data
export includes that user's `vector_owner_fence` metadata. A cleanup failure does
not reopen the blocked state; general cleanup retry/reconciliation and reviewed
retention for completed operational records remain unfinished work. See
[VECTOR-OUTBOX.md](VECTOR-OUTBOX.md) before enabling optional cleanup or storage.

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
### Chat history at rest

Chat history is stored in the app's per-user AppData namespace. New and
updated chat payloads are encrypted with Nextcloud's `ICrypto` service before
they are written to `chats.json`; the encryption key is managed by Nextcloud,
not by EVA. Existing installations with a legacy plaintext file remain
readable and are transparently migrated the next time that user's chats are
written. If Nextcloud's crypto service is unavailable, EVA keeps the existing
data intact and refuses to open an encrypted payload rather than falling back
to plaintext.
