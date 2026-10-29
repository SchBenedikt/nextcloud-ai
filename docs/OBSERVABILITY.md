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
| `nextcloud_eva_ai_account_cleanup_pending` | none | Durable local account-erasure requests. |
| `nextcloud_eva_ai_account_cleanup_oldest_age_seconds` | none | Age of the oldest durable local account-erasure request. |
| `nextcloud_eva_ai_operation_duration_ms` | `operation`, `quantile` | Approximate recent provider chat-completion and slow-tool duration quantiles. |
| `nextcloud_eva_ai_provider_completion_duration_ms` | `provider`, `quantile` | Approximate successful provider completion latency by fixed provider type. |
| `nextcloud_eva_ai_retrieval_stage_duration_ms` | `backend`, `stage`, `quantile` | Approximate retrieval-stage duration quantiles on successful chat requests. |

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
histograms or full-traffic Prometheus distributions. Retrieval stages do not
separate SQL dense scanning from lexical selection within the candidate phase;
queue-wait and lock-wait durations, plus indexing-embedding distributions,
remain separate work.

## Privacy and availability

EVA does not label metrics with account IDs, file names or paths, query text,
chat content, provider URLs, model credentials or vector values. Vector-outbox
metrics use fixed operation/state labels. The families are collected on demand.
Retrieval stage values share the existing successful chat usage write; scraping
adds no write to indexing or retrieval.

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

The PDF's full target still needs embedding-provider percentiles, queue-age
histograms, distributed trace propagation beyond one chat request, and
representative quality, sampling and load evidence. Keep request logging
content-free and validate performance claims on frozen corpora.
