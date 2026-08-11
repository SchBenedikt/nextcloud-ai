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
| `ActionExecutor` | **Single chokepoint** for all tools — enforces `ToolPolicy` before executing |
| `ToolPolicy` | Central tool registry: risk class, allowed surfaces, confirmation flags |
| `CalendarService` | CalDAV access: calendars, events, reminders, free slots |
| `SharesService` | File sharing: list/create/update/delete shares |
| `EmailService` | Mail app integration: search/list/read mails + email indexing |
| `FileContextChatService` | Chat strictly over selected files (Files app context menu) |
| `ChatStore` | Chat history persistence (`eva_ai_chat_history`) |
| `AgentStore` | Agent conversation state (`eva_ai_agent_store`) |
| `ActivityService` | Reads the activity feed (all apps) |
| `AppConfig` | Typed access to all app settings with defaults |
| `TalkBotRegistrar` | Auto-registers the Talk bot on boot (idempotent) |
| `TalkContextReader` | Reads Talk conversation history for the bot |

## Data model (database)

| Table | Purpose |
|---|---|
| `eva_ai_documents` | Indexed files/emails: path, name, hash, mime, size, user |
| `eva_ai_chunks` | Text chunks + embedding vectors, linked to a document |
| `eva_ai_chat_history` | Per-user chat conversations/messages |
| `eva_ai_agent_store` | Agent (confirmation-flow) conversation state |
| `ai-marks/` (app-data) | Tracks files created by EVA (for safe deletion) |

Migrations in `lib/Migration/`:

- `Version100000…` — initial schema (documents, chunks, chat history)
- `Version101000…` — agent store / chat-history extensions
- `Version103000…` — schema refinements
- `Version104000…` — **repair migration**: renames hyphenated index names
  (`eva_ai_*` → `eva_ai_*`) and drops obsolete `ragchat_*` leftovers; required
  for MySQL/MariaDB/PostgreSQL compatibility

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
