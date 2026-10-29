# EVA backend observability

EVA contributes aggregate metric families to Nextcloud's OpenMetrics
exporter. Nextcloud controls the scrape route and access policy; EVA does not
create a separate public metrics endpoint or background poller.

| Metric family | Labels | Meaning |
| --- | --- | --- |
| `nextcloud_eva_ai_indexed_items` | `kind=documents|chunks` | Indexed document and chunk totals. |
| `nextcloud_eva_ai_lexical_index_chunks` | `state=pending|indexed|unsupported|processing` | Chunks by exact-token posting backfill state. |
| `nextcloud_eva_ai_vector_generations` | `backend`, `status` | SQL/Qdrant generations by lifecycle state. |
| `nextcloud_eva_ai_vector_outbox_events` | `operation`, `state` | Actionable vector delivery/deletion queue depth. |
| `nextcloud_eva_ai_vector_outbox_oldest_age_seconds` | `state` | Age of the oldest pending, processing, or dead event. |
| `nextcloud_eva_ai_vector_outbox_age_events` | `operation`, `state`, `age_bucket` | Vector outbox event counts in fixed, disjoint age buckets. |
| `nextcloud_eva_ai_index_queue_age_events` | `age_bucket` | Instance-wide index requests waiting for a scheduler slot, by wait age. |
| `nextcloud_eva_ai_account_cleanup_pending` | none | Durable local account-erasure requests. |
| `nextcloud_eva_ai_account_cleanup_oldest_age_seconds` | none | Age of the oldest durable local account-erasure request. |
| `nextcloud_eva_ai_operation_duration_ms` | `operation`, `quantile` | Approximate recent provider chat-completion and slow-tool duration quantiles. |
| `nextcloud_eva_ai_provider_completion_duration_ms` | `provider`, `quantile` | Approximate successful provider completion latency by fixed provider type. |
| `nextcloud_eva_ai_retrieval_stage_duration_ms` | `backend`, `stage`, `quantile` | Approximate retrieval-stage duration quantiles on successful chat requests. |
| `nextcloud_eva_ai_rag_request_duration_ms` | `outcome`, `quantile` | Approximate end-to-end EVA request duration quantiles for completed, failed and interrupted requests. |
| `nextcloud_eva_ai_index_run_duration_ms` | `mode`, `outcome`, `quantile` | Approximate seven-day duration quantiles for bounded background indexing runs. |
| `nextcloud_eva_ai_index_run_file_chunks_per_second` | `mode`, `outcome`, `quantile` | Approximate file chunk throughput quantiles measured over the file-indexing phase. |
| `nextcloud_eva_ai_embedding_batch_duration_ms` | `provider`, `result`, `quantile` | Approximate indexing/generation embedding batch latency; chat query embedding is already measured in retrieval stages. |
| `nextcloud_eva_ai_embedding_batch_samples` | `provider`, `result` | Success/error counts in the bounded embedding sample. |
| `nextcloud_eva_ai_embedding_cache_hit_ratio` | `provider` | Cache hits divided by hits plus misses in the bounded sample. |

## Duration quantiles

The duration family reports approximate p50, p95 and p99 gauges using nearest
rank over the newest 5,000 positive duration records from the preceding seven
days. The query uses the `operation, created_at` index. The operation and
quantile labels come from fixed allowlists; no account, provider, model, chat,
file or content labels are exported.

Provider chat-completion duration is the provider call duration already stored
by EVA; it is not the whole RAG turn. The provider-specific family reports this
same duration separately for Ollama, Groq and OpenAI-compatible profiles. It
uses a bounded sample of up to 5,000 recent records per type. Profile IDs and
model names are never metric labels. Tool duration is recorded only for calls
that take at least 100 ms. Retrieval stages cover count, query embedding,
candidate retrieval, SQL hydration, scope filtering, ranking and total retrieval.
They are rounded to integer milliseconds and attached to the existing chat usage
row; no additional retrieval write or content-bearing field is added. Only
completed provider chat records with observed stages enter these quantiles.

Duration families use nearest rank over bounded samples from the preceding
seven days. These are bounded approximate gauges, not complete
histograms or full-traffic Prometheus distributions. Embedding batch metrics
cover only background indexing and generation builds; query embedding remains
part of retrieval-stage telemetry and adds no second usage write. Retrieval
stages do not separate SQL dense scanning from lexical selection within the
candidate phase; queue-age and lock-wait durations remain separate work.

Queue age metrics use five fixed, disjoint buckets: under 1 minute, 1–5 minutes,
5 minutes–1 hour, 1–24 hours and over 24 hours. The index scheduler metric
aggregates unique queued users without exporting their IDs. Vector outbox
metrics aggregate by its fixed operation and state allowlists. Nextcloud's
`IMetricFamily` API represents each metric as a scalar sample, and the core
renderer does not emit histogram `_bucket`, `_sum` or `_count` series. These
families therefore expose bucket counts as gauges rather than claiming to be
native Prometheus histograms.

Whole-request duration starts when `RagService` begins processing a normal or
streamed chat request and ends when it returns a final response, confirmation,
error or interrupted stream. It includes retrieval, model calls and tool work;
it excludes browser/network time before EVA receives the request. Each sample
stores only the owning user ID, a fixed outcome, a timestamp and integer
duration in the existing usage table. The user dashboard reads at most 5,000
recent requests total; OpenMetrics reads at most 5,000 rows per outcome from
the preceding seven days. No user or chat labels are exported.
The dashboard shows the requesting account's values and never mixes in other
accounts when an administrator selects a user.

Index-run rows store only user ownership, one of four fixed modes, one of three
fixed outcomes, run duration, processed-item count and successfully written
file-chunk count. File-phase duration is measured separately from whole-run
duration, so Mail and Talk processing in an `all` run does not dilute the file
chunk-throughput denominator. The phase includes file traversal, extraction,
embedding and SQL writes. User and OpenMetrics queries are bounded to the newest
5,000 rows in the seven-day window; existing usage retention and account-erasure
cleanup apply. Item and chunk totals are operational observations for the sample,
not cumulative counters.

## Privacy and availability

EVA does not label metrics with account IDs, file names or paths, query text,
chat content, provider URLs, model credentials or vector values. Vector-outbox
metrics use fixed operation/state labels. The families are collected on demand.
Retrieval stage values share the existing successful chat usage write; scraping
adds no write to indexing or retrieval. Background embedding batches add one
usage row per provider batch with generic model value `embedding` and no document
identifiers or content. These rows use the existing 365-day retention and
account-erasure cleanup.

Nextcloud controls access to `/metrics` using its `openmetrics_allowed_clients`
system setting. Keep that allowlist restricted to the monitoring network. A
missing or failed family means its source query could not be collected; it must
not be interpreted as a zero-valued healthy queue.

Usage records that power the administrator usage dashboard are retained for
at most 365 days, matching its longest report window. A daily background job
removes older rows in bounded batches, and EVA removes a user's rows during
account erasure. These records contain aggregate request metadata and
durations, never prompt or answer content.

## Remaining observability work

Model-provider and optional Qdrant HTTP operations now receive a W3C
`traceparent` header tied to the active EVA chat request. EVA logs the same
trace and request-span IDs without prompts, answers or baggage. The trace flag
is unsampled: this is correlation context, not OpenTelemetry instrumentation.
An SDK/exporter, distinct child spans, database and lock spans, and
representative end-to-end trace evidence remain open. The PDF's representative
quality, sampling and load evidence is also still required. Keep request
logging content-free and validate performance claims on frozen corpora.
