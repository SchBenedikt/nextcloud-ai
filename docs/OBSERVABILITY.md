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

## Duration quantiles

The duration family reports approximate p50, p95 and p99 gauges using nearest
rank over the newest 5,000 positive duration records from the preceding seven
days. The query uses the `operation, created_at` index. The operation and
quantile labels come from fixed allowlists; no account, provider, model, chat,
file or content labels are exported.

Provider chat-completion duration is the provider call duration already stored
by EVA; it is not the whole RAG turn. Tool duration is recorded only for calls
that take at least 100 ms. These quantiles are a bounded recent sample, not a
complete histogram or a full-traffic Prometheus distribution. They do not
represent separate RAG, embedding, SQL, queue-wait or lock-wait stages.
Detailed privacy-safe stage timings remain in optional Nextcloud debug logs and
are not persisted as metric rows.

## Privacy and availability

EVA does not label metrics with account IDs, file names or paths, query text,
chat content, provider URLs, model credentials or vector values. Vector-outbox
metrics use fixed operation/state labels. The families are collected on demand;
they do not add writes to indexing or interactive retrieval requests.

Nextcloud controls access to `/metrics` using its `openmetrics_allowed_clients`
system setting. Keep that allowlist restricted to the monitoring network. A
missing or failed family means its source query could not be collected; it must
not be interpreted as a zero-valued healthy queue.

## Remaining observability work

The PDF's full target still needs separate retrieval-stage/provider/embedding
percentiles, queue-age histograms, distributed trace propagation beyond one
chat request, and production sampling/load evidence. Keep request logging
content-free and measure overhead before enabling additional per-request writes.
