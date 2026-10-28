# Backend migration work ledger

Source: *Technische Backend-Analyse von EVA AI*, 23 pages, dated 28 October
2026. The report audits main `24e7982` and PR #697. This ledger additionally
accounts for the workspace stack #698–#699. Vendor pricing, product lifecycle
claims and estimated latency in the report are not measured EVA results.

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
- 02–14: pending; a stage is complete only when its code and matching operating
  documentation exist. Validation and production rollout remain separately
  recorded. No Qdrant deployment or production data migration has occurred.
