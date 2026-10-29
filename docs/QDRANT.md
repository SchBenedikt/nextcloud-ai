# Optional Qdrant dense and hybrid adapter

EVA's normal installation still uses built-in SQL retrieval. Qdrant remains
optional and disabled by default. A configured connection does not select it for
chat retrieval; only an explicitly verified and activated generation can serve
as a Qdrant canary. Installation, upgrade, page rendering and generation
metadata creation make no Qdrant request.

## Optional local deployment

For a single-host installation, `deploy/qdrant/compose.yaml` runs Qdrant 1.20.0
with persistent storage, telemetry disabled, and its HTTP port bound only to
loopback. Start and check it from the repository root:

```sh
docker compose -f deploy/qdrant/compose.yaml up -d
curl --fail http://127.0.0.1:6333/
```

This setup is intentionally local and unauthenticated. Do not change the port
binding to `0.0.0.0`; a remote Qdrant server must use HTTPS and API-key
authentication. The compose file pins a version so an image update is an
operator-controlled change. Take and verify a backup before upgrading an
existing Qdrant data volume.

Allow the exact local origin in Nextcloud, then use the native EVA
administration settings to save and check the connection:

```sh
php occ config:app:set eva_ai vector_private_origins --value='["http://127.0.0.1:6333"]'
```

Choose no-key authentication only for this loopback endpoint. Connecting or
enabling Qdrant transport does not create a collection, send indexed vectors,
copy document text or change the search backend. The separate generation
workflow still requires verification and explicit canary activation; sending
chunk text for native BM25 needs its additional consent.

Administrators configure the endpoint, encrypted API key, exact private-origin
allowlist, and request/point-delivery switches in **Administration settings →
EVA AI → Knowledge storage → Configure Qdrant and manage search migrations**.
The admin UI can create a Qdrant generation from an account's active SQL
generation, prepare/repair its empty collection, and then guide the separate
build, verification, explicit canary activation, and SQL rollback steps. SQL
remains usable if Qdrant is absent or disabled.

## Protocol and identity

Dense retrieval targets Qdrant 1.x, minimum 1.16.0. Native hybrid retrieval
requires Qdrant 1.20.0 or newer for BM25 scoring on a full-text payload index.
Health explicitly checks the root
API version; this is reachability/capability metadata, not proof of collection
readiness, HA, backup health or successful indexing. On 29 October 2026, the
local Nextcloud 35 installation reached a loopback Qdrant 1.20.0 instance via
`occ eva_ai:vector:qdrant health`. This checked only the application transport;
no generation, point delivery or search cutover was performed. The live
multi-version/cluster matrix remains pending.

Protocol references used during implementation:

- [Create collections](https://api.qdrant.tech/api-reference/collections/create-collection)
- [Create payload indexes](https://api.qdrant.tech/api-reference/indexes/create-field-index)
- [Upsert points](https://api.qdrant.tech/api-reference/points/upsert-points)
- [Conditional point updates](https://qdrant.tech/documentation/manage-data/points/#conditional-updates)
- [Query points](https://api.qdrant.tech/api-reference/search/query-points)
- [Hybrid dense/BM25 queries and RRF](https://qdrant.tech/documentation/search/hybrid-queries/)
- [Full-text indexes and BM25 scoring](https://qdrant.tech/documentation/manage-data/indexing/)

Each server-owned generation uses its own randomly named collection and a named
`dense` vector with pinned dimension and Cosine distance. Preparation checks the
existing vector schema and indexes tenant, generation, document, source, scope, revision and deletion
fields before data ingestion. It rejects incompatible schemas rather than silently
reusing a differently shaped collection.

Points use stable positive SQL chunk IDs within that collection. Payloads contain
SHA-256 tenant tokens, generation IDs, document/file/chunk identifiers, source,
content hash, monotonic revision, deletion state and hashed path ancestors. Raw account names, file paths and document
text are not copied for dense-only generations. These unsalted tokens are pseudonyms, not encryption or proof
against guessing; vectors and identifiers remain personal data requiring access
controls and retention policy.

Native hybrid retrieval is an explicit per-generation option, off by default.
The admin UI or `--include-text-payload` records that choice when creating a
Qdrant generation; starting or resuming its build separately requires
`--confirm-text-transfer` (the UI asks for the same separate confirmation).
Those controls are distinct from consent to send text to the embedding provider.
Only opted-in generations add chunk text to the Qdrant `content` payload and
build the BM25/phrase index. Qdrant can contain plaintext document chunks; remote
operators must configure their own storage access, encryption-at-rest, backup
retention and deletion controls. Full-generation erasure removes the collection;
chunk tombstones overwrite the payload and do not retain text. Existing
dense-only collections are never silently promoted to hybrid and must be rebuilt
as a new opted-in generation.

Folder filtering is exact ancestor-token matching, including full-path equality,
so `Notes` does not match `NotesArchive`. It does not require a newer prefix-index
feature. Every search forces tenant AND generation AND `deleted=false` filters.

Canonical relative Files paths produce those ancestor tokens. Mail/Talk points
accept their corresponding bounded `mail://`/`talk://` URI, carry no Files folder
tokens and cannot enter a Files-folder-scoped result through that field. Raw URIs
are not sent to Qdrant. Remote source/scope isolation checks remain unexecuted.

Native hybrid queries run dense and BM25 prefetches with server-generated tenant,
generation, deletion and scope filters, then fuse them with Qdrant RRF. The
application retains SQL hydration at exact chunk revisions, filename/path
context, quoted-phrase boosts and document diversification. If remote hybrid
search fails, retrieval falls back to the built-in SQL path.

Callers cannot provide arbitrary filter JSON. Returned payloads are checked again
for identity/source/scope/document violations. Any violation fails the entire
request instead of silently hiding a violated invariant. Search returns IDs,
revisions and scores only. EVA hydrates owner-controlled SQL rows at exactly the
returned revision and rejects stale metadata before using any text.

Upsert validates the complete batch before its first request, enforces generation
dimension and finite nonzero vectors, and caps batches at 200 points / 250,000
vector elements / 8 MiB encoded request. Scope ancestry is capped at 128 levels.
Writes use wait=true and strong ordering and only accept completed operations.
Chunk deletion uses exact-ID tombstones as detailed below. Search is bounded to 100 results, 3 seconds of server time and 5 seconds
HTTP time. Health uses 3 seconds; other requests cap at 30 seconds. Responses are
limited to 2 MiB with transfer progress and read bounds. There are no hidden
unbounded retries. An outbox worker must perform bounded, observable retries.

## Revision and terminal chunk deletion protocol

Upsert now requires one positive monotonic journal revision per batch. Conditional
updates require the existing tenant/generation and a stored revision no greater
than the incoming revision. Live updates also require `deleted=false`: a chunk
tombstone is terminal even when a later erroneous live write has a larger revision.
Revisions are capped at 2^53-1 to preserve exact ordering in Qdrant's numeric range
comparisons. Stop synchronization rather than round a larger journal ID.

The former physical document-delete operation has been removed from the contract.
SQL deletion must journal every affected stable chunk ID before discarding it,
including chunks whose first remote upsert has not completed. `tombstoneChunks`
inserts or replaces those IDs with only tenant/generation/revision/deleted metadata
and a non-content unit vector. Existing file identifiers, scope hashes, content
hash and embedding are overwritten. Tombstones are retained for the lifetime of
the collection; never reuse a chunk ID or physically purge its tombstone while
old writes can still arrive. Whole-generation erasure may remove the collection;
workers must never recreate it automatically.

This deliberately uses conditional upsert's missing-point insertion behavior:
deleting only existing remote points misses a delayed first insert. The official
Qdrant documentation above explains that an `update_filter` does not prevent a
missing point from being inserted. A materialized tombstone blocks that late live
write. Strong ordering and wait=true remain required. A completed conditional
operation is not proof that each point matched: subsequent verification must
compare exact IDs, revisions and deletion state, not just delivery acknowledgements.

Unversioned points fail the update predicate and the read checks. An earlier
experimental nonempty collection must be rebuilt in a new generation, never
silently adopted. Only disposable fixture collections have been populated. Mutation producers,
current-SQL/lease guards and the opt-in prepared-point consumer are integrated.
The adapter alone still does not enable synchronization. Request-shape, reordered
upsert/delete, missing-point deletion, duplicate retry, metadata erasure and
multi-node concurrency checks remain unexecuted while tests are suspended.

## Configure explicitly

Native administration is available in Nextcloud **Administration → Eva AI →
Knowledge storage → Optional storage and migration tools**. The controls load
only after the disclosure is opened. They use the same encrypted connection
service as OCC, require fresh credentials on replacement, and never activate
retrieval or prepare a collection. Saved-connection checking and transport
disable are separate explicit actions. Enabling requests also resumes enabled
generation cleanup jobs; inspect pending work before opting in. The network
allowlist remains an instance setting managed with OCC. The old
`/apps/eva_ai/admin` route redirects to the native settings section. See API.md
and CONFIGURATION.md for the request contract and visible states.

Run as the web-server user from the Nextcloud directory:

```sh
php occ eva_ai:vector:qdrant status
php occ eva_ai:vector:qdrant configure --url=https://vectors.example.internal:6333 --key-file=/private/qdrant-key --enable
php occ eva_ai:vector:qdrant health
php occ eva_ai:vector:qdrant disable
```

Keep the key in a private local file; do not put it in shell history through a
command-line value. It is encrypted with Nextcloud ICrypto in instance app config,
never in personal settings or status output, and bound to the configured URL's
fingerprint. Reconfiguration requires a new key file or explicit `--no-key`, so
changing an endpoint cannot accidentally forward an old key elsewhere. It disables
transport before updating configuration and enables it last only with `--enable`.
Default/no-key setups are not automatically trusted or enabled.

Only loopback URLs may use HTTP; remote targets require HTTPS and Nextcloud's
certificate validation remains enabled. Never disable TLS verification for a
self-signed server: install its issuing certificate into the trusted certificate
store according to your Nextcloud deployment policy.

Private destinations additionally require an exact instance-owned origin:

```sh
php occ config:app:set eva_ai vector_private_origins --value='["https://vectors.example.internal:6333","http://127.0.0.1:6333"]'
```

Nextcloud's own local-address policy remains in force as well. The EVA guard does
not set a per-request allow-local bypass. Core configuration must permit the
intended local destination; EVA's exact-origin allowlist then constrains it further.
Metadata/link-local/reserved destinations are blocked even if listed. Requests are
validated and DNS-pinned at the final cURL handler, reject redirects, and use
sanitized error codes without raw HTTP exceptions or credential-bearing messages.

Configured Nextcloud proxies are rejected because proxy-side DNS may not match the
checked/pinned address. Environment proxy defaults are disabled at the final
handler. This deliberate restriction also applies to custom compatible providers;
a reviewed proxy egress mode remains future work. No raw URL or secret appears in
the health/status payload.

## Optional local container example

`deployment/qdrant.compose.yaml` is an operator-started example. Set
`EVA_QDRANT_IMAGE` to an approved official version or image digest (at least 1.16.0)
and `EVA_QDRANT_API_KEY` to a strong private key in a protected environment file.
There is intentionally no floating latest-image default or built-in password.
Then explicitly run Docker Compose using that file. Keep an identical key value
in the private file passed to the OCC configure command. Docker administrators
can inspect container environment variables; protect Docker access accordingly.

The example persists storage in a named volume and publishes only the REST port
on 127.0.0.1. It does not expose gRPC publicly. HTTP loopback configuration assumes
Nextcloud runs on the host and can reach that address. A Nextcloud container's
127.0.0.1 is a different network namespace; use a separately secured HTTPS service
for that deployment. The example has not been started or validated in this change.
Do not delete the storage volume as an upgrade procedure.

## Explicit empty collection preparation

```sh
php occ eva_ai:vector:qdrant prepare --user=alice --generation=GENERATION_ID
```

The command loads owned SQL generation metadata, requires building status, checks
API reachability and prepares the matching collection/indexes. It does not send
SQL chunks, trigger backfill, mark anything verified, change retrieval mode or
activate a generation. No general-purpose raw upsert HTTP endpoint or CLI upload
command is exposed.

The VectorStore interface is bound to this optional adapter for future consumers;
current SQL Searcher/Indexer do not consume it. Before workers can synchronize SQL
mutations, the transactional outbox must pin the storage endpoint identity and
handle stale revisions, document/account tombstones and configuration rotation.
The generation's existing endpoint hash identifies embedding inference, not the
Qdrant endpoint. Upsert retry idempotence alone is insufficient for concurrent
update/delete correctness. Remote erasure, durable retry/dead-letter handling,
backfill verification, shadow comparison and a tested rollback remain prerequisites
for enabling real indexing or primary reads. API health does not satisfy them.

## Retained destinations and generation binding (1.18.1)

Explicit preparation now pins a generation's storage target before contacting the
server. The target registry retains old encrypted connection records so future
outbox deletion work will not follow a changed current URL. Requests on a
`forTarget` adapter use the retained destination and its latest stored key, while
still honoring the global disable switch and egress policy. See
[VECTOR-OUTBOX.md](VECTOR-OUTBOX.md) for journal, rotation and upgrade boundaries.

An explicitly enabled generation-erasure worker is now available through cron or
`eva_ai:vector:outbox run`. It uses the retained target, committed metadata and a
post-DNS lease/status guard. Preparation likewise rechecks building ownership and
target before transfer. Execution is disabled by default; see VECTOR-OUTBOX.md
before enabling it. This consumer does not replicate chunk data or activate reads.

## Prepared-point transport

Version 1.18.7 adds an explicit administrator point-consent gate and a bounded
background/CLI consumer. It uses only existing prepared collections and retains
revision/terminal tombstone rules. Uploads require the current destination;
cleanup can address a retained destination. Native source access and post-DNS
lease/flag/source checks run before transfer. See [VECTOR-OUTBOX.md](VECTOR-OUTBOX.md#prepared-point-worker-1187).

The disposable Qdrant 1.16.0 fixture verified conditional old/new revisions and
terminal tombstones against the actual server through the native HTTP transport,
including SQL acknowledgement on an isolated SQLite fixture. This is not a
production-corpus migration, ANN benchmark, distributed consistency proof or
claim about all newer Qdrant versions. The optional deployment stays off on
installation and upgrade.
