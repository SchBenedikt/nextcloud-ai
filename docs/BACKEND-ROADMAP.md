# Backend migration work ledger

Source: [*Technische Backend-Analyse von EVA AI*](reports/eva-backend-analysis.pdf), 23 pages, dated 28 October
2026. The report audits main `24e7982` and PR #697. This ledger additionally
accounts for the workspace stack #698–#699. Vendor pricing, product lifecycle
claims and estimated latency in the report are not measured EVA results.

Installation decision confirmed by the user (29 October 2026): the new,
generation-based indexing path is EVA's standard path. It must run with
Nextcloud's configured SQL database and supported embedding provider, without
requiring another service. The old legacy SQL path remains available during
migration and as rollback until the new path passes its cutover gates. Qdrant
remains an explicitly configured optional scale adapter, never a prerequisite.
Installing or upgrading EVA must not start an external service or transmit
existing documents to one. Remote transfer requires a separate administrator
action and explicit consent. See [the indexing architecture decision](INDEXING-ARCHITECTURE.md).

## Report coverage and evidence

The retained PDF has SHA-256
`986da45fdf74d1da2e169226f0b8daa4b9420d64ceb947d25de73e9016d9e660`.
Page numbers below are PDF page numbers. This map makes recommendations traceable;
a stage listed here is not evidence that its acceptance criteria have passed.
The implementation entries further below record the actual delivered slices.

| PDF pages | Finding or recommendation | Implementation stage and remaining evidence |
| --- | --- | --- |
| 1, 4–5, 7 | PHP dense full scan, JSON vectors and SQL LIKE bottlenecks | 03, 05–12: measure the baseline, use bounded keyset scans for SQL pages, then continue toward indexed/hybrid retrieval; Qdrant remains optional. |
| 2, 9–10, 20–21 | Engine comparison, cost estimates and deployment choices | 07, 14: Qdrant is the selected optional adapter; document single-node/managed/HA operations. Alternative engines and estimated prices are decision inputs, not a requirement to ship five adapters or measured savings. |
| 3, 16 | P0 main protection, database matrix and security checks | 04, 14: endpoint boundary implemented in the stack; administrative branch protection and executed portability/security gates remain pending. Targeted tests resumed for the local recovery; the full portability/security matrix remains open. |
| 6, 8, 18 | Provider contracts, encrypted secrets, SSRF/DNS/TLS and local-service approvals | 02, 04, 07, 14: use native HTTP guards and explicit admin policy on every transfer. Hosted-provider contract tests remain opt-in; no account credentials are needed for installation. |
| 7–8, 11–12 | Immutable embedding generations, tenant filters, SQL hydration and transactional outbox | 06–09: additive schema, isolated chunk journal, point delivery, persisted verification, guarded enrolled-user SQL activation and generation-aware search exist; portability, adversarial and soak evidence remain open. |
| 9 | Major dependency upgrades require compatibility evidence | 14: assess PHPUnit/Symfony upgrades separately; do not merge them merely to clear Dependabot notifications. |
| 13–14 | Resumable backfill, immutable model rebuild, activation pointer, shadow/canary rollout and rollback | 09–11: bounded build, verification and guarded SQL activation/search exist; explicit Qdrant account canary and SQL rollback are available. Production shadow/soak and rollback drills remain pending. SQL runs without Qdrant; performance and release gates remain open. |
| 15 | Native hybrid BM25/RRF, phrases, scope and diversification | 12: preserve current relevance behavior before enabling backend hybrid retrieval; compare quality against both exact and current hybrid baselines. |
| 7, 15, 22 | ChatStore hot-file writes, normalized encrypted SQL messages and distributed chat coordination | 13–14: independent additive chat migration, recovery and erasure; lock lifecycle fix is only the immediate consecutive-request repair. |
| 15–18 | OTel correlation, provider/tool/DB/lock metrics, cache outages, restore/delete drills and domain extraction | 03, 14: native aggregate OpenMetrics and durable account-erasure retries are implemented; request percentiles, cross-service traces, restore/delete drills and controller decomposition remain pending. |
| 16, 18 | PHPStan/Psalm, SAST, secrets, SBOM, dependency and pinned-action policy | 14: define supported versions and executed evidence before enabling enforced checks; no claim of completed CI hardening. |
| 18–19 | Frozen corpus, adversarial tenants, quality thresholds and cold/warm load at concurrency 1/5/20/50 | 05, 09–12, 14: offline evaluator and explicit SQL snapshot runner exist; representative real datasets, ANN comparisons, load/soak and resource measurements remain pending. |
| 20–22 | Optional deployments, authoritative Nextcloud SQL/VFS and reconstructible vector index | 07, 09, 14: standalone default preserved; backup/restore and reconstruction drills remain pending. |
| 23 | External references | Retained as historical report sources; verify current vendor/API facts against primary documentation when implementing the corresponding adapter. |

## Verified starting point

- `Searcher::loadCandidates()` visits the complete tenant index in 2,000-row
  pages for dense candidates above 8,000 chunks. Ranking remains PHP cosine,
  BM25, quoted-phrase boost, reciprocal rank fusion and document diversification.
- Large-index SQL dense scans now fetch only chunk IDs and embedding JSON; full
  content/provenance rows are loaded only for the bounded cosine top set. This
  reduces database result transfer and PHP row materialization, but does not
  change the O(N) cosine scan or establish a latency result.
- Nextcloud OpenMetrics now exposes instance-wide indexed item totals, vector
  generation counts by backend/state, and outbox queue depth and oldest age by
  operation/state.
  Exported labels contain no tenant, file, prompt, endpoint, or credential data;
  request percentiles, provider timings, and queue-age histograms remain open.
- SQL cosine ranking validates and computes the query-vector magnitude once per
  request instead of repeating that query-side work for every candidate. This is
  an algorithm-preserving CPU reduction; no latency improvement is claimed
  without representative measurements.
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
branch. PR descriptions name the parent and prerequisites. Nextcloud SQL and VFS
remain authoritative. The generation-based SQL implementation is the default;
no vector service is enabled implicitly. Existing encrypted secrets, account
isolation, tool policy and cancellation semantics must survive.

Use `benedikt.schaechner@web.de` for author/committer and authenticated GitHub
account `SchBenedikt` for pushes. Publish PRs, never push directly to main.

The initial implementation batches deferred tests at the user's request. Runtime
recovery now resumes local PHP, frontend and authenticated browser checks. Earlier
stage entries below record their evidence at delivery time; see the current
recovery evidence in [QUALITY.md](QUALITY.md). Passing local checks does not prove
benchmark targets, cross-database portability or production cutover. Branch
protection and required checks remain administrative follow-up.

## Ordered implementation stack

| Stage | Deliverable | Dependencies | Acceptance and rollback |
| --- | --- | --- | --- |
| 01 | This audited ledger and architecture decision | #699 | Every PDF finding mapped; distinguish recommendations from measurements. Documentation only. |
| 02 | EmbeddingProvider contract wired into indexing and query embedding | 01 | Existing Ollama implementation preserves batching, null slots, cache keys, cancellation and counters. No provider/model changes. Revert DI binding to concrete service. |
| 03 | Retrieval stage timings without prompt or content logging | 02 | Separate embedding, candidates, hydration, scope, ranking and total time; expose candidate count and backend. Logging optional. No ranking changes. |
| 04 | Provider egress policy | 03 | Admin-owned policy for private/loopback services; deny metadata/link-local targets; reject URL credentials, query and fragment; validate all chat, model, image and audio paths. Preserve Nextcloud DNS validation; no blanket allow-local bypass. Opt-in rollout with migration notes for local servers. |
| 05 | Reproducible retrieval benchmark | 04 | Frozen corpus and qrels; semantic-only, exact IDs/names, mixed, phrase, diversification, scope and tenant-adversarial queries. Exact baseline and current EVA baseline. Report quality and latency separately. |
| 06 | VectorStore contract, generation entities and additive schema | 05 | Generation pins user, provider identity, endpoint fingerprint, model, dimension, metric, chunk settings and schema. States building/active/retired/failed. SQL generation identity, durable build, local verification, enrolled-user activation and SQL retrieval now exist; scale/quality and recovery evidence remain pending. |
| 07 | SQL generation store and transactional indexing pipeline | 06 | Enrolled users automatically queue a bounded SQL backfill after a successful embedding batch with known model identity. Source mutations are journaled and consumed in the background. Search switches to the verified SQL generation after activation; legacy chunks remain for fallback. SQL remains canonical; no external transfer. |
| 08 | Transactional mutation journal and worker lifecycle | 07 | Active source creates and metadata changes enqueue coalesced per-generation intent transactionally; a recurring worker consumes it in bounded, resumable slices with leases and retry backoff. Source deletion cancels pending work and erasure removes journal rows. Pending mutations make search use legacy SQL until the generation catches up. |
| 09 | Resumable backfill and generation verification | 08 | Bounded keyset build and verification; enrolled SQL generations queue verification automatically after staging, with missed handoff recovery. Explicit model provenance and local count/source checks are enforced. Activation rejects unverified or incomplete generations; current SQL certificates can activate automatically. |
| 09a | Optional Qdrant adapter and explicit transfer setup | 06, 08 | Server-only encrypted credentials, TLS for remote service, bounded timeouts/batches, stable point IDs, indexed tenant/generation/document/source payload. Every query forces server-side tenant AND generation filters. Hydrate text from owned SQL rows. SQL remains independently usable when Qdrant is absent or unavailable. |
| 10 | Shadow reads and comparison telemetry | 09 | SQL serves enrolled accounts by default; Qdrant canary is explicit and opt-in. The current comparison slice is an admin probe with bounded query/provider/vector/SQL work; production shadow sampling and soak remain pending. Compare recall, ranking, scope, tenant isolation and errors. No answer-content logs. |
| 11 | Canary and primary generation-based reads | 10 | Verified SQL-generation retrieval is automatic for enrolled users; an administrator can explicitly activate a verified Qdrant canary and roll back to built-in SQL. Queries apply tenant/generation/scope filters, hydrate exact revisions from SQL, and fall back on remote errors or pending mutations. Keep legacy rows; production soak and release-cycle rollback evidence remain pending. |
| 12 | Native hybrid retrieval | 11 | Implemented the explicit generation text-payload opt-in, separate build/resume consent, Qdrant 1.20+ BM25 index preparation, native dense+BM25 RRF, exact SQL hydration and SQL fallback. Existing generations remain dense-only. Verification checks text-field coverage and compares sampled text with SQL. Remaining: run representative quality/latency comparison, verify live Qdrant 1.20+ index schema and text erasure, then enable only for evaluated canaries. SQL/dense-only rollback remains available. |
| 13 | Normalized encrypted chat/message SQL storage | 12 | **In progress (1.18.27–1.18.29 stack).** Additive encrypted tables, small resumable imports, per-message SQL mirroring, automatic new-account SQL, scheduled verified migration for existing users, owner-scoped erasure, indexed metadata pages, per-user SQL reads with AppData export rollback, and paginated native sidebar loading are implemented. Mixed-version deployment/rollback rehearsal, performance/load evidence, and restore drills remain. Preserve encryption and add no plaintext search indexes. |
| 14 | Operational hardening and portability | 13 | PostgreSQL/MariaDB lifecycle coverage; metrics exporter (chat, provider, retrieval and slow-tool events now share short-lived trace IDs, but aggregation/export is open); restore and delete drills; sustained soak; cache outage/rate-limit semantics (embedding-cache 15-second process-local cooldown now avoids repeated failing cache calls while preserving uncached operation); domain-controller extraction; dependency/SAST/SBOM policy. Administrative main protection requires its own explicit rollout. |

## Stage 14 interface slice: correlated chat timings

Normal, streamed and queued chat work now gets a random 128-bit trace ID held in
request context. The same ID appears in chat totals, retrieval stage timings,
provider completion timings and slow-tool timings. Queued jobs retain it only in
the temporary queue record and omit it from completed history. These are
privacy-preserving debug logs, not OpenTelemetry spans, percentiles, or an
exporter; those remain open. The trace ID does not change provider payloads or
chat responses.

## Stage 14 interface slice: compact administration

The admin landing view now presents the built-in Nextcloud SQL default and a concise account/index queue summary. Per-user index actions, generation setup, and optional external vector storage are grouped under a collapsed native HTML disclosure, so standalone installations do not lead with infrastructure configuration. Advanced administration remains available. This is a presentation change; it does not alter indexing consent, background jobs, or storage selection.

## Frontend integration required throughout the stack

The user explicitly requested frontend changes alongside the backend. Every
remaining stage must include the relevant visible administration/personal flow,
using native Nextcloud components and the existing compact page widths. Do not
expose dormant backend flags as working user features or add chat-header controls
for infrastructure concerns.

| Backend stage | Matching interface deliverable |
| --- | --- |
| 07–08 | Shared offline storage status in EVA administration and Nextcloud administration settings: actual search mode, connection configuration, cleanup state and actionable queue counts. Errors remain unknown, never a healthy zero. No automatic external probes. |
| 08–09 | Native administrator configuration with write-only credentials for optional remote storage, explicit connection test, bounded operation inspection/retry, build progress and cancel/resume once the corresponding guarded backend APIs exist. Keep default setup simple; separate optional connection setup from migration and activation. |
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
remain pending until their backend operations exist. Fixture browser checks,
authenticated local consent and isolated remote-transport evidence are recorded
in QUALITY.md; the full access-control/portability/rollout matrix remains open.

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
Collection names are server-controlled and must not expose user IDs. Dense-only
point payloads contain identifiers and metadata by default; text stays in SQL.
The optional native full-text generation copies chunk text to Qdrant only when
the generation was created with explicit consent and the separate build consent
is repeated on start/resume. Its distinct payload is removed by chunk tombstones
or generation erasure and is verified against SQL before activation. Qdrant
1.20+ BM25 support is capability checked; older servers remain on SQL lexical
retrieval.
Query endpoints cannot accept arbitrary tenant filters from clients.

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
  implemented, now including the opt-in prepared-point worker and canonical source
  mutation/deletion journal. Backfill, bulk throughput and continuous generation
  reconciliation remain pending.
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
remaining cleanup continues. This prevents newly orphaning chunks in this path;
it does not retroactively identify owners of historical orphans. Version 1.18.39
adds the durable encrypted-identity retry queue for incomplete local cleanup.

### Stage 06 generation schema and contracts

The 1.18.0 additive migration, immutable VectorGeneration, tenant-bound repository,
VectorStore/SearchFilters contracts and explicit prepare/list/fail OCC operations
are implemented. Old chunks remain legacy/unassigned and SQL retrieval remains
the default. SQL activation requires current verification; Qdrant canary
activation is explicit and has a SQL rollback. Local generation metadata joins
account cleanup; durable remote tombstones precede any remote writes.
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

Normal document insertion extends any queued, running, paused or staged
generation build in the same owner-fenced transaction. Late-committing rows
rewind a cursor and reclaim a stale lease, while safe tail appends preserve the
current document and provider request. Final staging rechecks under the owner
fence. Canonical in-place metadata changes also rewind eligible builds and
remove stale generation chunks/manifests under the owner fence. Enrolled users
now receive automatic SQL-generation provisioning, including a public-text
bootstrap probe for unchanged legacy indexes; the bounded builder, verifier and
activation job switch retrieval to the verified SQL generation. Normal source
mutations queue bounded generation updates, and search falls back to legacy SQL
while those updates are pending. Deleted-source SQL chunks and configured
Qdrant points use the canonical deletion path. Remote write/load/isolation tests,
container startup and production portability/soak evidence remain open; those
gates are not proved by syntax checks or the local bundle build.

Normal indexed-file flushes now recognize a matching active SQL generation and
let the transactional mutation journal extend it instead of provisioning a
second full backfill. Vectors just validated for exact chunk text can seed only
the matching generation-scoped cache, reducing duplicate embedding requests in
the bounded builder/mutation workers. Model, endpoint, dimension or chunk-setting
changes still produce a separate immutable generation; an explicitly selected
Qdrant primary is not displaced. Provider calls and chunk SQL writes retain their
existing bounded worker boundaries.

### Stage 08 durable journal and target foundation

The 1.18.1 schema, encrypted target registry, immutable storage binding, transaction-
required enqueue, CAS leases/acknowledgements, bounded retry/dead-letter primitives
and offline OCC inspection/requeue are implemented. Account SQL erasure and bound
collection deletion intent commit together. Legacy per-user cleanup is keyset-
bounded and reset now preserves chunk ownership until chunk deletion completes.

The normal indexer now routes stale-file, excluded-file, folder-prefix and
successful replacement deletion through the canonical source-deletion
transaction. SQL generation rows are removed with their source; Qdrant tombstones
are durably enqueued without remote calls on the index/chat path. Generation
upserts from normal indexing, reset/rename integration, remote revision/lease
hardening and target retention/pruning remain pending. Durable local account
cleanup retries are implemented; remote generation deletion remains on its
separate outbox. VECTOR-OUTBOX.md distinguishes these implementation gaps from
the queue primitives. No delivery/crash/concurrency or live migration gate is
verified.

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
No unblock/prune or remote-preparation reconciliation exists yet. Durable local
account-cleanup retries are implemented separately from the vector outbox. SQL
writes and chunk consumers are still pending; no remote activation, migration
lifecycle or concurrency gate is verified. Privacy documentation now distinguishes local
extraction/storage from provider-dependent inference and retained deletion state.

### Stage 08 isolated building chunks and atomic upsert intent

GenerationChunkRepository now stores separate building-generation rows in the
existing chunk table, preserving NULL-generation legacy text/vectors. It locks
owner/generation/source, rejects stale document stamps, validates prepared vectors
and commits row changes plus monotonic journal revisions atomically. Repeated
identical slots are no-ops; the 1.18.3 unique slot index supports bounded lookup.
All nine legacy read/count paths explicitly exclude generation rows, and later
delivery hydration checks ownership, revision and current source metadata. Native
administration displays an offline preparation count, without enabling delivery.

The live Indexer/backfill does not invoke this internal writer yet. General chunk
deletion/metadata invalidation/reset integration, point consumers, actual source/
provider-pinned backfill and verification remain pending. GENERATION-CHUNKS.md
records those boundaries and storage overhead. Syntax/build checks do not verify
SQL lifecycle, concurrency, isolation or rollout readiness.

### Canonical source-deletion journal

Chunk/document mapper deletion now allocates terminal point-delete intent and
removes canonical rows in one source-locked transaction. Bounded keyset batches
cover replacement, Files/Mail/Talk and folder deletion, reset and account cleanup;
the deferred IndexJob reset no longer issues raw chunk/document DELETE statements.
Both native admin surfaces explain that point cleanup remains queued while
delivery is paused; the later 1.18.7 consumer requires explicit consent. SQL-only deletions never create vector events or make
remote calls. Source-stamp field updates and tenant-filtered hash clearing also invalidate
prepared entries transactionally, while preserving legacy chunks. Rebuilding
those entries, backfill and activation remain pending. Point consumption is
delivered by the later 1.18.7 slice. Source lifecycle transaction/rollback checks
and isolated point-transport evidence are recorded in QUALITY.md; distributed
concurrency remains open.

### Prepared-point consumer and consent

The 1.18.7 slice adds bounded point delivery, distinct opt-in consent in native
administration, fresh owner/source/target/lease guards and revision-bound SQL
acknowledgement. SQL retrieval and ordinary indexing remain the default. Isolated
SQLite vector migrations and real Qdrant 1.16 transport/reordered writes/tombstones
have passed. Native source-access and disable/stale-lease guards have unit coverage.
Representative scale, multi-version/HA, true concurrent interruption, cross-database
matrix, backfill and search activation are still open gates; transport delivery
is not completion of the report or verified migration.

### Immediate chat consistency corrections (1.18.8)

Completed turns now share one revision-guarded/idempotent write instead of two
whole-file rewrites. Bounded client waits, regeneration persistence ordering and
stale-token rejection close the reviewed readiness races. This is a transitional
AppData correction; stage 13 normalized encrypted SQL messages, migration and
recovery remain pending. Large browser-dismissal durability is also not proven
by fetch keepalive.

### Stage 09 first slice: pinned generation embeddings (1.18.9)

A separate generation adapter sends exact model requests to Ollama or an existing
OpenAI-compatible profile and validates named model, dimension, magnitude and
complete input-index correspondence. Fresh SQL profile/encrypted-key snapshots
are checked at transfer time; model fallback and implicit truncation are absent.
Proven cache records can be reused across generations with identical owned
embedding identity, but legacy/fallback records are not guessed into that space.

Native administration and OCC expose an explicit public-text compatibility check
for existing building generations. Metadata reads do not probe providers. This is
preparation for the bounded document backfill, not a completed backfill worker or
rollout gate. Durable cursors/leases/control, canonical source extraction and full
remote verification remain pending. Details and protocol sources are recorded in
[GENERATION-EMBEDDINGS.md](GENERATION-EMBEDDINGS.md).

### Native account-cleanup correction (1.18.9)

A real account-delete drill exposed an unsupported `distinct()` call in the
bounded legacy chunk cleanup. It prevented SQL erasure while leaving source
ownership available to the scheduler. The query uses native `selectDistinct()`
now, and cleanup never reopens a deleted home. Actual MariaDB tenant isolation,
chunk/document cleanup, native user deletion, AppData removal and repeat cleanup
have passed locally; known disposable audit remnants were removed and checked.
This advances the sequential delete drill in stage 14. Version 1.18.39 adds
durable local retry coordination; in-flight legacy-writer fencing and
distributed/remote erasure remain pending.

### Stage 09 source-input slice: shared canonical extraction

The ordinary Indexer now delegates parsers and Files/Mail/Talk text assembly to
shared services. Generation source preparation rechecks native access, reconstructs
the complete canonical source, requires its SQL content hash and chunks with
immutable generation settings. File stream reads and generated chunk counts are
bounded; text beyond the separate agent excerpt is preserved. A deterministic
plan hash binds source/generation/chunker/content. Streaming chunk boundaries also
fix oversized headings and overlap overruns in ordinary indexing.

Actual installed DI/VFS/SQL preparation and changed-file rejection have passed
locally. This supplies the input used by the following durable-worker slice.
Source planning alone does not constitute a completed or verified migration.

### Stage 09 durable builder and native control (1.18.10)

Explicit administrator/OCC control now schedules tenant-bound keyset processing
with persisted document/chunk cursors, source manifests, heartbeats and exclusive
leases. Batches commit prepared chunks, vector intent and progress atomically;
pause/cancel invalidate the token, and stale processes cannot overwrite successors.
Fresh personal index enrollment is checked before transfer/checkpoint, and account
erasure/export include the new progress/manifests. Native controls show prepared
counts and never imply verified or active search. Nextcloud administration links
to the same working controls.

Canonical extraction is reused within a tick; high overlap is bounded by chunk
count and generated bytes, with UTF-8-safe provenance budgets. Local conversions
have explicit time/output limits, and Mail skips attachment work outside its
canonical prefix. Actual MariaDB transaction/rollback probes, isolated SQLite
resume/lease/erase scenarios, combined native VFS + HTTP embedding + SQL building
and authenticated native administration are recorded in QUALITY.md.

Stage 09 now has a first persisted verification slice: exact SQL/remote point IDs,
tenant/generation identity, revisions, dimensions, source manifests, deterministic
sampled cosine scores, snapshot reconciliation, exact live counts and invalidatable
certificates. SQL activation and explicit Qdrant canary activation now consume
these certificates. Cross-database and adversarial evidence, representative
load/soak, hard remote-storage/IMAP budgets, full legacy-writer coverage and
durable erasure validation remain pending. Stage 10 has only an explicit admin
probe; production shadow/soak and remaining stages 11–14 evidence remain open.
See [GENERATION-BUILDS.md](GENERATION-BUILDS.md).

The 1.18.11 storage contract adds exact tenant/generation-filtered live counts
and bounded exact-ID point reads with vectors. Version 1.18.12 uses them in the
read-only persisted verifier described in
[GENERATION-VERIFICATION.md](GENERATION-VERIFICATION.md).

### Stage 10 first slice: explicit bounded shadow probe (1.18.13)

The native administrator generation panel now offers a manual comparison only
after current verification. It sends a short sample through the pinned model,
compares top-k SQL dense ranks with filtered Qdrant results, validates returned
SQL revisions and reports aggregate overlap/latency. Chat and answer retrieval
never invoke it; samples, vectors and result IDs are not persisted or logged.
Per-generation concurrency, provider/vector deadlines, SQL scan limits and a
wall-clock guard bound work. This measures one explicit probe; it is not a
production shadow/soak period, hybrid-ranking comparison, canary or cutover.
See [GENERATION-SHADOW.md](GENERATION-SHADOW.md).

### Durable local account-erasure retries (1.18.39)

Account deletion now records a Nextcloud-encrypted identity in a local SQL retry
queue, immediately runs all idempotent cleanup steps, and retries failed work
with a leased Nextcloud background job and bounded exponential backoff. The
queue exports aggregate pending-count and oldest-age OpenMetrics only. Qdrant
collection deletion remains the existing independent vector-outbox operation.
PHP syntax, app XML, and diff checks passed locally; tests, installed retry
drills, cross-database runs, concurrency/restore drills, production evidence,
and remote-vector confirmation remain pending. See
[ACCOUNT-ERASURE.md](ACCOUNT-ERASURE.md).
