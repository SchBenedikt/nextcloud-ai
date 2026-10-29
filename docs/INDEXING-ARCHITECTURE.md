# EVA indexing architecture decision

**Status:** accepted product direction; first guarded SQL generation cutover implemented, scale and release gates remain open.
**Decision date:** 29 October 2026.
**Source:** user direction following review of `reports/eva-backend-analysis.pdf`.

## Decision

EVA's new generation-based index is the standard indexing path. The default
installation must support indexing and retrieval with the Nextcloud SQL
database and configured embedding provider alone. SQL remains the canonical
store for documents, access metadata and chunk text. The legacy index remains
readable as a rollback source until generation cutover has passed the release
and recovery gates.

Ollama is the default embedding destination. A user may explicitly select an
OpenAI-compatible profile for automatic SQL generations; this separately
configured choice sends indexed file text to that profile and does not follow
the chat-provider setting. Regular legacy indexing remains on Ollama so its
vectors are never mistaken for vectors in a different generation space.

Qdrant is an optional scale adapter. It can accelerate retrieval for an
administrator who configures it, but it is not required to install, upgrade,
index files, chat, or search. EVA must not start a remote service or send
existing document content to one during installation or upgrade. A remote
transfer requires explicit configuration and a separate consented build.

This adapts the report's recommendation to prioritize Qdrant as a production
backend to the user's requirement that EVA work as a standalone Nextcloud app.
It does not dismiss the report's measured risks: PHP full-index cosine scans
and JSON vector storage remain performance problems. SQL lexical retrieval now
has a portable posting index; it uses postings for indexed chunks and the legacy
`LIKE` path only for pending or unsupported chunks during catch-up. SQL-native generation search is the usable
baseline and portability path; optional ANN storage is the scale path.

## Required behavior

1. New and changed files are processed by a resumable, bounded background
   pipeline. Upload, rename, chat response, and interactive UI requests do not
   wait for extraction or embedding.
2. Every generation pins the embedding provider identity, endpoint fingerprint,
   model, dimensions, chunk settings, metric and schema version. Legacy rows do
   not receive guessed provenance.
3. Tenant, source-access, deletion and account-erasure fences apply to SQL and
   optional vector points. SQL remains the source of hydrated text and current
   permissions.
4. Generation build, verification, shadow comparison and activation are
   distinct operations. Only a complete, current, verified generation can be
   activated. Rollback keeps the previous generation and legacy data intact.
5. Search behavior remains useful without Qdrant. Optional-store failure has a
   bounded, observable fallback and cannot return another tenant's content.
6. Admin UI uses native Nextcloud controls and exposes only essential setup,
   progress, actionable failure and rollback information. Chat UI does not
   surface infrastructure controls.
7. User-facing indexing and search status reports the actual active path. Do
   not label the current `sql_legacy` implementation as the target SQL
   generation path.

## Current implementation gap

The current tree has durable generation metadata, a bounded build,
verification, an explicit shadow probe and a guarded SQL retrieval path for
enrolled users. Normal indexing continues writing legacy chunks while the new
generation backfill and source mutations run in the background. They do not
delay the index response. SQL generation builds do not send content to Qdrant;
the optional remote build requires separate administrator consent. Specifically:

- `VectorGeneration` accepts `sql` and `qdrant`; SQL generations bind to a
  deterministic local target and write directly to Nextcloud SQL.
- SQL builds do not create Qdrant point-delivery events. Their verifier checks
  local vector shape, revisions, content hashes and source manifests. Qdrant
  generations retain remote reconciliation and explicit transfer consent.
- Normal indexing continues writing legacy chunks while an enrolled user's
  generation is built and maintained asynchronously. After complete
  verification, a recurring worker activates a current SQL generation under
  the owner fence. Search reads that generation using its pinned embedding
  model. Missing metadata, an empty generation with legacy content, or pending
  source mutations makes search fall back to legacy SQL. Legacy data remains
  available for recovery.
- Recurring local indexing is enabled by default after a user starts indexing;
  the user can explicitly pause it. The recurring index pass also checks
  settled legacy indexes, using one uncached
  public-text probe when no SQL generation exists; it never guesses model
  provenance from cache-only batches or sends indexed content for discovery.
  Provider failures are retried after a six-hour per-user cooldown. Qdrant
  selection is left untouched, and SQL is the only backend selected here.
- SQL verification is queued for users with recurring indexing enabled after a staged build. Failed
  or cancelled verification remains explicit. A current successful
  verification can activate the SQL generation automatically. A fully
  verified Qdrant generation can be activated only by an explicit administrator
  action, with a direct rollback to legacy SQL.
- Canonical creates and metadata changes now write coalesced mutation intent
  for an active generation in the same SQL transaction. Deletes cancel pending
  upserts. A bounded background worker re-embeds with the generation's pinned
  model, checkpoints long sources, and retries transient failures. It leaves a
  changed document pending until all its chunks are written. Generation reads
  omit incomplete content and switch to legacy SQL until the mutation completes.
- Guarded activation and generation-aware SQL reads now serve explicitly
  enrolled users. The old generation is retired atomically while its rows
  remain available for recovery.
- An administrator can optionally canary a verified Qdrant generation for an
  enrolled account. Every remote query enforces the tenant, generation and
  folder-scope filters; search hydrates only exact-revision, source-current
  rows from SQL. A remote error or queued source mutation falls back to legacy
  SQL. This canary is opt-in and has an explicit rollback action.
- SQL generation reads still deserialize JSON vectors and perform PHP cosine
  ranking, including full-index scans on large indexes. Those scans now use
  stable keyset pages instead of database offsets; the full vector scan still
  costs CPU proportional to index size. No representative
  load/quality, cross-database activation, shadow soak or rollback drill has
  established production readiness.

Therefore, this is the current enrolled-user cutover path, not evidence that
scale, cross-database portability or production release gates have passed.

## Delivery sequence

See [BACKEND-ROADMAP.md](BACKEND-ROADMAP.md), stages 06–12. The dependency
order is: SQL-capable generation identity, storage, build and verification;
default background indexing and mutation journal; resumable migration and
verification; guarded SQL activation and retrieval (implemented for enrolled
users); then performance improvements, shadow evidence, optional Qdrant
acceleration and hybrid retrieval. Each cutover slice must include its matching native settings/status
surface, focused validation, recovery notes and a stacked reviewable PR.

## Acceptance gates before making generations the only default

- Fresh install and upgrade work on supported SQLite, MariaDB and PostgreSQL
  configurations with no vector service configured.
- Existing users can build a generation in bounded background slices, pause,
  resume, cancel and recover abandoned leases without changing chat answers.
- Source mutation, access revocation, deletion and account erasure cannot leave
  retrievable stale chunks or permit stale work to resurrect deleted content.
- Normal stale-file, exclusion, folder-prefix, and successful replacement
  cleanup use the canonical vector source-deletion transaction. SQL generation
  chunks are deleted with their source; configured Qdrant generations receive
  durable tombstone intents. Enrolled users use verified SQL-generation
  retrieval automatically. Ordinary indexing keeps legacy chunks for rollback
  and queues active-generation mutation work; the bounded worker updates SQL
  generation rows asynchronously, and search falls back while mutations remain
  pending. Continue auditing each Files/Mail/Talk/VFS mutation and deletion
  path, and verify crash recovery and backend portability before removing the
  legacy rollback path.
- SQL and configured optional-store rankings pass the frozen quality corpus;
  tenant and scope adversarial cases pass; shadow and load evidence meet agreed
  latency and error budgets.
- Activation is atomic and reversible, with the previous path retained through
  a documented soak and a tested rollback.
- Native admin status and personal indexing state accurately describe the
  effective path, progress, and actionable failures without exposing secrets or
  document contents.
