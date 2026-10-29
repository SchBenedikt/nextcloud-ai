# Staged vector generation verification

Verification is a bounded reconciliation for an administrator after a
generation build reaches `staged`. The verifier itself is read-only and never
requests embeddings or sends source text to a provider. A current SQL
certificate allows automatic SQL-generation activation; a current Qdrant
certificate allows an explicit administrator canary with direct SQL rollback.
Verification follows the generation backend: SQL verifies local chunks and
source manifests; Qdrant additionally reconciles remote points.

## Checks performed

The durable cursor records an owner and generation, the build snapshot's highest
document ID, the expected SQL point count, phase, keyset cursors, bounded progress,
lease and heartbeat. Each 60-second background tick claims one expiring lease and
processes at most 20 batches, each limited to 100 points and an 80,000-value
vector budget. HTTP calls run outside SQL transactions; short owner/generation
fenced transactions save checkpoints.

For every generation, the worker checks revision, content hash, vector shape and
current source manifests. For Qdrant it also retrieves every exact point ID,
checks tenant and generation identity, live status, source document, revision and
content hash, samples cosine similarity and reconciles the exact live count. SQL
generations stay local and require no remote reads. Sources created beyond the
snapshot, changed manifests, changed SQL point counts, missing remote points or
a mismatched remote count fail the run closed.

The final certificate hashes the ordered point and source-manifest evidence,
expected/observed counts and sample count. It is written only after every phase
completes under the current lease and generation identity. Any canonical source
insert, mutation or deletion clears the certificate and marks outstanding
verification stale in the same SQL transaction. Account erasure fences source
inserts and chunk writes; verification metadata is included in personal export
and cleanup.

## Controls and operations

The administrator generation page exposes start, pause/resume, retry and progress
using native Nextcloud components. `GET` and `POST`
`/api/admin/vector-generations/{userId}/{id}/verification[/{operation}]` are
administrator-only. The matching OCC command is:

```sh
occ eva_ai:vector:verify status <user-id> <generation-id>
occ eva_ai:vector:verify start <user-id> <generation-id>
occ eva_ai:vector:verify run
```

The scheduled job is inert until a staged generation has a queued verification.
SQL verification requires the owner index to remain enabled. Qdrant verification
also requires the optional vector backend and point delivery. A Qdrant outage is
reported as a failed/retryable run; it does not delay chat requests. Source
changes require preparing a fresh generation rather than
certifying an obsolete snapshot.

## Evidence boundary

The focused worker tests cover a matching point/source certificate and a missing
remote point failing closed. Cross-database and adversarial testing, long-run
throughput, remote outage recovery under load, complete source-writer coverage,
shadow-read quality and rollback soak remain backend roadmap gates.
