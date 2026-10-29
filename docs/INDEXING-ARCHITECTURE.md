# EVA indexing architecture decision

**Status:** accepted product direction; implementation and cutover are pending.
**Decision date:** 29 October 2026.
**Source:** user direction following review of `reports/eva-backend-analysis.pdf`.

## Decision

EVA's new generation-based index is the standard indexing path. The default
installation must support indexing and retrieval with the Nextcloud SQL
database and configured embedding provider alone. SQL remains the canonical
store for documents, access metadata and chunk text. The legacy index remains
readable as a rollback source until generation cutover has passed the release
and recovery gates.

Qdrant is an optional scale adapter. It can accelerate retrieval for an
administrator who configures it, but it is not required to install, upgrade,
index files, chat, or search. EVA must not start a remote service or send
existing document content to one during installation or upgrade. A remote
transfer requires explicit configuration and a separate consented build.

This adapts the report's recommendation to prioritize Qdrant as a production
backend to the user's requirement that EVA work as a standalone Nextcloud app.
It does not dismiss the report's measured risks: PHP full-index cosine scans,
JSON vector storage, and SQL `LIKE` candidate selection remain performance
problems to solve. SQL-native generation search is the usable baseline and
portability path; optional ANN storage is the scale path.

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

The current tree has durable generation metadata, a bounded manual build,
verification and an explicit shadow probe. However:

- `VectorGeneration` currently accepts only `qdrant` as its backend.
- The build flow requires an explicitly configured remote target and consent.
- Normal indexing writes only legacy chunks; chat search reads only those rows.
- There is no guarded generation activation path or production shadow sampling.
- `VectorStorageStatus` reports `sql_legacy`; the interface must stay truthful
  until SQL generation writes and search are actually wired into normal use.

Therefore, the new path is the accepted target architecture, not a claim about
current runtime behavior.

## Delivery sequence

See [BACKEND-ROADMAP.md](BACKEND-ROADMAP.md), stages 06–12. The dependency
order is: SQL-capable generation identity and storage; default background
indexing and mutation journal; resumable migration and verification; safe
activation and shadow evidence; then optional Qdrant acceleration and hybrid
retrieval. Each cutover slice must include its matching native settings/status
surface, focused validation, recovery notes and a stacked reviewable PR.

## Acceptance gates before making generations the only default

- Fresh install and upgrade work on supported SQLite, MariaDB and PostgreSQL
  configurations with no vector service configured.
- Existing users can build a generation in bounded background slices, pause,
  resume, cancel and recover abandoned leases without changing chat answers.
- Source mutation, access revocation, deletion and account erasure cannot leave
  retrievable stale chunks or permit stale work to resurrect deleted content.
- SQL and configured optional-store rankings pass the frozen quality corpus;
  tenant and scope adversarial cases pass; shadow and load evidence meet agreed
  latency and error budgets.
- Activation is atomic and reversible, with the previous path retained through
  a documented soak and a tested rollback.
- Native admin status and personal indexing state accurately describe the
  effective path, progress, and actionable failures without exposing secrets or
  document contents.
