# Account erasure and recovery

When Nextcloud deletes an account, EVA immediately records a durable cleanup
request and begins erasure. It first blocks future index/generation writes and
removes the account's indexed SQL data while queuing any required Qdrant
collection deletion through the existing vector outbox.

EVA then clears the account's embedding cache, encrypted chat history and SQL
chat rows, dirty-file queue, agent state, background chat queue, AI-created file
markers, and per-user settings. Each operation is safe to repeat. A failure in
one step does not prevent the other cleanup steps from running; EVA retains the
cleanup request and retries the complete idempotent sequence from the scheduled
Nextcloud background job with bounded exponential backoff.

The retry record contains only a purpose-separated hash for lookup and a
Nextcloud-encrypted account ID needed to resume cleanup. It contains no chat,
file, prompt, credential, or vector payload. The record is deleted after all
local steps succeed. The blocked vector-owner fingerprint remains as a minimal
fence against stale workers or recreation of the same account ID. Remote
collection erasure continues independently in the vector outbox until its
existing guarded worker confirms it.

The retry queue is visible in aggregate through Nextcloud OpenMetrics as
`nextcloud_eva_ai_account_cleanup_pending` and
`nextcloud_eva_ai_account_cleanup_oldest_age_seconds`. No account IDs or raw
errors are exported.

This mechanism makes failures durable and observable; it does not replace
operator backups or a restore/delete rehearsal. Cross-database and production
recovery evidence remains an open rollout gate.
