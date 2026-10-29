# EVA — Architecture

High-level overview of how eva_ai is built and how data flows through it.

# Encrypted SQL chat storage rollout

Starting with 1.18.27, EVA creates additive `eva_ai_chats`,
`eva_ai_chat_messages`, and `eva_ai_chat_migrations` tables. Chat metadata and
each message payload use Nextcloud `ICrypto` encryption with the existing
`EVA-CHAT-1` format marker. Database owner keys are SHA-256 hashes of the exact
Nextcloud user ID. Only structural IDs, timestamps, message counts, and
migration state are queryable; no plaintext title, role, or message index is
created.

The per-user `sql_read_enabled` flag defaults off. After the checkpointed import
and ciphertext fingerprint verification, an administrator can enable SQL reads
for a canary account. Enabled reads use a direct encrypted-message query for a
conversation and indexed metadata pages for the chat list. Content search still
decrypts messages in the owning account because messages are never plaintext
indexed.

With SQL reads disabled, ChatStore mutations mirror changed metadata and
message rows in one SQL transaction after the AppData write succeeds. With SQL
reads enabled, message-level SQL writes become primary and avoid rewriting the
AppData JSON snapshot; the encrypted AppData copy remains as the pre-cutover
rollback image. SQL errors surface instead of silently falling back to stale
AppData. Use the per-user deactivation command to export current SQL back to
AppData before deploying code without SQL support. Older application nodes do
not write SQL, so wait for deployment completion before enabling the flag.


## Directory layout

```
appinfo/            App metadata (info.xml), routes.php
lib/
  AppInfo/          Application bootstrap (services, listeners, providers)
  BackgroundJob/    IndexJob — periodic background indexing
  Command/          occ commands (Index, Reset, Mounts, Tool, TalkSetup)
  Controller/       PageController (UI), ApiController (REST API)
  Search/           Native Unified Search provider for the EVA file index
  Db/               Entities + mappers (Document, Chunk)
  Listener/         TalkBotListener — reacts to Talk BotInvokeEvent
  Migration/        Database migrations (schema + repair)
  Notification/     Notifier — "AI answer ready" bell notifications
  Service/          Core services (see below)
  TaskProcessing/   28 providers for the Assistant app
```

## Core services (`lib/Service/`)

| Service | Responsibility |
|---|---|
| `Indexer` | Walks native sources, delegates canonical text extraction, writes documents/chunks and reconciles deleted/modified sources |
| `IndexTextExtractor` | Shared file/blob parsers, native format detection and an optional bounded VFS stream read |
| `IndexDocumentText` | Canonical file, Mail envelope/attachment and Talk text shared by indexing and generation preparation |
| `Chunker` | Streams sections/sentences into overlapping chunks; generation plans use explicit immutable settings and a chunk-count bound |
| `GenerationBuildWorker` | Separate background keyset builder with lease/owner/source guards; commits chunks, outbox and progress together without changing SQL retrieval |
| `GenerationBuildService` | Shared native administrator/OCC control and secret-free progress reads; explicit start schedules work instead of calling a provider |
| `LocalTextProcess` | Argv-based PDF/Office conversion with runtime/output limits; no raw converter stderr reaches the user |
| `Ollama` | Thin HTTP client for the Ollama API (embedding + chat) |
| `Searcher` | SQL-first hybrid retrieval; explicitly opted-in Qdrant generations can run native BM25 + dense RRF, retain file/path and phrase signals, hydrate exact revisions from SQL, then diversify results |
| `RagService` | Orchestrates retrieval + prompt building + answer generation |
| `ActionExecutor` | **Single chokepoint** for all tools — enforces `ToolPolicy` before executing; bounded name/content file search and protected knowledge trimming |
| `ToolPolicy` | Central tool registry: risk class, allowed surfaces, confirmation flags |
| `CalendarService` | CalDAV access: calendars, events, reminders, free slots |
| `SharesService` | File sharing: list/create/update/delete shares; provider-aware direct lookup avoids a 500-entry scan cap |
| `EmailService` | Mail app integration: search/list/read mails + email indexing |
| `FileContextChatService` | Chat strictly over selected files (Files app context menu) |
| `ChatStore` | Chat history persistence in Nextcloud AppData (`eva_ai/chats/<user namespace>/chats.json`); payloads are encrypted with Nextcloud's `ICrypto` service when available, while existing plaintext stores are read for migration compatibility |
| `AgentStore` | Agent conversation state (`eva_ai_agent_store`) |
| `ActivityService` | Reads the activity feed (all apps) |
| `EvaSearchProvider` | Bounded Unified Search over indexed file names, paths and content; re-checks VFS access |
| `AppConfig` | Typed access to all app settings with defaults |
| `KnowledgeInitializer` | Idempotently adds the per-user first-run profile section to `KNOWLEDGE.md` without overwriting existing content |
| `TalkBotRegistrar` | Auto-registers the Talk bot on boot (idempotent) |
| `TalkContextReader` | Reads Talk conversation history for the bot |
| `TalkTranscriptService` | Room discovery, transcripts and room-scoped recall - see [TALK-INDEXING.md](TALK-INDEXING.md) |
| `BrowserRenderer` | Runs the headless browser for pages that need JavaScript - see [BROWSER-RENDERING.md](BROWSER-RENDERING.md) |

## Data model (database)

| Table | Purpose |
|---|---|
| `eva_ai_documents` | Indexed files/emails: path, name, hash, mime, size, user |
| `eva_ai_chunks` | Text chunks + embedding vectors, linked to a document |
| `eva_ai/chats/<user namespace>/chats.json` (AppData) | Per-user chat conversations/messages |
| `eva_ai_agent_store` | Agent (confirmation-flow) conversation state |
| `ai-marks/` (app-data) | Tracks files created by EVA (for safe deletion) |

Migrations in `lib/Migration/`:

- `Version100000…` — initial schema (documents, chunks, chat history)
- `Version101000…` — agent store / chat-history extensions
- `Version103000…` — schema refinements
- `Version104000…` — **repair migration**: renames hyphenated index names
  (legacy `eva-ai_*` / `ragchat_*` → `eva_ai_*`) and drops obsolete leftovers; required
  for MySQL/MariaDB/PostgreSQL compatibility

## Frontend navigation

The Vue application uses native Nextcloud navigation components. `NcAppNavigationSearch` filters the per-user chat list, and the primary `New chat` action is rendered directly below the search field. Each chat entry exposes native action-menu controls for rename and delete; these operations are scoped to the authenticated user and do not invoke LLM file tools.

## Data flow — first app start

On the first authenticated `status` or `settings` request, `KnowledgeInitializer` checks a per-user marker, reads only the current user's core Nextcloud identity, and appends an explicitly marked editable section to that user's `KNOWLEDGE.md` through the VFS. Existing content is preserved and the marker makes the operation idempotent.

## Data flow — persistent enrollment and stream cancellation

`IndexJob` combines persisted per-user enrollment with legacy/document discovery, so an empty or reset index does not remove a user from recurring processing. Users can opt out through their personal Settings. Streaming checks the client connection at the controller, RAG and Ollama layers; a disconnect ends the generator and releases the response body before another tool round can begin.

## Data flow — indexing

```
BackgroundJob (or "Start indexing" button)
        │
        ▼
Indexer::run(user)
        │  generator: walks files (no full tree in RAM)
        ▼
Text extraction (PDF, Office, ODT, EPUB, …) + email pass (if enabled)
        │
        ▼
Chunker → chunks (chunk_size / chunk_overlap)
        │
        ▼
Ollama embeddings (embedding_model)   ──┐
        │                               │
        ▼                               ▼
document + chunks (+ vectors)  →  eva_ai_documents / eva_ai_chunks
        │
        ▼
Reconciliation: remove stale entries for deleted/unreadable files
```

## Data flow — chat

```
Web chat UI / Talk bot / Assistant
        │
        ▼
RagService::ask()
        │
        ├─► Searcher: vector + BM25 → top_k hits (RRF fusion)
        │
        ▼
Prompt built (system prompt + tool definitions + retrieved chunks, with sources)
        │
        ▼
Ollama chat (chat_model)
        │
        ▼
Tool calls? ──► ActionExecutor ──► ToolPolicy::check()
                (confirm if mutating/destructive, surface-aware)
        │
        ▼
Answer streamed back (names source files)
```

## Execution surfaces

The same chat pipeline runs in four contexts, and `ToolPolicy` restricts which
tools are allowed in each:

| Surface | Set by | Mutating tools |
|---|---|---|
| `web` | default | ✅ (after confirmation) |
| `talk` | `TalkBotListener::handle()` | ❌ readonly only |
| `rag` | RAG pipeline | ❌ readonly only |
| `taskprocessing` | TaskProcessing providers (proposal phase) | ❌ readonly only |
| `taskprocessing_confirmed` | Native Assistant confirmation phase | ✅ only the confirmed action |

## API routes (`appinfo/routes.php`)

- `GET  /`  `/app`  `/standalone` — page routes
- `GET  /api/status` — app + Ollama status
- `GET/PUT /api/settings` — read/update settings
- `POST /api/index` — start indexing
- `POST /api/indexReset` — reset index
- `GET  /api/documents`, `POST /api/documentChunks` — index inspection
- `POST /api/chat`, `GET/POST/DELETE /api/chats…` — chat + history
- `POST /api/streamChat` — streaming chat
- `POST /api/fileContextChat`, `/api/fileContextStatus` — file-context chat
- `POST /api/check` — connectivity check
- `GET  /api/models` — available Ollama models

Calendar tool output normalizes timed event timestamps to UTC before adding the `Z` suffix; all-day events remain date-only. Share mutations resolve a provider-aware share ID through Nextcloud's share manager and verify that the authenticated user owns the outgoing share.

## Testing

- `tests/ToolPolicySecurityTest.php` — risk classes, surface isolation, prompt-injection rejection
- `tests/TaskProcessingContractTest.php` — provider IDs, task types, input/output shapes
- Bootstrap loads OCP interfaces when a Nextcloud installation is available and
  skips the contract tests gracefully otherwise (CI-safe).

## Current UI and tool boundaries

The workspace uses one responsive `--eva-content-width` token: it expands to `clamp(1180px, 78vw, 1680px)` on large displays and is constrained by the viewport on smaller screens. The native New chat action uses a block-level full width with Nextcloud's native wide modifier, matching the padded Documents and Settings navigation-item width directly below the chat search. Assistant providers keep stable IDs while using the display names `Eva · Local`, `Eva · RAG`, `Eva · Tools`, and `Eva · Agent`.

`search_files` performs a bounded VFS walk and searches names plus readable text-file content, returning snippets and a `truncated` flag when its node/depth/result limits are reached. `update_knowledge` protects the automatic profile block while trimming only old non-profile lines when the file exceeds its size limit.

The centralized tool policy exposes registered read-only tools to the safe RAG/TaskProcessing surfaces. File, calendar, contact, share, and task mutations remain restricted to interactive surfaces and require explicit confirmation where configured. Web search is an explicit per-user opt-in: EVA can use the configured no-key HTML/RSS providers, self-hosted SearxNG, or admin-configured hosted providers. Retrieved pages are bounded, ranked, and exposed as external cited sources; if a provider blocks server traffic, EVA reports that limitation instead of pretending the search succeeded.

## Index cancellation

The Stop indexing action sets a durable per-user cancellation flag but does not clear the run token or terminal state from the HTTP request. The UI therefore reports `stopping` until the worker confirms termination. Embedding requests use a bounded read timeout; queued workers and active workers stop at their next cancellation boundary, discard staged replacement rows, and the worker's `finally` block alone records the real finish time and releases the run claim. A start requested during this state is represented by a follow-up queued job that waits for the old worker to release the claim before creating a new run.

## Index conflict recovery

A repeated start request for the same user is treated as an idempotent status response rather than a user-visible HTTP 409. A genuine worker-lock conflict remains a 409 with an actionable message. Request parameters use Nextcloud's native IRequest access first, with one non-recursive JSON-body fallback for POST payloads; the app does not use the old recursively named wrapper. Stale per-user index state is recovered after 15 minutes without a heartbeat; cancellation recovery remains faster. These responses are application concurrency handling, not Content Security Policy decisions.

## Focused workspace

`App.vue` owns the shared conversation list. `HomeView` receives it as props
and emits the first prompt through the normal chat-creation path. It makes
no independent stats or greeting requests. Answer typography is shared in
`src/lib/markdown.css`; file-context replies use the same safe Markdown renderer. `ChatView` owns the lifetime of the
shared renderer; `ChatActions` supplies native actions and modal dialogs.

`SettingsView` is the shell for asynchronously loaded components under
`src/components/settings/`. `useEvaSettings` owns shared form state and starts
optional resource loaders when their section is selected. Styles are scoped
under `.settings-view` in `src/styles/settings.scss`.

`SystemController::indexStatus()` returns `IndexStatusResponse` without building
full RAG status. Provider profiles use the existing settings route and encrypted
`ProviderCredentials` storage; authentication defaults to `api_key` for legacy
profiles. See [Performance](PERFORMANCE.md) and [Migration](WORKSPACE-MIGRATION.md).

## Settings boundaries

`SettingsUpdateRequest` decodes the wire format and restricts allowed fields.
`SettingsCredentialsRequest` validates all secrets before credential writes.
`ModelSelectionValidator` checks capabilities against the proposed endpoint
without temporarily persisting it. `SettingsPersistence` normalizes the full
update before writing values. Provider profile JSON is decoded once and stored
as the validated list, preserving profiles across save/reload.

Frontend settings coordinate independent briefing, integration and personal
knowledge composables. Optional resources load only when their section opens.
The shared form stays mounted across sections; save responses preserve edits
made after a request began and queue outstanding changes.

## Embedding dependency boundary

`Indexer` and `Searcher` depend on `EmbeddingProvider`, bound by Nextcloud's
bootstrap service alias to the existing shared `Ollama` service. Chat orchestration
continues to use its existing provider path. The contract preserves ordered batch
results, rejected-input null slots, error tuples, query embedding and per-batch
cache counters. This stage changes no endpoint, model, cache identity or retrieval
ranking. Constructor parameter names remain compatible with existing callers.

A new embedding adapter must pin the same embedding space for ingestion and query
vectors. Provider selection and vector-generation metadata are subsequent migration
stages; this interface alone does not safely enable switching embedding models on
an existing index. The historical `ollama_requests` status counter remains Ollama
specific and must not be incremented by a future hosted-provider adapter.
