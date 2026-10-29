# Staged vector generation verification

Verification is an explicit, read-only reconciliation for an administrator to
run after a generation build reaches `staged`. It is not a search migration:
SQL remains authoritative and active, no generation activation is exposed, and
the worker never requests embeddings or sends source text to a provider.

## Checks performed

The durable cursor records an owner and generation, the build snapshot's highest
document ID, the expected SQL point count, phase, keyset cursors, bounded progress,
lease and heartbeat. Each 60-second background tick claims one expiring lease and
processes at most 20 batches, each limited to 100 points and an 80,000-value
vector budget. HTTP calls run outside SQL transactions; short owner/generation
fenced transactions save checkpoints.

For each SQL chunk, the worker retrieves that exact point ID and checks its
tenant and generation identity, live status, source document, revision and
content hash. It validates both vector dimensions and finite values, then checks
cosine similarity for the first point and every 64th ID. SQL source manifests
are compared with current source stamps and generation chunk counts. Sources
created beyond the snapshot, changed manifests, changed SQL point counts, missing
points or a mismatched exact live remote count fail the run closed.

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
It requires the optional vector backend, point delivery and source indexing to
remain enabled. A storage outage is reported as a failed/retryable run; it does
not delay chat requests or change SQL retrieval. Source changes require preparing
a fresh generation rather than certifying an obsolete snapshot.

## Evidence boundary

The focused worker tests cover a matching point/source certificate and a missing
remote point failing closed. Cross-database and adversarial testing, long-run
throughput, remote outage recovery under load, complete source-writer coverage,
shadow-read quality and search activation remain backend roadmap gates.
