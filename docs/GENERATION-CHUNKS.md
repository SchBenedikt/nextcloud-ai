# Building-generation SQL chunks

Version 1.18.3 adds an internal generation writer and an indexed generation/
document/chunk slot. It makes no model or Qdrant requests and is not called by
the live Indexer, a backfill command or a point worker yet. Chunk synchronization
remains unavailable. Use this mutation boundary when connecting the later builder.

## Preserve rollback data

`eva_ai_chunks` holds separate rows for each generation. NULL-generation rows
remain the legacy SQL index. Building rows have their own auto-increment IDs,
content, vectors, provenance, hash and journal revision. Different chunking/models
can coexist without overwriting legacy embeddings. No old vector gains guessed
model provenance. All nine legacy ChunkMapper read/count paths explicitly select
NULL-generation rows, including ranking candidates, hydration, document previews
and exact benchmark snapshots.

The unique generation/document/chunk-index slot prevents duplicate non-NULL slots
and supports indexed lookup. Legacy NULL rows keep their existing semantics.
Creating the index can scan/lock an existing table; validate upgrade time and
locking on the actual corpus/database. Migration performs no re-embedding. Never
reset chunk IDs or journal sequences: remote tombstones require stable IDs.

## Prepare outside the transaction

Capture an owned source through `GenerationChunkRepository::snapshot()`. Its
stamp covers ID, owner, source, file identity, path/name, content hash, modification
time, size and MIME. The future builder must read the permitted source and use
the generation's exact provider/model/endpoint and chunking settings. Extraction,
network requests and inference stay outside SQL transactions and the answer path.

Pass that snapshot and an internal `PreparedVectorChunk` to `putBuildingChunk()`.
The DTO/repository bound content, position, tokens, provenance and encoded sizes,
and validate finite nonzero vector dimension and generation chunk size. Reserved
`_eva_vector` provenance stores the source stamp/chunking declaration. These
checks do not prove which model generated an input vector; provider identity
checks and later verification remain required. No upload endpoint is exposed.

## Atomic row and journal write

The short transaction locks the open owner, the building generation and the
source document using portable row updates. It requires unchanged immutable
generation identity, fixed storage target and current source stamp. Changed,
renamed, removed, failed, retired or erased sources/generations fail closed.

An existing slot keeps its ID. An identical payload is a no-op; changed data
updates only that generation's row. New slots receive new SQL IDs. Upsert intent
and its allocated revision commit with the row; failure rolls both back. Revisions
must increase and fit 2^53-1. No remote request runs in this transaction.

`pointForDelivery()` reads an owned generation/revision-bound row and its joined
current document. It checks the source stamp, content hash and vector, returning
metadata/vector without document text. A later worker must separately check its
lease, flags, generation lifecycle/target, core user and source state at the final
transfer boundary. Reading a point does not authorize remote transmission.

Files use canonical relative folder paths for ancestor tokens. Mail/Talk accept
their bounded `mail://`/`talk://` URIs and carry no Files folder tokens. Raw paths
and URIs remain in SQL, outside the Qdrant payload.

## Administration and remaining gates

Both native admin surfaces show the generation-tagged SQL row count separately
from legacy search. A count does not imply delivery, verified migration or an
active index. Count failure makes the shared snapshot unavailable.

General deletion paths still physically remove rows by document; durable point
tombstones, metadata invalidation/rebuild and the raw deferred-reset bypass must
be integrated before enabling point consumers. Resumable source/provider-pinned
backfill, generation completeness checks, exact-ID/revision verification, quotas,
retention, shadow/primary reads and rollback drills remain pending. Per-generation
text/vector copies temporarily require extra SQL space. No speed improvement or
completed migration is claimed by this internal writer alone.

Unexecuted gates include database/index lifecycle, concurrent slot/erase/failure
ordering, stale-source rejection, rollback after event allocation, idempotent
repeat, revision exhaustion, legacy read isolation and native counter states.
Tests remain deferred; syntax/build checks do not prove these runtime properties.

Document source stamps accept the existing Indexer MD5 content hashes (and
SHA-256 hashes for compatible future sources). The stamp itself remains SHA-256
over canonical metadata. Chunk integrity hashes remain strictly SHA-256; the
source hash is a change detector, not an authenticity assertion.
