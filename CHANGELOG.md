# Changelog

All notable changes to **EVA (eva_ai)** are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- **Issue #71**: `RagService` now receives a `LoggerInterface` through
  constructor injection, so the access-revocation purge path no longer
  throws an undefined-property error that was silently swallowed by the
  surrounding `try`/`catch`; the audit log entry is now actually written.
- **Issues #108, #114 and #117**: Ollama URLs reject credentials/query/fragment components individually, connection checks stop after an unreachable server or missing model instead of waiting through multi-minute model timeouts, and chat pairs are persisted strictly as question then answer.
- **Issues #95, #100 and #103**: Talk blocks sensitive profile/share/server-status tools, existing share listings redact link capability secrets and URLs, and TaskProcessing only injects explicitly supplied Talk rooms with caps of three rooms and 20 recent messages per room.
- **Issues #102, #105 and #98**: the `chatwithtools` TaskProcessing provider now ignores caller-supplied tool definitions and keeps the application-owned policy prompt, abandoned agent state is pruned after 30 days, and a tool-only streaming round completes with a truthful summary instead of a false no-text error.
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
