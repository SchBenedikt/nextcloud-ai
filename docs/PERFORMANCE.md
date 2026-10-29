# Workspace performance

These are request and lifecycle improvements, not measured throughput claims.

## Cached exact vector norms

Exact SQL cosine retrieval still visits every eligible vector, but each vector
no longer needs a second norm pass on every query. EVA stores the exact vector
magnitude beside each chunk, writes it with new legacy and generation chunks,
and gradually fills the value for existing rows in bounded background pages.
Rows without a valid cached magnitude retain the previous calculation path, so
the rollout does not require a synchronous full-table migration. The ranking
formula and candidate coverage are unchanged; this reduces per-query arithmetic
but does not remove the O(N × dimension) dot-product scan or establish a latency
improvement. A portable ANN backend and representative benchmarks remain needed
for large indexes.

## One-pass lexical scoring

Within the bounded retrieval candidate pool, EVA now tokenizes each chunk body
once and counts only the query's exact Unicode word tokens. Previously each
query term rescanned every candidate body with substring counting, so a query
with several terms revisited the same text repeatedly and could match partial
words. This lowers repeated text work and makes body term frequency match the
query tokenizer. A portable SQL term-postings table now selects exact-token
candidates by indexed SHA-256 term keys and supplies stored frequencies directly
to BM25. Normal and generation chunk writes mark their lexical state pending;
a bounded background job builds postings after those writes commit and backfills
existing rows. This keeps token expansion off the file-index write transaction.
A mixed index uses postings immediately for completed chunks and runs the
legacy `LIKE` query only for pending or unsupported chunks. Candidates are
merged and bounded in stable document/chunk order. Exact-token semantics take
effect as backfill completes, so retrieval quality during catch-up needs a frozen
corpus comparison. This does not remove the dense SQL scan; migration portability
and retrieval latency still need
measurement against a frozen corpus.

A local microbenchmark over 8,000 synthetic 896-byte chunks and 12 query terms
reported a 30.3 ms median for exact-token extraction versus 40.0 ms for repeated
substring counting (7 runs, same PHP process). This isolates term-frequency
extraction; it does not include SQL, fusion, hydration, embeddings, or a real
corpus and is not a full-search latency claim.

## Home

Home receives the navigation's chat list as props. It makes no separate `stats`
or `greeting` request. This avoids a second chat-store read, document aggregates
and full model-status construction on that screen. Both routes remain available
to existing API clients.

## Settings

Models initially loads core settings. History, personal knowledge, connectors,
extensions, keys and diagnostics load on first entry into their section.
Independent optional resources load concurrently. `useEvaSettings` owns form
state; section components render it.

A full status response containing model information no longer triggers another
initial model-discovery request. Editing the endpoint still discovers models
on the new server.

There is no idle settings polling. During indexing, a visible settings page
checks progress every ten seconds with an in-flight guard. Hidden tabs skip
those checks.

## Index progress

`GET /api/index/status` reads the user's indexing flags and timestamps. It keeps
abandoned-worker recovery but does not initialize knowledge, count documents,
read chats, discover models or run OCR diagnostics. Documents uses this route
instead of full system status too.

## Chat resources

The native sidebar uses updated-time/chat-ID keyset pages after normalized SQL
chat storage is active. Each page decrypts only the requested conversation
metadata and never reads message payloads; it does not materialize every chat
before slicing the response. AppData accounts and older clients retain the
existing offset-compatible path until SQL reads are enabled. Chat content search
remains a separate, explicit full-text walk because message bodies stay
encrypted and are not indexed in plaintext.

The wrapper destroys its renderer when replaced or unmounted. Destruction
hands an active request to the existing durable background flow, removes the
page-hide listener, aborts the browser stream, stops speech, clears its timer,
cancels scheduled rendering and unmounts the actions app. Background-status
polls skip hidden tabs.

The background-task list polls active runs every ten seconds, slows idle lists
to once a minute and skips hidden tabs. It also prevents overlapping requests.

## Verification

Compare route counts in the browser Network panel. Measure server and provider
latency separately. Mocked browser tests prove routing and behavior, not real
inference speed. Workspace tests verify absent home diagnostics and deferred
advanced settings. Controller tests assert that progress does not call expensive
services.

## Settings requests

Capability validation can read a proposed Ollama endpoint directly. It no longer
writes and restores the user configuration just to test model roles. Cancelled
HTTP requests are not retried. Independent settings resources are split into
focused composables and loaded on demand.

## End-to-end request latency

Whole EVA request latency is recorded separately from provider and retrieval
durations. Normal requests and streamed requests measure from the start of
`RagService` through final answer, confirmation, error or interrupted stream;
the sample includes EVA retrieval, model and tool work, but not browser/network
time before the request reaches EVA. Values are exposed as per-outcome p50/p95/p99
in the existing usage metrics view and CSV, and as seven-day OpenMetrics gauges
from a bounded 5,000-sample query. Only a fixed outcome, user ownership, time
and duration are stored. This is operational latency telemetry, not a frozen
corpus benchmark or a Prometheus histogram.

## Backend retrieval timing baseline

Searcher writes an optional debug-level `eva_ai: retrieval timings` event through
Nextcloud's logger. It records the fixed retrieval backend (`sql_legacy`,
`sql_generation` or `qdrant_generation`), `total_ms`, `failed`, result count,
`count_ms`, `index_chunks`, `embedding_ms`, `dense_enabled`,
`candidates_ms`, `candidates`, `hydrate_ms`, `scope_ms`, `scoped_candidates` and
`ranking_ms`. Durations use the monotonic clock and are in milliseconds.

These remain per-request detail logs. Normal, streamed, and queued chat work receives a random, short-lived
`trace_id` and request `span_id`, attached to the chat total, retrieval stages,
provider duration, and slow-tool duration events. Configured model providers
and optional Qdrant receive a W3C `traceparent` HTTP header on inference,
embedding and vector operations. The header carries correlation IDs only; its
sampled flag is clear, and EVA sends no baggage. Request bodies and chat
responses are unchanged. These IDs provide correlation context without an
OpenTelemetry SDK, exporter or distinct child spans. Queued work keeps the trace
only until completion or expiry. No prompts, account identifiers, paths, chunk
contents, vectors, or provider response bodies are part of these telemetry
events. Debug events follow the Nextcloud logging level and retention policy; no
special log level is enabled by the app. Logging failure is ignored so it
cannot mask a successful result or the original error.

Version 1.18.72 also stores reached retrieval stages on the existing usage row
for a successful provider chat completion. Nextcloud OpenMetrics exports
approximate seven-day p50/p95/p99 values for count, embedding, candidates,
hydration, scope, ranking and total retrieval, labelled only by a fixed backend
and stage. The exporter reads at most 5,000 recent chats. These integer-ms
quantiles cover successful chats with a measured stage; they are not a complete
histogram and are not evidence for the PDF's corpus-quality or load targets.

Early empty-query/empty-index returns and failures emit only the stages reached.
`dense_enabled=false` also covers an unavailable query embedding. Candidate time
currently includes SQL dense scanning and lexical candidate selection; ranking
includes BM25, phrase boosting, dense reranking, fusion and diversification.
Do not interpret either number as an isolated vector-database or BM25 latency.
Stage separation can become finer when those operations gain independent adapters.

For a representative baseline, collect the debug events with a frozen corpus and
query set and record hardware, model, index size, concurrency and warm/cold cache
state. Obtain p50/p95/p99 from that sample; do not infer them from one request.
See [the backend migration ledger](BACKEND-ROADMAP.md) for quality and cutover gates.
