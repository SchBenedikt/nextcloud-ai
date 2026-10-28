# Optional vector storage and generation identity

EVA 1.18.0 adds generation metadata in Nextcloud's existing database. Installation
and upgrade require no vector server, contact no external service and do not
switch retrieval backends. Existing SQL embeddings remain present and SQL search
remains the default. Qdrant integration and controlled cutover are later stack
stages; this schema does not claim to make current semantic retrieval faster.

## Additive schema

`eva_ai_vec_generations` stores immutable user/backend/collection identity,
embedding provider/profile, model, endpoint hash, dimension, cosine metric,
chunk size/overlap and schema version. Lifecycle metadata includes status,
creation/activation/verification timestamps, verification hash and build cursor.
Only building creation and conditional building-to-failed transitions are exposed
at this stage. The activation/verification fields are reserved for the actual
backfill verifier, not a CLI assertion that a remote index is correct.

`eva_ai_vec_state` reserves a single active-generation pointer and retrieval mode
per user. The primary key is user_id, so active selection will have one canonical
place rather than relying on an unenforced unique 'active status' convention.
No row is inserted and no pointer/mode is changed by installation or generation
creation. The later activation service must atomically verify and update this
pointer, retire the previous generation and preserve rollback metadata.

Chunks gain nullable `vector_generation_id`, `vector_state` defaulting to `legacy`
and nullable `content_hash`. No old chunk is assigned a guessed model or
collection. No expensive data backfill is performed during app upgrade. Existing
chunk entities retain their old behavior and default to legacy/unassigned state.
The generation/state/ID index supports later bounded synchronization work.

The schema uses standard Nextcloud DB types and bounded indexes rather than a
PostgreSQL extension. Migration syntax has been inspected; actual lifecycle and
PostgreSQL/MariaDB migration runs remain outstanding while tests are deferred.

## Prepare generation metadata explicitly

Run as the Nextcloud web-server user from the Nextcloud directory:

```sh
php occ eva_ai:vector:generation create alice --provider=ollama --model=nomic-embed-text --endpoint=http://127.0.0.1:11434 --dimension=768
php occ eva_ai:vector:generation list alice
php occ eva_ai:vector:generation list alice --after=GENERATION_ID
php occ eva_ai:vector:generation fail alice --id=GENERATION_ID
```

The model and dimension above are illustrative; explicitly supply the resolved
model and its actual dimension. The command performs no discovery or embedding
request and therefore does not prove the supplied identity matches a running
service. Endpoint URLs cannot contain credentials, query or fragment; only a
SHA-256 endpoint fingerprint is stored. Chunk size/overlap default to the selected
user's settings or can be supplied through `--chunk-size` / `--chunk-overlap`.
Overlap must be smaller than chunk size.

`--provider=openai_compatible --profile=PROFILE_ID` can describe a future compatible
embedding adapter. This prepares identity only; it does not implement that adapter
or enable profile-based embedding selection. Ollama generations have no profile.
The supported external storage backend in this identity version is Qdrant.

IDs are 128-bit server-generated random values. Collection names derive from those
IDs, never user input or account names. Repository lookup, list, failure transition
and erasure always bind user ownership. Listings use at most 50 rows and an ID
keyset cursor. Generation identity is immutable; changing a model, endpoint,
dimension or chunk configuration requires a new generation.

There is deliberately no activation command yet. Creating metadata cannot skip
backfill verification, tenant checks, outbox delivery, measured shadow comparison
or rollback preparation. The complete migration requirements are tracked in
[BACKEND-ROADMAP.md](BACKEND-ROADMAP.md).

Version 1.18.2 adds a lazy owner fence. Creation and binding serialize against
account erasure; a blocked owner cannot be silently reinitialized by a later
request or by recreating the same Nextcloud user ID. Inspect it without remote
requests using `php occ eva_ai:vector:generation owner-status USER`, including
after the core user record has been deleted. The fence is committed before the
erasure transaction, so cleanup rollback retains the block. It is minimal
pseudonymous lifecycle metadata, not an anonymous log. Personal export includes
the stored row in `vector_owner_fence`. No unblock or retention purge is available
in this stage; see VECTOR-OUTBOX.md for the concurrency and recovery boundaries.

## Vector-store boundary

Building text/vectors now have separate generation-tagged SQL rows and stable
chunk IDs. Legacy reads and counts explicitly select unassigned rows, preserving
rollback and preventing mixed-model ranking. The internal writer commits chunks
and upsert revisions atomically; provider-pinned backfill and deletion/consumer
integration remain pending. The 1.18.3 slot index and native preparation counter
are described in [GENERATION-CHUNKS.md](GENERATION-CHUNKS.md). No read mode changes.

The `VectorStore` contract defines generation-bound revisioned upsert, exact chunk
tombstones, search and sanitized health results. Search returns IDs/revisions/scores;
authoritative text and ownership must be hydrated from SQL at the matching revision.
`SearchFilters` accepts canonical relative
folder scope, source and bounded document IDs; it has no tenant or generation
override. Adapters must force tenant and generation from the server-owned
`VectorGeneration` on every operation and reject vectors whose finite nonzero
magnitude/dimension does not match that generation.

The optional adapter and erasure consumer are documented in QDRANT.md and
VECTOR-OUTBOX.md. Before chunk writes are enabled,
SQL chunk updates and durable outbox events must be one transaction; account and
document erasure must generate tombstones before discarding the identity required
for remote deletion. The 1.18.1 journal now retains bound generation deletion
intent transactionally with account SQL erasure and has an explicitly enabled
generation-erasure consumer. General document mutation fencing, point-event
consumption and verification remain prerequisites before live
SQL synchronization. See VECTOR-OUTBOX.md for the current boundaries.

## Rollback boundary

Keep SQL text and JSON embeddings throughout the migration window. These metadata
additions alone can be left dormant. Do not drop generation metadata or the active
pointer to 'roll back' once external writes exist; disable the retrieval flag and
let durable erasure/synchronization retain the information needed to finish work.
App downgrade and schema rollback have not been exercised. No production vector
migration or activation has occurred.

The personal data export also includes `vector_generations`, read through bounded
ownership-filtered keyset pages. This contains metadata and endpoint hashes, not
provider keys or raw endpoint URLs. Metadata read failures fail the export rather
than silently omitting these records. As with the existing export, concurrent
mutations are not a serializable snapshot. Very large histories still contribute
to the final JSON payload size; streaming personal exports remain follow-up work.
