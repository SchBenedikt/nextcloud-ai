# Building-generation SQL chunks

Version 1.18.3 adds an internal generation writer and an indexed generation/
document/chunk slot. The writer makes no model or Qdrant requests. Regular SQL
indexing does not produce generation rows. The explicit background builder added
in 1.18.10 uses this mutation boundary; the opt-in point worker delivers prepared
rows. See [generation builds](GENERATION-BUILDS.md) for start/pause/resume and
the verification gates that still prevent search activation.

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
time, size and MIME. The background builder reads the permitted canonical source and uses
the generation's exact provider/model/endpoint and chunking settings. Extraction,
network requests and inference stay outside SQL transactions and the answer path.

Pass that snapshot and an internal `PreparedVectorChunk` to `putBuildingChunk()`.
The DTO/repository bound content, position, tokens, provenance and encoded sizes,
and validate finite nonzero vector dimension and generation chunk size. Reserved
`_eva_vector` provenance stores the source stamp/chunking declaration. These
checks do not prove which model generated an input vector; provider identity
checks and later verification remain required. No upload endpoint is exposed.

SQL-generation embeddings use the versioned `eva64` float64 codec whenever it
stores fewer bytes than JSON. Existing JSON vectors remain readable and are
rewritten by a recurring job in keyset pages of at most 200 rows. The cursor is
stored in app configuration, and each update compares both the original vector
and generation ID so a concurrent index write is preserved. This maintenance
does not call an embedding provider or change vector values; legacy and Qdrant
rows keep their existing JSON representation.

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
metadata/vector without document text. The point worker separately checks its
lease, flags, generation lifecycle/target, core user and source state at the final
transfer boundary. Reading a point does not authorize remote transmission.

Files use canonical relative folder paths for ancestor tokens. Mail/Talk accept
their bounded `mail://`/`talk://` URIs and carry no Files folder tokens. Raw paths
and URIs remain in SQL, outside the Qdrant payload.

## Administration and remaining gates

Both native admin surfaces show the generation-tagged SQL row count separately
from legacy search. A count does not imply delivery, verified migration or an
active index. Count failure makes the shared snapshot unavailable.

Canonical chunk and document deletion now share `VectorSourceDeletion`: it locks
source rows, pages generation chunks in batches of 250, retains immutable target
and generation metadata in `delete_chunk` events, and commits events with physical
SQL removal. The mapper entry points cover document replacement, Files/Mail/Talk
purges, folder deletion, per-user/all-user reset and account cleanup. Document
removal repeats the guarded chunk cleanup in the same transaction, closing the
window between a caller's earlier chunk deletion and its parent-row deletion.
Global chunk reset also scans orphan document IDs; generation-tagged orphans need
valid retained generation/target metadata or deletion fails closed. SQL-only rows
produce no vector events. Deferred cron reset uses this canonical reset path.

A failed enqueue rolls back its batch's SQL deletion. Large resets commit bounded
batches and can be retried; they are not one atomic whole-index operation unless
the caller already supplies an outer transaction. The SQL source-row lock also
makes concurrent staged writers recheck their source after deletion. No provider
or vector request runs during these transactions. The point worker supersedes stale events and must not recreate a collection
after generation erasure.

Source updates now use the same source-row transaction boundary. Changes to the
fields used by `VectorDocumentSnapshot` (including path, source, content hash and
mtime) queue terminal tombstones and remove only the affected generation chunks.
Legacy chunks remain searchable. Updates limited to chunk counts or indexing
timestamps avoid that boundary, and identical source metadata is a no-op for
invalidation. Ownership reassignment is rejected and rolled back. Bulk hash
clearing uses bounded tenant-filtered batches through this boundary too.

An in-progress build reconstructs its current source and restarts that document
if the plan changed. Changes behind its completed cursor still require later
reconciliation and full verification. Prepared-point consumption is
available through its separate administrator consent, without activating reads. Rebuilding must allocate fresh chunk IDs and
capture the new source stamp; terminal tombstone IDs must never be reused. Resumable source/provider-pinned building is available through explicit consent.
Generation completeness checks and exact-ID/revision verification are available;
quotas, retention, production shadow/primary reads and rollback drills remain pending. Per-generation
text/vector copies temporarily require extra SQL space. No speed improvement or
completed migration is claimed by this internal writer alone.

Native lifecycle and isolated SQL/transport evidence are recorded in QUALITY.md.
Actual concurrent interruption, full database portability, representative
throughput/soak and generation verification remain separate acceptance gates.

Document source stamps accept the existing Indexer MD5 content hashes (and
SHA-256 hashes for compatible future sources). The stamp itself remains SHA-256
over canonical metadata. Chunk integrity hashes remain strictly SHA-256; the
source hash is a change detector, not an authenticity assertion.

## Canonical source preparation

`IndexTextExtractor` now owns the file/blob parsers formerly embedded in Indexer.
`IndexDocumentText` is shared by ordinary indexing and generation preparation,
including Mail envelope formatting, attachments, normalization and the existing
Mail/Talk content limits. Agent file excerpts keep their independent 100,000-
character maximum; generation preparation does not reuse that truncated excerpt.

`GenerationSourceReader` checks native source access before and after extraction
and requires the reconstructed text to match the canonical SQL content hash. It
reads exact owned Mail metadata by ID rather than searching a recent-message
page. A changed transcript, different IMAP fallback, missing attachment, changed
parser configuration or same-size file edit cannot silently become the old source.
An ordinary reindex must reconcile a hash mismatch before it can be rebuilt.

File preparation respects the smaller of the owner's file-size setting and
20 MiB, including a bounded VFS stream read if the file grows after metadata was
captured. Extracted text is limited to two million characters without silently
truncating file content. Existing Mail/Talk canonical limits are part of their
source representation and remain unchanged. PDF/Office commands now have time/output limits; OCR retains its existing
bounded-operation limits. Native remote storage/IMAP transport behavior and
representative concurrency/load still require separate evidence.

`GenerationDocumentPreparer` uses the generation's immutable chunk size/overlap,
never mutable personal chunk settings. It bounds a document to 10,000 chunks and
returns an internal text plan with a SHA-256 identity over chunker version,
generation identity, source stamp and every chunk/provenance record. Sections,
sentences and hard splits are consumed incrementally; a large overlap stops at
the budget instead of materializing an unbounded chunk list. Long headings and
retained overlap no longer exceed the embedding input size. Text plans contain
no inferred legacy embeddings and are not provider authorization or verification.

The source-input slice is used by the explicit resumable builder in
[GENERATION-BUILDS.md](GENERATION-BUILDS.md), including native progress and
pause/resume. SQL search stays active; preparation is not complete verification
or activation. Native runtime evidence is recorded in QUALITY.md.

The optional consumer is documented in [VECTOR-OUTBOX.md](VECTOR-OUTBOX.md#prepared-point-worker-1187).
It transfers only current, accessible prepared rows and marks the matching SQL
revision delivered. Regular SQL retrieval remains active. Reconciliation after source changes and
complete verification remain prerequisites to search cutover. Storage/point
permission and document-processing consent are separate; activation stays
unavailable.
