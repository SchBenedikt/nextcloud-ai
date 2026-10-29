# EVA backend observability

EVA contributes aggregate metric families to Nextcloud's OpenMetrics
exporter:

| Metric family | Labels | Meaning |
| --- | --- | --- |
| `nextcloud_eva_ai_indexed_items` | `kind=documents|chunks` | Indexed document and chunk totals. |
| `nextcloud_eva_ai_vector_generations` | `backend`, `status` | SQL/Qdrant generations by lifecycle state. |
| `nextcloud_eva_ai_vector_outbox_events` | `operation`, `state` | Actionable pending, processing, and dead vector delivery/deletion queue depth. |
| `nextcloud_eva_ai_vector_outbox_oldest_age_seconds` | `state` | Age of the oldest pending, processing, or dead event. |
| `nextcloud_eva_ai_account_cleanup_pending` | none | Count of durable local account-erasure requests. |
| `nextcloud_eva_ai_account_cleanup_oldest_age_seconds` | none | Age of the oldest durable local account-erasure request. |

EVA exports aggregate counts only. It does not label metrics with account IDs,
file names or paths, query text, chat content, provider URLs, model credentials,
or vector values. The families are collected on demand by Nextcloud's native
OpenMetrics endpoint; EVA does not create a separate public route or background
poller.

Nextcloud controls access to `/metrics` using its `openmetrics_allowed_clients`
system setting. Keep that allowlist restricted to the monitoring network. A
missing or failed family means its source query could not be collected; it must
not be interpreted as a zero-valued healthy queue.

These gauges do not yet provide request latency percentiles, provider failure
rates, SQL timing, indexing throughput, or queue-age histograms. Existing
privacy-preserving debug timing logs remain separate from the exporter. Those
measurements and representative operational dashboards still require follow-up.
