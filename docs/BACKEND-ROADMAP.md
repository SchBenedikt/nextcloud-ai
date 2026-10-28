# Backend migration work ledger

Source: *Technische Backend-Analyse von EVA AI*, 23 pages, dated 28 October
2026. The report audits main `24e7982` and PR #697. This ledger additionally
accounts for the workspace stack #698–#699. Vendor pricing, product lifecycle
claims and estimated latency in the report are not measured EVA results.

Installation requirement confirmed by the user: installing EVA must remain
sufficient to use its existing storage path. SQL retrieval is the zero-additional-
service default. Qdrant is an explicitly configured optional scale backend;
missing Qdrant configuration must not block app installation, upgrades, chat or
SQL indexing. Never start an external service or transmit existing documents to
one merely because the app was installed. Deployment automation and operating
instructions must support both standalone-app and optional-vector-store modes.

## Verified starting point

- `Searcher::loadCandidates()` visits the complete tenant index in 2,000-row
  pages for dense candidates above 8,000 chunks. Ranking remains PHP cosine,
  BM25, quoted-phrase boost, reciprocal rank fusion and document diversification.
- `Chunk` stores embeddings as JSON text. `ChunkMapper` supplies lexical
  candidates through SQL LIKE, not an inverted index.
- `Indexer` already bounds file traversal, extraction and embedding batches and
  supports cancellation, heartbeats and reuse of compatible cached embeddings.
- `ChatStore` aggregates a user's encrypted conversations in AppData. It is a
  separate persistence migration, not a vector database use case.
- Indexer and Searcher currently depend on the concrete Ollama service for
  embeddings. Chat-provider selection does not select an embedding provider.
- Custom provider requests use Nextcloud's HTTP client. An endpoint policy must
  preserve its DNS/SSRF protections, including on streaming and media paths;
  syntactic URL validation alone does not establish a network trust boundary.

## Rules for every dependent PR

Each row below is a separate reviewable change based on the preceding completed
branch. PR descriptions name the parent and prerequisites. SQL and Nextcloud VFS
remain authoritative. No vector service is enabled implicitly. Existing encrypted
secrets, account isolation, tool policy and cancellation semantics must survive.

Use `benedikt.schaechner@web.de` for author/committer and authenticated GitHub
account `SchBenedikt` for pushes. Publish PRs, never push directly to main.

The user has currently requested skipping tests and PR checks. Test and benchmark
criteria below therefore remain **unverified gates**, not permission to claim a
production cutover. Builds remain required after frontend changes. Branch
protection and required checks are administrative follow-up; do not silently
change repository policy while checks are deferred.

## Ordered implementation stack

| Stage | Deliverable | Dependencies | Acceptance and rollback |
| --- | --- | --- | --- |
| 01 | This audited ledger and architecture decision | #699 | Every PDF finding mapped; distinguish recommendations from measurements. Documentation only. |
| 02 | EmbeddingProvider contract wired into indexing and query embedding | 01 | Existing Ollama implementation preserves batching, null slots, cache keys, cancellation and counters. No provider/model changes. Revert DI binding to concrete service. |
| 03 | Retrieval stage timings without prompt or content logging | 02 | Separate embedding, candidates, hydration, scope, ranking and total time; expose candidate count and backend. Logging optional. No ranking changes. |
| 04 | Provider egress policy | 03 | Admin-owned policy for private/loopback services; deny metadata/link-local targets; reject URL credentials, query and fragment; validate all chat, model, image and audio paths. Preserve Nextcloud DNS validation; no blanket allow-local bypass. Opt-in rollout with migration notes for local servers. |
| 05 | Reproducible retrieval benchmark | 04 | Frozen corpus and qrels; semantic-only, exact IDs/names, mixed, phrase, diversification, scope and tenant-adversarial queries. Exact baseline and current EVA baseline. Report quality and latency separately. |
| 06 | VectorStore contract, generation entities and additive schema | 05 | Generation pins user, provider identity, endpoint fingerprint, model, dimension, metric, chunk settings and schema. States building/active/retired/failed. SQL legacy remains default. No inferred model identity for old chunks. |
| 07 | Qdrant adapter and deployment configuration | 06 | Server-only encrypted credentials, TLS for remote service, bounded timeouts/batches, stable point IDs, indexed tenant/generation/document/source payload. Every query forces server-side tenant AND generation filters. Return IDs/scores, hydrate text from owned SQL rows. Fail closed on dimension/tenant mismatch. |
| 08 | Transactional vector outbox and worker | 07 | Chunk mutation and event in the same SQL transaction, including document replace, reset, source deletion and account erasure. Claims/leases, retries/backoff, dead letters and idempotent versions prevent stale resurrection. Legacy reads remain default. |
| 09 | Resumable backfill and generation verification | 08 | Bounded keyset batches, durable cursor/heartbeat, cancel/resume, explicit model provenance, cache reuse. Compare expected SQL IDs and point IDs, dimensions and sampled scores. Activation must reject unverified or incomplete generations. |
| 10 | Shadow reads and comparison telemetry | 09 | SQL serves answers. Shadow execution has a bounded resource budget and cannot prolong interactive answers unboundedly. Compare recall, ranking, scope, tenant isolation and errors. No answer-content logs. Disable shadow via flag. |
| 11 | Canary and primary dense vector reads | 10 | Explicit per-instance/per-user rollout. Bounded error behavior, privacy-safe SQL hydration and observable fallback. Keep SQL embeddings and SQL writes for at least a release cycle. One-flag rollback, no destructive migration. |
| 12 | Native hybrid retrieval | 11 | Port BM25/RRF while preserving filename/path context, quoted phrases, scope and document diversification. Version-pin backend capabilities and compare against current hybrid baseline before activation. Dense-only rollback available. |
| 13 | Normalized encrypted chat/message SQL storage | 12 | Additive tables, per-message writes, indexed metadata pagination/retention, transactional migration with checkpoint, mixed-version recovery and account erasure. Preserve encryption; do not add plaintext search indexes for encrypted messages. Separate feature flag and backup/restore runbook. |
| 14 | Operational hardening and portability | 13 | PostgreSQL/MariaDB lifecycle coverage; metrics exporter/trace correlation; restore and delete drills; sustained soak; cache outage/rate-limit semantics; domain-controller extraction; dependency/SAST/SBOM policy. Administrative main protection requires its own explicit rollout. |

## Frontend integration required throughout the stack

The user explicitly requested frontend changes alongside the backend. Every
remaining stage must include the relevant visible administration/personal flow,
using native Nextcloud components and the existing compact page widths. Do not
expose dormant backend flags as working user features or add chat-header controls
for infrastructure concerns.

| Backend stage | Matching interface deliverable |
| --- | --- |
| 07–08 | Shared offline storage status in EVA administration and Nextcloud administration settings: actual search mode, connection configuration, cleanup state and actionable queue counts. Errors remain unknown, never a healthy zero. No automatic external probes. |
| 08–09 | Native administrator configuration with write-only credentials, explicit connection test, bounded operation inspection/retry, build progress and cancel/resume once the corresponding guarded backend APIs exist. Separate connection setup from migration and activation. |
| 02, 09 | Embedding provider/model selection separate from chat provider, immutable generation summary and clear rebuild requirement. Expose only implemented adapters. |
| 10–11 | Readable SQL/shadow/canary/primary status and rollback control, verification blockers and last comparison. Advanced migration details belong in disclosure panels, not the chat composer. |
| 12 | Consistent search/source states and understandable partial failure/fallback messages. Preserve the focused ChatGPT/Talk-like chat layout and native controls. |
| 13 | Paginated conversation history, preserved encryption and visible migration/recovery state only when necessary. Preserve the centered home composer/recent-chat width and no-scroll home behavior. |
| 14 | Compact operational diagnostics and accessible loading/empty/error/disabled states across narrow screens and light/dark themes. Avoid raw backend errors, identifiers and secrets in personal settings. |

The first interface slice provides read-only status in both admin surfaces through
one backend snapshot. Native connection configuration, explicit API check and
transport disable are now exposed through an administrator-only page and dedicated
controller. The prior dormant Vue admin branch is replaced by a separate entry
without personal chat initialization. Migration/activation/progress controls
remain pending until their backend operations exist. No browser, access-control,
CSRF or remote-transport validation is claimed yet.

## Native Nextcloud integration direction

The user additionally requested a faster, more capable EVA with deeper integration
into Nextcloud and native apps. Preserve the standalone installation default and
native UI while advancing these concrete integration paths alongside the PDF:

| Surface | Required direction |
| --- | --- |
| Files and VFS | Native file actions/pickers, permission-checked source links and selected-file chat. File-change listeners enqueue bounded background work; embedding/reindexing must not run on the interactive upload/rename or answer path. |
| Unified search | Route the existing `EvaSearchProvider` through the same owned/scoped retrieval contract, with bounded query work and explicit backend/fallback state. No separate diverging search index or guessed model identity. |
| Talk | Reuse native bot/event and background-job integration. Preserve participant ownership, conversation context, confirmation policy and cancellation without exposing another user's Files index. |
| Task processing | Preserve native OCP provider registration and supported-core compatibility; share provider selection and observability instead of duplicating transport logic in every task type. |
| Chat and administration | Native composer, Files context and source affordances with the existing compact content width. Infrastructure/configuration/progress belongs in native settings and admin flows, not extra chat-header controls. |
| Account and app lifecycle | Native user-deletion/lifecycle listeners must coordinate durable erasure and retry. App installation must not start another service or silently transmit documents. |

These are development/acceptance requirements, not claims that every path has been
updated or validated. Existing services/listeners are starting points, not evidence
of completed integration. The PDF's migration, benchmark, ownership and rollback
gates remain mandatory completion boundaries.

## Operational follow-up

Consolidated maintenance follow-up from the retired 2.0 roadmap remains in stage
14: finish controller/executor domain boundaries, remaining request/response DTOs
and Vue composable extraction, actual-core request-stack coverage, responsive
audits and legacy code-comment cleanup. Existing typed ChatRequest and extracted
composables are starting points; assess remaining paths from current source.

## Required generation and consistency semantics

A generation is immutable after building begins. A provider/model change creates
another building generation; it never overwrites the active collection in place.
Collection names are server-controlled and must not expose user IDs. Stored point
payloads contain identifiers and metadata only during dense migration. Text stays
in SQL. Query endpoints cannot accept arbitrary tenant filters from clients.

Events need a durable monotonic revision or tombstone: retrying an old upsert after
an account/document delete must not recreate deleted data. Workers must verify
current ownership, revision and generation before delivery. Account erasure covers
SQL, outbox, vector points, cache and secrets; backup retention must be documented.

Count equality alone is insufficient verification: missing IDs and duplicate/stale
points can have equal counts. Verification must compare identities and dimensions,
include adversarial tenant queries and check a deterministic score sample. No
production activation until this evidence exists.

## Benchmark and rollout gates

Use small/medium/large corpus sizes from the report as workload targets, not as
capacity claims. Begin on available hardware and record hardware, backend versions,
vector dimensions, filter selectivity, cache state, concurrency and corpus hash.
Do not manufacture a million-chunk latency result from a tiny synthetic sample.

Measure Recall@5/10/20, Precision@k, MRR@10, nDCG@10, source-document recall,
duplicate-chunk rate and distinct documents@k. Cross-tenant leakage must be zero.
Compare current EVA, exact cosine, Qdrant dense, Qdrant plus existing lexical/RRF,
then native hybrid. Optional Weaviate comparison is not an implementation dependency.

Measure p50/p95/p99 for embedding, candidates/vector, lexical, hydration, fusion and
whole retrieval; also PHP RSS, SQL rows/bytes, network, backend storage/RAM, ingest
rate, reindex time and queue lag. Exercise cold/warm caches and 1/5/20/50 users.
The report proposes Recall@10 >=95%, nDCG regression <=2%, p95 <200ms and p99
<500ms excluding LLM time at one million chunks. These are provisional acceptance
targets requiring hardware-specific evidence, not current guarantees.

Before cutover: worker kill/retry loses no writes, cancellation resumes correctly,
outbox returns to steady state, cross-tenant leakage is zero, restore works and
rollback succeeds without reindexing. A multi-day shadow/soak period cannot be
replaced by a successful build or an adapter unit test.

## Findings beyond the vector migration

- DB portability: run real migrations, mapper and API paths on PostgreSQL and
  MariaDB in addition to SQLite when checks resume.
- Provider contracts: mock API shapes and opt-in live provider checks; never
  publish secrets or transmit user documents during automatic verification.
- Observability: request correlation, provider/tool durations, queue age, dead
  letters, embedding cache hit ratio, vector errors and chat-store lock wait.
- Dependency upgrades #664/#665 require PHP/Nextcloud compatibility review;
  major upgrades are not automatically safe because a bot opened the PR.
- Controller splitting and cache/rate-limit resilience are separate bounded
  refactors; preserve request/response DTOs and public API behavior.
- Branch protection, reviews/CODEOWNERS, required checks, secret scanning,
  static analysis and release SBOM are policy work, not silently enabled code.

## Progress

- 01: implemented in this branch.
- 02: embedding dependency boundary implemented; automated validation deferred.
- 03: initial stage timings implemented; independent lexical/fusion spans and
  a metrics exporter remain follow-up work.
- 04: custom-provider destination policy implemented; adversarial transport checks
  remain unverified and proxy support is explicitly restricted.
- 05: offline evaluator and live SQL snapshot/recording commands implemented;
  representative measurement runs and acceptance results remain pending.
- 06: additive schema, immutable identity, metadata operations and contracts
  implemented; migration execution and activation verification remain pending.
- 07: optional dense adapter and explicit connection/collection administration
  implemented; live protocol, isolation and deployment verification pending.
- 08: target registry, durable journal, lease/retry primitives and transactional
  account-erasure intent and disabled-by-default generation-erasure consumer
  implemented; chunk worker, revision/tombstone fencing and general mutation
  integration remain pending.
- 09–14: pending; a stage is complete only when its code and matching operating
  documentation exist. Validation and production rollout remain separately
  recorded. No Qdrant deployment or production data migration has occurred.

### Stage 02 implementation

Indexer and Searcher now consume the EmbeddingProvider contract; bootstrap binds
it to the existing Ollama instance. Ollama implements the contract directly, so
existing constructors and test doubles remain valid. No additional provider is
activated. Automated tests remain deferred at user request; only syntax/patch
checks are recorded for this stage.

### Stage 03 implementation

Searcher now emits optional privacy-preserving debug stage measurements using a
monotonic clock. Logging failures cannot fail retrieval. Candidate selection and
ranking remain unchanged. These events are not yet a metrics exporter, benchmark
corpus, latency claim or completed shadow/soak gate. Automated tests remain deferred.

### Stage 04 first slice: redirect boundary

All OpenAI-compatible request paths now explicitly reject redirects, including
models, chat, image generation and audio. Returned-image downloads already did so.
Inspected Nextcloud 35's Client implementation: EVA retains default certificate,
proxy and remote-host checks and introduces no allow-local bypass. The full
admin-owned address/DNS policy and its validation are still pending; this change
must not be presented as complete SSRF protection. Endpoint operators must enter
the final base URL. PHP syntax/patch checks only; automated checks remain deferred.

### Stage 04 destination policy implementation

ProviderEndpointPolicy enforces instance-only exact private-origin approval,
reserved/metadata address rejection, HTTPS for unapproved origins and validated
DNS pinning at the cURL transfer boundary. All compatible-provider paths, including
returned-image downloads, use the guard. CNAME work is bounded and ambiguous
responses fail closed. Core TLS/local-host checks remain in force. Proxy and
hosts-file limitations and private-provider upgrade instructions are documented
in PROVIDERS.md. Built-in Ollama remains unchanged; the security criteria here
concern the user-defined compatible-provider surface introduced by #697.

Implementation is present; adversarial and live transport verification remains
unperformed under the user's instruction to defer tests. Do not mark the security
acceptance gate verified on the basis of PHP syntax checks.

### Stage 05 offline benchmark foundation

The streaming exact-cosine oracle and backend ranking evaluator are implemented
in `tools/retrieval-benchmark.php`, with a frozen synthetic format example and
strict corpus/query hashes. Quality, isolation, duplicate and nearest-rank latency
metrics are documented in RETRIEVAL-BENCHMARK.md. Live EVA/Qdrant snapshot recording,
representative corpus collection, load execution and empirical acceptance gates
remain pending. The synthetic example is not a real retrieval-quality benchmark.

### Stage 05 live SQL baseline integration

`eva_ai:retrieval:benchmark export|record` now exports an explicit user's private
snapshot and invokes the actual SQL Searcher with before/after corpus and direct
SQL configuration fingerprints. Keyset reads, output permissions, query embedding
preflight, failure cleanup and legacy provenance restrictions are documented in
RETRIEVAL-BENCHMARK.md. No external vector service is required. Quiescence is an
operator prerequisite; the fingerprint protocol is not a serializable transaction.
Live executions, representative datasets and acceptance measurements remain pending
under the instruction to defer tests. Neither successful syntax checking nor the
synthetic dataset satisfies the production quality/performance gates.

### Account-erasure prerequisite discovered during schema work

The legacy cleanup removed document rows before deleting chunks, but
ChunkMapper::deleteForUser identifies chunks through those document rows. Cleanup
now deletes chunks first and only deletes parent documents if that succeeds.
A chunk-deletion failure retains the ownership mapping for a retry while the
remaining best-effort account cleanup continues. This prevents newly orphaning
chunks in this path; it does not retroactively identify owners of historical
orphans or implement a durable retry queue. Those remain storage-erasure work.

### Stage 06 generation schema and contracts

The 1.18.0 additive migration, immutable VectorGeneration, tenant-bound repository,
VectorStore/SearchFilters contracts and explicit prepare/list/fail OCC operations
are implemented. Old chunks remain legacy/unassigned and SQL retrieval remains
default. There is no unverified activation path. Local generation metadata joins
account cleanup; durable remote tombstones must precede any remote writes.
VECTOR-STORAGE.md documents fields, invariants, compatibility and rollback limits.
Schema execution, concurrency and database-portability validation remain pending;
syntax checking is not proof of a successful upgrade or backfill verification.

### Stage 07 optional dense adapter

Qdrant transport and dense VectorStore operations, encrypted instance credentials,
endpoint-bound secrets, TLS policy, transfer-time egress checks, collection/index
preparation and explicit OCC administration are implemented. Scope tokens avoid
copying raw paths. All operations are bounded; result payload identity is checked.
An optional loopback-only Compose example requires explicit approved image/key
configuration and is never run by installation. QDRANT.md records API references,
behavior, restrictions and unverified integration boundaries.

No SQL chunk synchronization or primary/shadow reads are enabled. The outbox must
pin storage-target identity and handle deletion/revision ordering before any live
SQL mutation is replicated. No remote write/load/isolation tests or container
startup have been run under the user's instruction to defer testing.

### Stage 08 durable journal and target foundation

The 1.18.1 schema, encrypted target registry, immutable storage binding, transaction-
required enqueue, CAS leases/acknowledgements, bounded retry/dead-letter primitives
and offline OCC inspection/requeue are implemented. Account SQL erasure and bound
collection deletion intent commit together. Legacy per-user cleanup is keyset-
bounded and reset now preserves chunk ownership until chunk deletion completes.

There is no worker or general chunk-mutation replication yet. Remote revision/
tombstone enforcement, account creation fencing, target retention/pruning, reset/
rename/replacement integration and persistent account-cleanup retries remain
pending. VECTOR-OUTBOX.md distinguishes these implementation gaps from the queue
primitives. No delivery/crash/concurrency or live migration gate is verified.

### Stage 08 generation-erasure consumer

VectorErasureWorker and its minute job now consume only delete_generation intent
when both server-owned flags explicitly enable execution. First observation adds
a grace period; post-DNS guards recheck flags, lease and retired/missing metadata
before transfer. Explicit collection preparation now also rechecks its building/
target identity at that boundary. CLI `outbox run` reports execution counts.
Chunk operations remain unclaimed; there are no automatic point writes or primary
reads. VECTOR-OUTBOX.md states the soft-budget, process-suspension, account fencing
and retention limits. Runtime crash/race/remote validation remains outstanding.

### Stage 08 remote point revision boundary

The adapter requires monotonic journal revisions for live point writes, rejects
older overwrites, materializes terminal tombstones for exact chunk IDs (including
never-delivered points), and overwrites deleted embeddings/metadata with a unit
vector and minimal deletion payload. Physical document deletion is removed from
the interface. Search forces deleted=false and returns checked revisions for later
SQL hydration. QDRANT.md records the missing-point insertion trap and safe-integer
bound. No point-event consumer or mutation producer is enabled by this change;
remote concurrency and deletion semantics remain unverified acceptance gates.

### Stage 08 durable owner creation fence

The 1.18.2 owner table serializes generation creation/target binding against
account deletion using portable insert-ignore and row updates. Account erasure
commits a blocked fingerprint before SQL index cleanup, so rollback cannot reopen
ownership. Preparation reads owner/building/target state in one post-DNS query.
OCC owner-status works for deleted IDs; personal export includes fence metadata.
Both native administration surfaces show blocked/unavailable owner state using
bounded bulk lookups, without presenting an unblock control.
No unblock/prune or general cleanup retry coordinator exists yet. SQL writes and
chunk consumers are still pending; no remote activation, migration lifecycle or
concurrency gate is verified. Privacy documentation now distinguishes local
extraction/storage from provider-dependent inference and retained deletion state.
