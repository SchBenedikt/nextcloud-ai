# Resumable background generation builds

EVA can build an explicitly prepared, owned **building** generation from the
owner's canonical indexed sources. SQL generations are stored entirely in the
Nextcloud database and need no vector service. Qdrant remains an optional
external scale path. The legacy SQL index stays active until a later verified
cutover. Installing or upgrading EVA starts no build and transmits no documents.

## Start and control

Prepare a generation with the immutable model, dimension, endpoint and chunk
settings matching the intended embedding space. The `sql` backend is the
default and stores vectors locally in Nextcloud SQL. Select `qdrant` only when
that optional service is explicitly configured; see the
[Qdrant administration procedure](QDRANT.md). Prefer versioned model
identifiers: an API's echoed model name does not prove that an operator has kept
mutable model weights unchanged.

The OCC generation command defaults to SQL; select Qdrant only when needed:

```sh
php occ eva_ai:vector:generation create USER --provider=ollama --model=MODEL --endpoint=http://localhost:11434 --dimension=DIMENSION
php occ eva_ai:vector:generation create USER --backend=qdrant --provider=ollama --model=MODEL --endpoint=http://localhost:11434 --dimension=DIMENSION
```

In EVA administration, select the owner and generation under **Embedding
generations**. **Background build** explains the transfer and requires a separate
document-processing checkbox. The owner's source index must be enrolled and the
generation bound to its backend. SQL builds need only Nextcloud SQL and the
pinned embedding provider; Qdrant builds also require storage and point delivery.
Nextcloud's native EVA administration page links to these same controls through
**Manage storage and background builds**. No controls are added to chat.

Start only schedules SQL work. Native Nextcloud cron processes the job in the
background. Progress reads inspect SQL metadata and never probe a provider.
Pause invalidates the current lease; explicit resume retains its checkpoints.
An already-started request may finish, but a paused process cannot publish a
new checkpoint. Cancel also stops admission and retains prepared data; explicit
resume is possible. It does not purge a collection or erase the legacy index.
SQL builds create no remote point-delivery operations. Previously committed
Qdrant vector operations remain governed by the separate point-delivery
permission. Disable storage requests to stop all Qdrant transfers.

The same control service is available to local administrators:

```sh
php occ eva_ai:vector:build status USER GENERATION_ID
php occ eva_ai:vector:build start USER GENERATION_ID --confirm-document-transfer
php occ eva_ai:vector:build pause USER GENERATION_ID
php occ eva_ai:vector:build resume USER GENERATION_ID
php occ eva_ai:vector:build cancel USER GENERATION_ID
# One bounded global tick, processing only previously authorized eligible work:
php occ eva_ai:vector:build run
```

The minute job is registered automatically; actual execution follows the
instance's configured Nextcloud background-job mode. Configure working cron for
reliable progress. Clicking Start does not run inference in the browser request.

## Durable progress and isolation

`eva_ai_vec_builds` stores the initial document high-water mark/count, completed
document cursor, current document/chunk cursor, plan identity, state, lease,
heartbeat and a sanitized failure code. `eva_ai_vec_build_docs` records each
completed canonical source stamp, plan hash and expected chunk count.

Normal Nextcloud document insertion extends the high-water mark and total in
the same owner-fenced SQL transaction. A row committed behind the cursor rewinds
the build and invalidates its lease; an ID that lands before the current
in-flight document also forces a rewind. A safely appended row leaves the current
document and embedding request alone. A build that was already staged is queued
again when new indexed sources arrive. Before staging, the worker rechecks for
new documents while holding the same owner fence used by insertion, so an
insert racing with finalization is either consumed by that tick or reopens the
build after it commits. These hooks transfer no source text and do not add work
to chat requests.

Sources use a tenant-filtered document-ID keyset, without OFFSET scans. One tick
claims one generation for 180 seconds and admits at most 256 chunks/16 sources
subject to a 90-second admission budget checked between operations. Embeddings
use batches of at most eight, with
request deadlines capped at 30 seconds and shortened near the tick budget.
Source extraction is reused across batches within the tick. Resume reconstructs
the current canonical source and requires the same plan; if it changed, only
that document's rows in this generation are tombstoned and rebuilt with new IDs.
Plans are limited to 4,096 chunks and 16 MiB of generated chunk text, so very
large overlap cannot multiply a small source into an excessive in-memory plan.
Repeated source-budget exhaustion without progress stops for attention instead
of consuming every later background tick indefinitely.

Provider/native-source I/O runs outside SQL transactions. Each batch atomically
commits prepared rows and the build checkpoint; Qdrant batches also commit vector
journal intent. Lock order is owner, generation, build, then source. A reclaimed process has a different
token; a late process cannot acknowledge its successor or overwrite a pause.
Transfer guards recheck lease, flags, target, generation identity, source metadata
and native access. Fresh SQL preferences also honor an explicit personal indexing
opt-out, including one made after the tick started.

PDF/Office commands run through an argv-based subprocess helper with a 20-second
command deadline and eight-MiB captured-output limit. GNU timeout additionally
terminates descendants where available; the PHP deadline is the fallback. OCR
keeps its existing bounded page/pixel/output and 60-second operation limits.
Mail stops processing once its canonical 30,000-character prefix is filled.
Native storage/IMAP calls retain their transport behavior; admission budgets and
lease checks do not prove a hard wall-clock deadline for every remote operation
or zero contention with interactive model requests. Load/soak evidence is still
required before rollout.

Metadata endpoints and personal export omit runtime tokens and storage targets.
Personal export includes owned progress/manifests. Native account erasure fences
the owner, removes build metadata with generation/index cleanup and prevents a
late worker from recreating it. Legacy NULL-generation vectors remain intact.

## Prepared is not verified

`staged` means the bounded source pass finished. SQL verification must still
check local vectors, revisions, content hashes and source manifests. Qdrant
verification additionally checks every remote point. New documents committed
while a build is queued, running, paused or staged are added to its work range.
In-place metadata changes behind the cursor, deleted-document manifest cleanup,
and changes after a build is cancelled or failed still need reconciliation. The UI/API report
`retrieval_changed: false` until a later cutover.

The read-only persisted verifier now consumes the tenant-scoped exact live-count
and exact-ID retrieval contract. It checks SQL/remote IDs, revisions, dimensions,
source manifests, deterministic sampled cosine scores and the snapshot boundary,
then stores a certificate that canonical source changes invalidate. See
[GENERATION-VERIFICATION.md](GENERATION-VERIFICATION.md). Cross-database and
adversarial evidence remain stage 09 gates. Activation is unavailable. Continuous update ingestion,
shadow/canary/primary rollout and normalized encrypted SQL chat storage are later
stages in [the full ledger](BACKEND-ROADMAP.md). See [QUALITY.md](QUALITY.md) for
what was actually checked; a staged count is not a verified migration.
