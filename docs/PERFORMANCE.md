# Workspace performance

These are request and lifecycle improvements, not measured throughput claims.

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

## Backend retrieval timing baseline

Searcher writes an optional debug-level `eva_ai: retrieval timings` event through
Nextcloud's logger. It records `backend=sql_legacy`, `total_ms`, `failed`, result
count, `count_ms`, `index_chunks`, `embedding_ms`, `dense_enabled`,
`candidates_ms`, `candidates`, `hydrate_ms`, `scope_ms`, `scoped_candidates` and
`ranking_ms`. Durations use the monotonic clock and are in milliseconds.

These are per-request measurements, not aggregated percentiles or a Prometheus
exporter. Debug events follow the Nextcloud logging level and retention policy;
no special log level is enabled by the app. Query text, user identifiers, paths,
chunk contents, vectors and exception text are deliberately excluded. Logging
failure is ignored so it cannot mask a successful result or the original error.

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
