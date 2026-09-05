# EVA — Architecture

High-level overview of how eva_ai is built and how data flows through it.

## Directory layout

```
appinfo/            App metadata (info.xml), routes.php
lib/
  AppInfo/          Application bootstrap (services, listeners, providers)
  BackgroundJob/    IndexJob — periodic background indexing
  Command/          occ commands (Index, Reset, Mounts, Tool, TalkSetup)
  Controller/       PageController (UI), ApiController (REST API)
  Db/               Entities + mappers (Document, Chunk)
  Listener/         TalkBotListener — reacts to Talk BotInvokeEvent
  Migration/        Database migrations (schema + repair)
  Notification/     Notifier — "AI answer ready" bell notifications
  Service/          Core services (see below)
  TaskProcessing/   13 providers for the Assistant app
```

## Core services (`lib/Service/`)

| Service | Responsibility |
|---|---|
| `Indexer` | Walks the user's files (generator-based), extracts text, calls `Chunker`, writes documents/chunks, reconciles deleted/modified files |
| `Chunker` | Splits extracted text into overlapping chunks |
| `Ollama` | Thin HTTP client for the Ollama API (embedding + chat) |
| `Searcher` | Hybrid retrieval: vector search + BM25, fused with RRF |
| `RagService` | Orchestrates retrieval + prompt building + answer generation |
| `ActionExecutor` | **Single chokepoint** for all tools — enforces `ToolPolicy` before executing; bounded name/content file search and protected knowledge trimming |
| `ToolPolicy` | Central tool registry: risk class, allowed surfaces, confirmation flags |
| `CalendarService` | CalDAV access: calendars, events, reminders, free slots |
| `SharesService` | File sharing: list/create/update/delete shares |
| `EmailService` | Mail app integration: search/list/read mails + email indexing |
| `FileContextChatService` | Chat strictly over selected files (Files app context menu) |
| `ChatStore` | Chat history persistence in Nextcloud AppData (`eva_ai/chats/<user namespace>/chats.json`) |
| `AgentStore` | Agent conversation state (`eva_ai_agent_store`) |
| `ActivityService` | Reads the activity feed (all apps) |
| `AppConfig` | Typed access to all app settings with defaults |
| `KnowledgeInitializer` | Idempotently adds the per-user first-run profile section to `KNOWLEDGE.md` without overwriting existing content |
| `TalkBotRegistrar` | Auto-registers the Talk bot on boot (idempotent) |
| `TalkContextReader` | Reads Talk conversation history for the bot |

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
| `talk` | `TalkBotListener::handle()` | ✅ (after confirmation) |
| `rag` | RAG pipeline | ❌ readonly only |
| `taskprocessing` | TaskProcessing providers (proposal phase) | ❌ readonly only; switches to `web` after user confirmation |

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

## Testing

- `tests/ToolPolicySecurityTest.php` — risk classes, surface isolation, prompt-injection rejection
- `tests/TaskProcessingContractTest.php` — provider IDs, task types, input/output shapes
- Bootstrap loads OCP interfaces when a Nextcloud installation is available and
  skips the contract tests gracefully otherwise (CI-safe).

## Current UI and tool boundaries

The workspace uses one responsive `--eva-content-width` token: it expands to `clamp(1180px, 78vw, 1680px)` on large displays and is constrained by the viewport on smaller screens. The native New chat action uses a block-level full width with Nextcloud's native wide modifier, matching the padded Documents and Settings navigation-item width directly below the chat search. Assistant providers keep stable IDs while using the display names `Eva · Local`, `Eva · RAG`, `Eva · Tools`, and `Eva · Agent`.

`search_files` performs a bounded VFS walk and searches names plus readable text-file content, returning snippets and a `truncated` flag when its node/depth/result limits are reached. `update_knowledge` protects the automatic profile block while trimming only old non-profile lines when the file exceeds its size limit.

The centralized tool policy exposes registered read-only tools to the safe RAG/TaskProcessing surfaces. File, calendar, contact, share, and task mutations remain restricted to interactive surfaces and require explicit confirmation where configured. Live web search is not implemented yet; see GitHub issue #54.

## Index cancellation

The Stop indexing action sets a durable per-user cancellation flag but does not clear the run token or terminal state from the HTTP request. The UI therefore reports `stopping` until the worker confirms termination. Embedding requests use a bounded read timeout; queued workers and active workers stop at their next cancellation boundary, discard staged replacement rows, and the worker's `finally` block alone records the real finish time and releases the run claim. A start requested during this state is represented by a follow-up queued job that waits for the old worker to release the claim before creating a new run.

## Index conflict recovery

A repeated start request for the same user is treated as an idempotent status response rather than a user-visible HTTP 409. A genuine worker-lock conflict remains a 409 with an actionable message. Request parameters use Nextcloud's native IRequest access first, with one non-recursive JSON-body fallback for POST payloads; the app does not use the old recursively named wrapper. Stale per-user index state is recovered after 15 minutes without a heartbeat; cancellation recovery remains faster. These responses are application concurrency handling, not Content Security Policy decisions.
