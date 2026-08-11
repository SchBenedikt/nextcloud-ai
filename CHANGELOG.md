# Changelog

All notable changes to **EVA (eva_ai)** are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
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
- GitHub Actions CI (`tests.yml`) running PHPUnit on PHP 8.2/8.3/8.4 plus a PHP
  syntax check; composer install retries on transient SSL failures.
- Privacy documentation (data lifecycle, retention, reset commands) in the
  README and `docs/PRIVACY.md`.
- `Indexer` now streams file discovery via a generator instead of materializing
  the whole tree in memory.
- Docs: `docs/SECURITY.md`, `docs/CONFIGURATION.md`, `docs/ARCHITECTURE.md`,
  `docs/DEVELOPMENT.md`.

## [1.4.0]

### Fixed
- Repair migration for hyphenated index names (`eva_ai_*` → `eva_ai_*`) so the
  schema works on MySQL/MariaDB and PostgreSQL; obsolete `ragchat_*` indexes are
  dropped.
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
