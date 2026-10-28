# Durable vector intent and target registry

Version 1.18.1 adds an SQL journal and a target registry. SQL retrieval remains the
installation default. A generation-erasure job is registered but disabled unless
both instance flags explicitly enable it; no chunk write is replicated. Later
worker/writer integration must
satisfy the ordering requirements below before remote indexing is enabled.

## Storage-target identity

Embedding endpoint identity and storage endpoint identity are distinct. Generations
now gain a nullable `storage_endpoint_hash`. Explicit Qdrant preparation first
registers the current instance destination and atomically binds a building
generation to it. A later prepare against a different destination fails rather
than retargeting the existing collection. Changing storage server requires a new
generation or a separately reviewed migration; changing an embedding model already
requires a new generation.

`eva_ai_vec_targets` stores a trimmed endpoint, auth mode and ICrypto-encrypted
key under the endpoint's SHA-256 fingerprint. Reconfiguration of another endpoint
keeps the old target record, so outstanding erasure can still address the old
service with its original credentials. Key rotation for the same endpoint updates
that target's encrypted key. The global `qdrant_enabled` switch still gates every
request, including requests to retained destinations. Identity uses the trimmed
URL without trailing slashes; differently spelled aliases are distinct targets.
TLS, origin allowlists,
certificate checks, DNS pinning and proxy restrictions apply to every target.
Old origins may need to remain approved until their cleanup completes.

Target records are instance infrastructure secrets, never personal settings or
public API responses. No prune command exists yet: dropping a target referenced
by a generation or journal event would strand deletion work. Retention/pruning
must consider both references. An unchanged URL is not proof that a server was
not replaced; restore/backfill verification remains necessary after infrastructure
replacement or volume loss.

On upgrade, a current pre-registry connection can be registered from its existing
encrypted instance configuration during an explicit request/preparation. Old
unbound generation metadata is not assigned a guessed storage destination. If an
operator prepared a collection with an earlier unreleased stack version, prepare
that generation against its original server explicitly before relying on erasure.
Only disposable loopback protocol fixtures have been prepared and populated; no production target or user corpus has been migrated.

## Journal representation

The internal `GenerationChunkRepository` can now commit a building-generation
SQL chunk and its upsert revision in one transaction, after source/owner/identity
checks. Legacy rows remain separate. The opt-in point worker can deliver these
prepared rows; automatic backfill and ongoing generation production remain pending
before a complete-index migration can be offered. See [GENERATION-CHUNKS.md](GENERATION-CHUNKS.md).

`eva_ai_vec_outbox` records operation, owner, generation and fixed target,
immutable generation metadata, optional document/chunk IDs, state, attempt count,
next availability, lease/token and sanitized error code. It stores no document
text, vectors or plaintext keys. A retained deletion event contains the minimal
owner/generation identity needed to address a remote collection after account
metadata is removed; this is operational personal data, not an anonymous audit.

An auto-increment event ID is reserved as a monotonic revision. Do not truncate or
reset the journal sequence. States are pending, processing, delivered, dead and
superseded. Enqueue rejects calls outside an active transaction. The canonical
mutation and its intent must use the same Nextcloud database connection and commit
or roll back together; no HTTP operation belongs inside that transaction.

Claims use a bounded compare-and-swap loop with a random 128-bit token and a
60–600 second lease (default 120). Expired processing rows can be reclaimed.
Acknowledgement/failure must match the current token and processing state, so a
stale claimant cannot acknowledge its successor's work. Retry uses exponential
backoff plus small jitter; after twelve claims the event remains
as a dead letter. Dead letters are not discarded or silently expired. Administrative
requeue is explicit. These are durable queue primitives, not evidence of a tested
crash-recovery or distributed-write protocol.

A lease alone is **not** a remote write-ordering guarantee: an expired worker may
still have an HTTP request in flight. Before consuming chunk events, the adapter
and worker must enforce revisions and deletion tombstones at the remote write
boundary. In particular, an old upsert must not recreate a physically deleted
point. Account/generation erasure must prevent any later automatic collection
recreation. Claims and strong request ordering by themselves do not solve either
case. The adapter now enforces revision predicates, terminal exact-ID tombstones
and deleted=false search filters (QDRANT.md). Canonical deletion/invalidation
producers and the opt-in prepared-point consumer are now integrated. A delivery acknowledgement does not prove a conditional
point matched; verification must compare actual revisions and deletion state.
Journal revisions used for point writes must not exceed 2^53-1, and chunk IDs must
never be reused within a generation.

## Account cleanup integration in this stage

Generation retirement, superseding prior events, collection-erasure intent,
chunk/document erasure and generation/active-pointer removal now share one SQL
transaction in account cleanup. Bound generations carry a complete immutable
descriptor and fixed target in the deletion event. A SQL failure rolls this part
back, retaining ownership for a retry. The remaining AppData/config cleanup
retains its previous best-effort behavior; a persistent account-cleanup retry
coordinator remains pending.

The 1.18.2 owner fence below serializes creation/binding against account erasure.
Protections and reconciliation for already in-flight remote preparation remain
required before general remote writes.
The generation-erasure consumer processes collection deletion only when explicitly
enabled. Preparation rechecks owned building status and fixed target after DNS,
immediately before every HTTP transfer. SQL ingestion is still not connected to
remote writes, and the chunk operations in the journal stay unclaimed.

The legacy per-user chunk cleanup now reads document IDs with bounded 500-row
keyset pages instead of materializing every document ID. Index reset also removes
chunks before documents, fixing the same ownership-join ordering bug as account
erasure. Reset does not yet journal all generation changes; it must be integrated
with the worker/writer stage before remote synchronization can be enabled.

## Inspection and explicit erasure execution

```sh
php occ eva_ai:vector:outbox status
php occ eva_ai:vector:outbox list --state=dead
php occ eva_ai:vector:outbox list --state=pending --after=EVENT_ID
php occ eva_ai:vector:outbox retry --id=EVENT_ID
```

Listings use bounded 100-row keyset pages and omit owner names, generation JSON,
raw URLs and credentials. Retrying changes only a dead letter back to pending;
it causes no network request. Inspection does not enable a consumer or retention
purge. Operators must retain
pending/dead deletion intent until remote cleanup is confirmed; silent expiration
would lose the purpose of the journal.

## Generation-erasure worker

The registered `VectorErasureJob` runs every minute, but first reads
`vector_sync_enabled` and `qdrant_enabled` directly from instance SQL configuration
instead of using a process-local cache. Both must equal `1`; missing flags mean
disabled and no HTTP request. To opt in explicitly:

```sh
php occ config:app:set eva_ai vector_sync_enabled --value=1
php occ eva_ai:vector:outbox run --limit=5
php occ config:app:set eva_ai vector_sync_enabled --value=0
```

An enabled cron job also executes the worker. A manual run is not a dry run. It
can delete the generation collections named by valid erasure events. It claims
only `delete_generation` operations. Prepared-point transport uses the separate
`run --points` path and its own consent flag; ordinary SQL ingestion still does
not automatically backfill generation rows.

Every event's immutable descriptor is checked against its owner/generation IDs.
Only absent or retired SQL generations may be erased. Invalid envelopes or unsafe
generation status become dead letters; transient connection errors follow normal
backoff. The worker does not require a deleted account to still exist. Its retained
target and encrypted credentials address the original store. A missing collection
is idempotent success; an HTTP success must otherwise include Qdrant's affirmative
collection deletion result. See the [official collection-deletion API](https://api.qdrant.tech/api-reference/collections/delete-collection).

The first valid claim persists `erasure_ready_at` and requeues erasure for at
least 60 seconds. This deadline is independent of claim attempts: a crash or SQL
failure before persisting it causes a later claim to start a fresh grace, rather
than skipping it. Legacy rows without a deadline also start a fresh grace.
Requeuing a dead letter clears the deadline and restarts this protocol. The grace
claim counts toward the twelve-claim retry limit. Before transfer, after
DNS resolution/pinning, a callback rereads the two global flags, current lease token
and deadline, and generation status. A reclaimed/superseded/disabled operation
therefore does not start a new transfer. A stale worker cannot acknowledge the
successor's event. Preparation has its own fresh building/target callback.

The grace exceeds current 30-second preparation HTTP timeouts and reduces races
with already-running requests. This is not a remote transactional collection
fence: arbitrary process suspension between the final check and sending, older
unfenced app versions or manual administration can exceed that model. Quiesce
explicit preparation during account erasure. The SQL account/creation fence does
not remove this remote suspension window. Reconciliation remains a prerequisite
for enabling general remote writes; no claim
of a complete distributed-consistency guarantee is made here.

Each invocation claims at most 5 events by default (explicit limit 1–20) and checks
a 40-second soft budget before each claim. Collection deletion uses a 10-second
server/HTTP timeout. DNS resolution uses operating-system resolver limits, so this
is not a hard wall-clock watchdog. Metrics returned to CLI count claimed, deferred,
delivered, failed and lost-lease events. Logs contain only event IDs and sanitized
reason codes. Retention purge is still not enabled; delivered/dead/superseded
records require the later explicit retention/erasure policy.

Required verification remains outstanding: disabled flags cause no HTTP, first
observation grace, crash/reclaim, post-DNS disable/lease checks, active-generation
refusal, malformed-envelope dead letters, original-target rotation and 404 retries.

## Durable account fence (1.18.2)

`eva_ai_vec_owners` lazily records a purpose-separated SHA-256 owner fingerprint,
open/blocked state, a row-lock revision and last-change timestamp. Installing the
app creates the table only; it does not enumerate users or prepare collections.
Generation creation and target binding hold the same owner's row lock until their
SQL transaction commits. Updates increment the revision to avoid backend-specific
zero-affected-row behavior from no-op updates, without relying on the row-locking
API added in Nextcloud 33. Nextcloud's portable insert-ignore handles concurrent
first initialization without replacing an existing blocked row.

Account cleanup first commits a blocked fence in its own short transaction, then
runs the existing retirement/journal/index erasure transaction. Failure in that
second phase retains ownership/deletion data for retry but does not reopen the
fence. Erasure must therefore start outside another SQL transaction; an outer
transaction would make the supposedly durable first commit just a savepoint.
Concurrent creators/binders either commit before the fence and are covered by the
subsequent cleanup scan, or observe the blocked state and fail with `owner_erased`.
The final post-DNS preparation guard reads owner-open, generation-building and
fixed-target state in one SQL query. Missing owner state denies preparation;
explicit rebinding/preparing initializes an existing legitimate building owner's
state. Reading status never creates or reopens a fence.

Inspect without network I/O, including after the core user record is gone:

```sh
php occ eva_ai:vector:generation owner-status alice
```

The command reports open, blocked or uninitialized, not an account name/hash.
Normal generation listings also show owner state. The personal export adds
`vector_owner_fence` with that user's stored fence metadata, or null if absent.
Fingerprints are pseudonymous personal data, not encryption or anonymous records.

The EVA admin view and native Nextcloud administration table show a blocked-owner
notice for accounts represented in their index/enrollment overview, including
retained document ownership after failed cleanup. Missing status is visibly
unavailable, not open. Bulk reads use at most 100 fingerprints per SQL query and
perform no network probe. Fully erased users need not appear in those overviews;
the read-only owner-status command can still inspect their fence. Neither view
offers an unblock control.

Blocked rows deliberately survive account erasure and cannot be reset by create,
configure, prepare, retry or app disable/enable. Recreating the same Nextcloud user
ID does not silently inherit/reopen its previous vector lifecycle. SQL chat/search
can still operate, but optional vector generation creation remains blocked until
a separately reviewed fresh-owner/cleanup-verification workflow is implemented.
There is no unblock/prune command in this stage. Historical erasures before this
migration are not retroactively inferred; reconcile old destinations before any
live cutover. A durable account-cleanup retry coordinator and remote preparation
reconciliation remain pending. No in-flight request can be recalled by a SQL row
check alone; the earlier grace and suspension limits still apply.

Required unexecuted checks include concurrent first initialization, creator-before-
eraser and eraser-before-creator orderings, bind/erase races, cleanup rollback with
a still-blocked fence, direct transfer checks, UID recreation, personal export and
PostgreSQL/MariaDB/SQLite lifecycle behavior. No remote indexing is enabled here.
No real collection has been deleted by adding or syntax-checking this worker.

## Validation still required

PHP syntax checks do not establish migration portability, transaction rollback,
claim contention, lease expiry, target/key rotation, crash recovery or erasure
correctness. These tests remain outstanding while the user-directed test pause
is in effect. The full goal cannot be considered complete until those invariants
and the rollout/restore gates in BACKEND-ROADMAP.md are verified.

## Source deletion journal

`VectorSourceDeletion` is the canonical chunk/document deletion boundary used by
both mappers. It locks the source rows and writes one terminal `delete_chunk`
intent per generation-tagged SQL chunk before removing data, with monotonically
newer revisions and the generation's retained storage target. Document deletion
includes a second guarded chunk cleanup so a concurrent staged writer cannot
leave a new orphan between separate mapper calls. SQL-only deletions create no
remote work. Per-user/all-user reset and deferred cron reset use the same path.

Each bounded batch rolls back if intent cannot be retained. The point worker
consumes these tombstones only after explicit consent. Pending intent is not proof
of remote erasure. It discards stale upserts, rechecks flags/lease/source/lifecycle
at transfer time, and supersedes retired/missing-generation point events only
when durable collection-erasure intent exists. Whole-account generation erasure
continues through its separate worker.

Metadata mutations through `DocumentMapper::update()` and per-user hash clearing
are source-locked and compared against the stamp fields. A changed source queues
terminal delete events and removes its prepared generation rows in the same
transaction as the metadata update. It preserves legacy chunks and never embeds
or contacts Qdrant in the metadata transaction. Identical values and publication-
only fields (`chunk_count`, `indexed_at`) do not invalidate prepared data. Moving
a document to another owner is rejected. A later backfill must rebuild changed
sources with fresh point IDs; current code does not claim automatic rebuilding.

## Prepared-point worker (1.18.7)

`VectorPointJob` invokes the bounded worker through Nextcloud cron. Default work
is at most 100 claimed events and a 30-second loop budget; a final in-progress
HTTP request may use its existing 30-second timeout. Each event has a 120-second
lease. Current dispatch is one point per request; bulk throughput and representative
load remain follow-up gates. The worker never embeds, scans files for content,
creates collections or switches retrieval.

Point traffic requires all three fresh instance flags: `qdrant_enabled`,
`vector_sync_enabled` and `vector_points_enabled`. The new points flag is absent
by default, including upgrades. Native administrator consent enables it and the
generation-cleanup flag. Disabling points leaves generation cleanup available;
"Disable storage requests" sets the transport flag off for all operations.
Connection configuration alone does not enable points. Allowing a connection can
resume already enabled workers, including cleanup on retained destinations.

```sh
# After explicit connection setup, collection preparation and point consent:
php occ eva_ai:vector:outbox run --points --limit=100
# Pause points while keeping separately enabled collection erasure available:
php occ config:app:set eva_ai vector_points_enabled --value=0
```

Claims include only upsert/delete_chunk, never collection erasure. A revision-bound
upsert rereads its owned SQL row, immutable descriptor/target and source stamp.
Fresh Files readability/path/mtime/size, Mail mailbox/account ownership and Talk
membership are checked through native services. Source checks do not extract
content; external VFS metadata can still use its storage backend. The same guard
runs after DNS pinning immediately before HTTP transfer. Uploads require the
current Qdrant target; only cleanup may use retained old targets. Endpoint changes
pause old uploads rather than sending fresh vectors to a previous server.

Deleted-source tombstones need no live file but still require valid generation,
owner and target state. A failed generation rejects new upserts but may receive
cleanup. Retired/missing metadata without durable collection-erasure intent is
an inspectable retry/dead-letter condition, not successful cleanup. Neither
point operation recreates a missing collection. Missing collections retry until
prepared or superseded by terminal generation erasure.

Successful transfer and revision-matching SQL `vector_state=delivered` update in
one short acknowledgement transaction with lease-token CAS. A stale event cannot
mark a newer chunk revision delivered. "Delivered" means the conditional request
completed, not that every filter matched or the generation is verified. Stage 09
must compare remote IDs, revisions, deletion states and completeness before any
activation. Native Mail/Talk ACL integration is unit-tested; real IMAP/Talk and
distributed failover/load still require configured runtime evidence.

`tests/runtime/point-transport.php` uses an isolated in-memory SQLite connection,
additive vector migrations and a disposable loopback Qdrant 1.16.0 instance. It
checks explicit consent, actual native HTTP transport, current revision filtering,
terminal tombstones and query isolation; collections are removed afterward. Its
source-access double isolates protocol tests from real user accounts. Live app
configuration and SQL data are never enabled or written by that probe.
