# Changelog

All notable changes to **EVA (eva_ai)** are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Optional Groq chat with encrypted per-user API keys, GPT-OSS 20B/120B selection,
  streaming and tool calls, explicit quota errors and no automatic model fallback.
- Optional bounded Tesseract OCR for images and scanned PDFs (#84).
- Chunk provenance for headings, pages, slides and sheets; migration in 1.4.8 (#147).
- The chat header now shows the real conversation title (restored from storage or
  auto-derived from the first question) instead of a static placeholder, and
  untitled chats display a translated "New chat" label instead of a hardcoded
  German default that leaked into the English UI.
- User messages get a copy button in the web and standalone chat, matching the
  assistant answers.
- `occ eva_ai:repair-chats <user>` inspects a corrupt chat store and - after
  explicit `--yes` - backs the damaged file up and reconstructs a minimal valid
  store that keeps every parseable chat (#184).
- A pending tool confirmation in the web and standalone chat is persisted with
  the assistant message, so a reload rebuilds the inline panel instead of
  leaving a dangling question; approving after a reload replaces the placeholder
  (no duplicate answer) and a claim token prevents the same action from running
  twice (#185).
- Ownership markers are written before the filesystem action they record and
  finalized with the resulting node afterwards, so an interruption between the
  two leaves a pending record that the next ownership check reconciles into a
  grant instead of a silently missing one (#183).
- Browser tests exercise the standalone chat end-to-end against a mocked API:
  streaming render + ordered persistence, the confirmation panel with its
  idempotency token, duplicate-approve rejection after a reload, and the stop
  button (abort + partial-answer persistence) (#133).
- The RAG quality baseline is documented with current German/English numbers,
  methodology and CI thresholds in `docs/RAG-QUALITY-BASELINE.md` (#146).
- Controlled-backend tests cover the Ollama streaming client: malformed lines,
  tool-call arguments split across chunks, UTF-8 split at read boundaries and
  connection errors, all deterministic without a live server (#134).

### Changed

- Streaming no longer yanks the scroll position: while an answer streams, the
  chat only follows the bottom when the user is already near it, so reading
  earlier context is not interrupted (web and standalone chat).
- Regenerate/edit no longer truncates the stored history before the model
  responds: the truncation and the optional user-text edit are committed
  atomically with the persisted answer, so a failed model call or a reload
  during regeneration never destroys the previous answer (#182). Regenerate
  requests validate against the chat's revision, so two tabs editing the same
  chat cannot silently overwrite each other - the stale tab gets an inline
  "modified in another tab" notice instead.

### Fixed

- Chat storage failures now distinguish a corrupt store (actionable message
  pointing to `occ eva_ai:repair-chats`) from generic persistence errors (#184).
- Use a shared Markdown parser for tables, nested lists/emphasis, safe links and
  streamed code fences, with matching chat styles and desktop/mobile browser checks.
- Run Groq contracts against real Nextcloud interfaces in the PHP 8.2–8.4 CI matrix.

- Bound Groq request history, avoid background greeting/follow-up API calls, and
  report actual token limits/retry timing instead of a generic Free Plan error.

- Atomically claim confirmed actions before execution and retain durable receipts
  so concurrent confirmations cannot execute the same proposal twice (#179).


### Fixed (September 8 review)

- Correct regenerate/edit streaming responses and reject invalid message targets before changing history (#170).
- Bound citation ranges and preserve first-mention order (#171); fix fenced-code boundaries and literal inline code/URLs (#172).
- Preserve corrupt chat/folder storage instead of overwriting it, and serialize getChat reads (#173).
- Keep archived/customized/scoped empty chats separate from New chat (#174).
- Share a UTF-8-safe NDJSON reader between chat frontends, including final unterminated events and explicit parse errors (#175).
- Serialize ownership-marker reads and updates with Nextcloud's shared locking provider (#176).
- Use the longstanding callback response API for streaming on supported Nextcloud versions (#177).
- Align frontend metadata with app version 1.4.7 and derive webpack's version from package metadata (#178).
- Add frontend behavioral tests to npm test and CI. See docs/REVIEW-2026-09-08.md for validation and remaining limitations, including native confirmation concurrency (#179).

### Added (1.4.7)

- **Provider capability layer (Issues #86/#148/#151):** EVA now reads each
  installed Ollama model's declared capabilities from `/api/tags` instead of
  classifying models by their names alone. Older Ollama servers without the
  capabilities field fall back to a conservative name/family heuristic that
  is explicitly marked as such.
- **Model fallback chains (Issue #86):** new per-user settings
  `chat_model_fallback` and `embedding_model_fallback` (comma-separated).
  When the primary model is not installed, EVA resolves the first installed
  candidate with the matching capability; if no candidate is usable it fails
  with a clear error that lists what is actually installed. A dedicated
  optional `summary_model` lets heavy text tasks (summarize, translate,
  proofread, …) use a separate model while chat keeps the lightweight one.
- **Versioned provider snapshot (Issue #151):** the status endpoint now
  reports `provider` with version, online state, snapshot age, request
  latency, capability availability, per-model roles and the resolved model
  including whether a fallback was used - all metadata, never content.
- **Capability-aware settings (Issue #148):** the model picker separates
  embedding and chat models by provider capability, shows installed status
  next to the configured model, explains fallback usage, and rejects a
  role-mismatched model selection before it is saved.
- Index config hashes include the embedding fallback chain so a changed
  fallback re-indexes automatically.

### Changed
- **Issue #143:** retrieval ranking is now deterministic for identical inputs and bounds repeated chunks per document (diversity cap), so one document cannot crowd out all other relevant evidence while a single-document query still fills the full window.
- **Issue #77:** the Talk bot no longer sends every room message to the LLM for classification. A deterministic heuristic pre-filter (bot name/trigger, question mark, assistant-directed phrasing) decides first; only plausibly-addressed messages reach the classifier. New `talk_classify_all` setting (default `0`) restores the legacy classify-everything behaviour.
- **Issue #112:** the periodic `IndexJob` now has a bounded wall-clock budget (`index_job_max_seconds`, default 50) and continues round-robin from the last processed user (`index_job_last_user`), so one slow user can no longer starve every other user's index pass.
- **Issue #118:** `docs/CONFIGURATION.md` is now a complete configuration reference (every AppConfig key with scope, default, limits, unit and `occ` examples) guarded by a documentation-sync unit test.
- **Issue #150:** mutating/destructive tool calls now write a per-user, privacy-conscious action history (time, surface, tool, sanitized parameters, outcome) to AppData; passwords, tokens, message bodies and other sensitive values are redacted. Users can inspect and clear their history in Settings.
- **Issue #83:** GDPR support: a **Download my data** action in Settings exports chats, personal knowledge and index metadata as JSON, and a `UserDeletedEvent` listener removes every eva_ai row, AppData folder, KNOWLEDGE.md and per-user config when an account is deleted.
- **Issue #91/#140:** inspecting a document's chunks is now bounded and paginated (`limit`/`offset` on `documentChunks`); the Documents view loads large documents in pages with a "Load more chunks" button instead of one huge response.
- **Issue #69:** the weather tool (external Open-Meteo API calls) can be disabled via a new **Allow weather forecasts** setting in Settings, keeping all tool traffic on your own server.
- **Web chat:** complete, explicit tool calls (shares, calendar events, tasks, files, contacts, profile, knowledge) now execute directly without a confirmation dialog; the dialog is only shown when required data is missing or ambiguous. Missing fields are highlighted right away, the prompt instructs the model to never invent missing values, and the dialog copy now explains what is missing. Newly created shares still show the copyable-link chip after direct execution.
- **Issues #60/#66:** the indexer extracts the complete content of office documents instead of partial sections. DOCX now includes headers, footers, footnotes, endnotes, comments and tables; XLSX covers shared and inline strings sheet by sheet with cell references and sheet names; PPTX adds speaker notes and numbers each slide; ODS sheet names are preserved as boundary markers. Legacy binary `.doc`/`.xls`/`.ppt` files are converted via LibreOffice headless when installed (otherwise skipped with a logged reason, never silent).

### Added
- Confirmation requests in the web chat now render a native, editable form for every tool that requires confirmation (shares, calendar events, tasks, files, notes, contacts, profile and knowledge) instead of raw JSON; destructive actions get a red warning style, unknown tools still fall back to readable JSON, and successful share creations show a copyable link chip.
- **Issue #145:** user-isolated, content-addressed embedding cache with 30-day bounded retention, model/endpoint/schema metadata validation, duplicate-miss coalescing, reset cleanup, and index-status hit/miss/request counters.
- **Issue #109:** English and German translation bundles for the Vue workspace, file actions and standalone chat.
- Opt-in single-process frontend build for memory-constrained hosts (`EVA_LOW_MEMORY_BUILD=1`).
- **Issue #99:** calendar event listing and free-slot detection now expand recurring events with bounded support for RRULE, RDATE, EXDATE, and RECURRENCE-ID.

### Changed
- **Issue #63:** file-context chat now budgets the document excerpts against the configured model context instead of truncating every document at a fixed 12000 characters. A single large document may use most of the window; several documents share the budget fairly (unused share of short documents is redistributed).
- **Issue #96:** chat conversations keep up to 1000 messages (was 200) and trimming is no longer silent: every dropped message is counted on the chat and the chat view shows a notice that the oldest messages were trimmed.
- **Issue #152:** the chat list search now also finds chats by their message content, not only titles. Content hits show an excerpt around the first match and the number of matching messages.

### Fixed
- Indexing can no longer be permanently blocked by the per-user index lock. Two related defects are fixed: the lock key is now bounded to 40 hex chars (the full sha256 exceeded the varchar(64) key column of Nextcloud's file_locks table, so acquire/release silently failed and stale rows collided with every later attempt), and when a worker still crashes while holding the lock, the queue endpoint and the indexer reclaim the expired row once the tracked run state is idle or stale - never while a live worker with a fresh heartbeat is running. Previously such a row blocked "Indexing could not be queued" forever on instances whose cron never ran the file-lock cleanup job.
- **Issue #62:** the chunker no longer runs `array_unique()` on its output, so genuinely repeated passages and overlap-induced duplicate chunks stay in the index; `chunk_index` stays sequential and the stored `chunk_count` always matches the number of written chunks.
- **Issue #73:** a personal setting now falls back to the admin-configured instance value (`occ config:app:set eva_ai …`) when the user has no explicit personal value; explicit choices still win, and per-user runtime state never inherits instance values.
- **Issue #92:** non-streaming chat calls are bounded (default 120 s total with an idle read timeout) instead of holding a PHP-FPM/TaskProcessing worker for up to 600 s; TaskProcessing generation providers now stream internally and report progress while generating, and timeouts surface as clear errors.
- **Issue #61:** once an index exceeds the candidate pool, the dense (semantic) candidate set is built by scanning the entire index in fixed pages and keeping the best cosine scores - no more query-derived random window that silently skipped semantic-only matches.
- **Issue #74:** the Documents summary (`totalChunks`, `totalSize`, `total`) is computed from full-index SQL aggregates with the same search filter, so paging with "Load more" no longer changes the totals.
- **Issue #78:** chat mutations are serialized through Nextcloud's shared locking provider (oc_lock) instead of a node-local `flock()` file, so concurrent writes cannot race on clustered multi-node deployments.
- **Issue #113:** the activity tool only queries when the Activity app is enabled for the user, uses a defensive stable read instead of a hard-coded column list, no longer hides the app's own entries, and formats timestamps in the requesting user's timezone.
- **Issues #115, #97 and #76:** validate and normalize `exec_write_types` before saving, log and propagate Mail database failures instead of treating them as empty mailboxes, and reuse one short-lived Ollama `/api/tags` status result for repeated UI polls.
- **Issue #104:** calendar event listing and free-slot searches now use CalDAV time-range queries instead of loading every object from every selected calendar.
- **Issue #132:** bind EVA-created file grants to file IDs; reject legacy path grants, prune stale IDs, preserve grants across renames, and do not claim existing files or folders.
- **Issue #72:** move personal knowledge out of privileged system prompts and mark retrieved context as untrusted data.
- Persist native confirmation results before optional model summaries; failed summaries retain truthful results without leaving completed actions pending.
- Native Assistant proposal tools and confirmed execution use separate policy surfaces; plain Assistant chat remains read-only.
- Enforce per-document context limits with interleaved chunks, bound mail searches and escape literal search wildcards, and initialize the authenticated user for model lookup.
- Generate file-action links through the Nextcloud router for subdirectory installations and load translations in the Files entry point.
- Align the build environment with the Node.js 24 requirement of `@nextcloud/files`.
- Restore standalone empty-chat state and connect its Markdown export button.
- Remove obsolete reflection calls from tests for PHP 8.5 compatibility.
- **Issue #71**: `RagService` now receives a `LoggerInterface` through
  constructor injection, so the access-revocation purge path no longer
  throws an undefined-property error that was silently swallowed by the
  surrounding `try`/`catch`; the audit log entry is now actually written.
- **Issues #108, #114 and #117**: Ollama URLs reject credentials/query/fragment components individually, connection checks stop after an unreachable server or missing model instead of waiting through multi-minute model timeouts, and chat pairs are persisted strictly as question then answer.
- **Issues #95, #100 and #103**: Talk blocks sensitive profile/share/server-status tools, existing share listings redact link capability secrets and URLs, and TaskProcessing only injects explicitly supplied Talk rooms with caps of three rooms and 20 messages per room.
- **Issues #102, #105 and #98**: the `chatwithtools` TaskProcessing provider now ignores caller-supplied tool definitions and keeps the application-owned policy prompt, abandoned agent state is pruned after 30 days, and a tool-only streaming round completes with a truthful summary instead of a false no-text error.
- **Issues #67 and #68**: mutating and destructive model tool calls now stop at a central confirmation gate; the web chat displays the exact pending call with Confirm/Cancel controls and executes it only through the authenticated confirmation endpoint. Nextcloud Talk is read-only, so room members cannot create, modify or delete files, shares, contacts, calendar events or tasks.
- **Issues #65 and #94:** stopping an index run now leaves cancellation and terminal state ownership with the worker, so the UI shows `stopping` instead of a false completion; embedding requests have bounded read timeouts, staged replacement rows are discarded when cancellation arrives, and a restart requested during stopping is queued behind the old worker.
- **Issue #70:** `search_files` now searches bounded readable text content as well as names, returns short content snippets, reports when traversal/result limits were reached, and skips binary or oversized files without aborting the search.
- **Issue #93:** Knowledge-base trimming now removes only the oldest non-profile lines, preserves the automatic first-run identity section, logs the trim, and reports the warning in the tool result.
- **Issues #101 and #106:** calendar event timestamps returned by `list_calendar_events` are now converted to UTC before the `Z` suffix is emitted, and share updates/deletes resolve the provider-aware share ID directly instead of scanning only the first 500 shares of each type.
- **Issue #110:** large queued indexing jobs now enqueue a continuation after the bounded pass budget instead of stopping with a misleading safety-limit error.
- **Issue #111:** legacy app-ID migration now copies each user's settings during the user-manager callback instead of collecting every UID in memory first.
- **Issue #116:** legacy `ragchat_*` tables are now explicitly removed when their current `eva_ai_*` counterparts already exist after a partial upgrade.
- **Indexing API**: use native Nextcloud request parameters with a single non-recursive JSON fallback instead of a recursively named input wrapper; duplicate starts now remain idempotent while genuine worker-lock conflicts still return an actionable 409.
- **Frontend**: the main Vue bundle is now emitted as `eva_ai-main.js` (was
  `eva-ai-main.js`), matching the name the page controller looks up. The app
  page no longer renders as an empty shell after the app-ID migration.
- **Issue #16**: calendar/task defaults now use the user's timezone and the
  weekday calculation is correct on Sundays (was off by one for `date('w')`).
- **Issue #15**: deleted emails are removed from the RAG index during
  reconciliation instead of lingering in retrieval results.
- **Issue #14**: every indexed document is re-checked for read access before
  its chunks are returned to the model; stale documents are purged.
- **Issue #13**: dense (semantic) candidates are no longer gated behind the
  keyword `LIKE` filter, preserving retrieval quality on large indexes.
- **Issue #11**: writes to shared calendars and address books are rejected
  unless the user holds an explicit write grant (DAV permission checks).
- **Issue #10**: file tools work again in `occ taskprocessing:worker` (CLI) by
  setting up the user filesystem before resolving the home folder.
- **Issue #8**: AppData namespaces for chats and AI marks are derived from a
  SHA-256 hash of the user ID, eliminating cross-user collisions.
- **Issue #7**: background indexing runs independently per user instead of
  being blocked by other users' partial indexes.
- **Issue #6**: an incomplete (bounded) index scan can no longer delete valid
  documents that were simply not visited yet.
- **Issue #5**: global configuration and index management endpoints are
  restricted to administrators.

### Changed
- **Responsive workspace and providers**: the New chat action now uses a block-level full width with Nextcloud's native wide modifier, exactly matching the Documents/Settings navigation-item width, shared chat content expands on large screens, notification entries use the EVA app icon, and Assistant provider labels are standardized as `Eva · Local`, `Eva · RAG`, `Eva · Tools`, and `Eva · Agent`.
- **Frontend navigation**: the native chat search now has the primary **New chat** action directly below it, and per-chat rename/delete actions use Nextcloud's native icon wrapper for stable alignment.
- **Issue #2**: the app ID is migrated from `eva-ai` to `eva_ai` (info.xml,
  namespaces references, routes, AppData folder, DB rows, Talk bot URL, JS
  bundles and docs). Existing installs keep their data; the Talk bot was
  re-registered under the new URL.

### Reliability and bug fixes
- Index cancellation is now bounded at the Ollama embedding boundary and does not publish staged chunks after a stop request; the worker alone records the real finish time and releases the run claim.
- **Issue #49:** background indexing now persists per-user enrollment, including empty or reset indexes, with an explicit Settings opt-out.
- **Issue #57:** abandoned streaming requests now stop when the client disconnects and close the Ollama response stream without starting another tool round.
- **Issue #58:** frontend API failures now preserve HTTP status and bounded server messages instead of becoming silent null responses.
- File-context chat now includes the current user's personal `KNOWLEDGE.md` as personal context while keeping selected-file excerpts as the only document evidence.

### Added
- On first use, EVA creates an editable, per-user `KNOWLEDGE.md` profile section from the Nextcloud user ID, display name and optional email without overwriting existing knowledge or importing sensitive profile fields.
- **Issue #3**: automated test asserting every TaskProcessing provider ID is
  unique and prefixed with the app ID.

- **Security**: external HTTP calls (Open-Meteo weather) now enforce TLS
  certificate verification (`CURLOPT_SSL_VERIFYPEER`/`SSL_VERIFYHOST` were
  disabled, enabling man-in-the-middle attacks). Fixes issue #9.
- `TalkSetup` command and `TalkBotRegistrar` no longer hard-depend on the
  optional Talk app: `occ` works again even when Talk (spreed) is not enabled.
  The `BotServerMapper` is resolved lazily at runtime after checking that Talk
  is active.
- Tool policy surfaces: read-only tools are now correctly allowed on the RAG
  surface; the `AgentInteractionProvider` switches to the `web` surface after
  user confirmation so mutating tools keep working in the confirmation flow.
- `AgentInteractionProvider::injectRagContext()` used an undefined `$this->config`
  property instead of the injected `AppConfig` — fixed.
- `indexEmails()` was missing the `oldDocId` key in a batch array, which could
  crash on "Undefined array key" — fixed.
- Removed a stray NUL byte in `Indexer.php` that made tooling treat the file as
  binary.
- `info.xml` metadata cleanup (author, bug tracker URL) and `package.json`
  version sync.

### Added
- Central tool permission policy (`lib/Service/ToolPolicy.php`) with risk
  classification (readonly/mutating/destructive), surface isolation
  (web/talk/rag/taskprocessing) and enforcement in `ActionExecutor::run()`.
- Automated test suite: `tests/ToolPolicySecurityTest.php`,
  `tests/TaskProcessingContractTest.php`, `composer.json` (PHPUnit), bootstrap
  and `phpunit.xml.dist`.
- GitHub Actions CI (`tests.yml`) running PHPUnit on PHP 8.2/8.3/8.4, PHP
  syntax checks, frontend builds, generated-bundle emission checks and migration/provider
  regression tests; composer install retries on transient SSL failures.
- Privacy documentation (data lifecycle, retention, reset commands) in the
  README and `docs/PRIVACY.md`.
- `Indexer` now streams file discovery via a generator instead of materializing
  the whole tree in memory.
- Docs: `docs/SECURITY.md`, `docs/CONFIGURATION.md`, `docs/ARCHITECTURE.md`,
  `docs/DEVELOPMENT.md`.

### Housekeeping
- Pending regression contracts for open issues #99–#106
  (`tests/OpenIssuesPendingContractTest.php`): each test documents the fix
  contract and is skipped until the issue is implemented, so CI stays green.
- New CI workflows: `quality.yml` (composer/npm dependency security audits on
  push and pull requests) and `nightly.yml` (nightly `occ app:check-code` and
  the test suite against every supported Nextcloud release line and `latest`).
- New documentation: `docs/FAQ.md` (setup, indexing, chat, Talk, Assistant and
  privacy troubleshooting).
- Issue and pull-request templates (`.github/ISSUE_TEMPLATE/*`,
  `.github/PULL_REQUEST_TEMPLATE.md`) matching the repository issue format.
- Backlog grooming: related open issues are cross-linked and small, isolated
  fixes are labelled `good first issue`.

## [1.4.0]

### Fixed
- Repair migration for legacy hyphenated index names (`eva-ai_*` → `eva_ai_*`)
  so the schema works on MySQL/MariaDB and PostgreSQL; obsolete `ragchat_*`
  indexes are dropped idempotently.
- Indexing reconciliation now removes stale entries for deleted or unreadable
  files and re-chunks modified files based on content hash.

### Added
- Nextcloud 35 compatibility (`max-version` 35).
- Email indexing toggle (`mail_index_enabled`) with per-pass limit
  (`mail_index_max`).
- TaskProcessing providers for the Assistant app, including an agent with a
  confirmation flow for mutating tools.
- File-context chat from the Files app ("Open with EVA" / multi-select).

> **Note:** Entries for versions before 1.4.0 are reconstructed from the current
> codebase and migration history and may not exactly match the original release
> notes.

## [1.3.0]

### Added
- Talk bot integration (automatic registration, `eva_ai:talk:setup` command).
- Shares tool (list/create/update/delete), tasks tool (VTODO), profile tool.
- Notifications "AI answer ready" (Notifier).

## [1.2.0]

### Added
- Hybrid retrieval (vector + BM25, RRF fusion).
- Weather, server status, activity feed and current-time tools.
- Free-slot search for calendars.

## [1.1.0]

### Added
- Calendar tool (create/update/delete events, reminders, German/ISO/relative time
  formats).
- Contacts tool (own, shared, group and Circles address books).
- Mail app integration (search/list/read/unread) and initial email indexing.

## [1.0.0]

### Added
- Initial release: RAG indexing of files, hybrid search, chat with source
  citations, file tools (list/create/rename/delete/read/search/notes), knowledge
  base (`KNOWLEDGE.md`).
