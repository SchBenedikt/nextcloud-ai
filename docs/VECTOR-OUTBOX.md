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
This development stack has not executed remote preparation or migration itself.

## Journal representation

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
case. Those delivery semantics and mutation-path integration are the next stage.

## Account cleanup integration in this stage

Generation retirement, superseding prior events, collection-erasure intent,
chunk/document erasure and generation/active-pointer removal now share one SQL
transaction in account cleanup. Bound generations carry a complete immutable
descriptor and fixed target in the deletion event. A SQL failure rolls this part
back, retaining ownership for a retry. The remaining AppData/config cleanup
retains its previous best-effort behavior; a persistent account-cleanup retry
coordinator remains pending.

Retirement serializes against a concurrent binding of existing generation rows.
Full account tombstones/creation fencing and protections against already in-flight
remote collection preparation are still required before general remote writes.
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
only `delete_generation` operations and leaves all upsert/delete_chunk events
untouched until their revision/tombstone protocol is implemented. No current SQL
ingestion path generates remote point writes.

Every event's immutable descriptor is checked against its owner/generation IDs.
Only absent or retired SQL generations may be erased. Invalid envelopes or unsafe
generation status become dead letters; transient connection errors follow normal
backoff. The worker does not require a deleted account to still exist. Its retained
target and encrypted credentials address the original store. A missing collection
is idempotent success; an HTTP success must otherwise include Qdrant's affirmative
collection deletion result. See the [official collection-deletion API](https://api.qdrant.tech/api-reference/collections/delete-collection).

The first claim observes committed SQL state, then requeues erasure for at least
60 seconds. If a process crashes on that claim, its 120-second lease expires before
reclaim, also providing that grace. Requeuing a dead letter restarts this protocol.
The grace claim counts toward the twelve-claim retry limit. Before transfer, after
DNS resolution/pinning, a callback rereads the two global flags, current lease token
and deadline, and generation status. A reclaimed/superseded/disabled operation
therefore does not start a new transfer. A stale worker cannot acknowledge the
successor's event. Preparation has its own fresh building/target callback.

The grace exceeds current 30-second preparation HTTP timeouts and reduces races
with already-running requests. This is not a remote transactional collection
fence: arbitrary process suspension between the final check and sending, older
unfenced app versions or manual administration can exceed that model. Quiesce
explicit preparation during account erasure. Full account/creation fencing and
reconciliation remain prerequisites for enabling general remote writes; no claim
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
No real collection has been deleted by adding or syntax-checking this worker.

## Validation still required

PHP syntax checks do not establish migration portability, transaction rollback,
claim contention, lease expiry, target/key rotation, crash recovery or erasure
correctness. These tests remain outstanding while the user-directed test pause
is in effect. The full goal cannot be considered complete until those invariants
and the rollout/restore gates in BACKEND-ROADMAP.md are verified.
