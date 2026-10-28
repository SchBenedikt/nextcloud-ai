# Optional Qdrant dense adapter

EVA's normal installation still uses SQL retrieval. The Qdrant adapter is opt-in,
disabled by default and not invoked by Searcher or Indexer in this stage. App
installation, upgrade, page rendering and generation metadata creation make no
Qdrant request. Configuring a connection does not select it for chat retrieval.

## Protocol and identity

The adapter targets Qdrant 1.x, minimum 1.16.0. Health explicitly checks the root
API version; this is reachability/capability metadata, not proof of collection
readiness, HA, backup health or successful indexing. Live version-matrix and
transport tests have not yet been performed.

Protocol references used during implementation:

- [Create collections](https://api.qdrant.tech/api-reference/collections/create-collection)
- [Create payload indexes](https://api.qdrant.tech/api-reference/indexes/create-field-index)
- [Upsert points](https://api.qdrant.tech/api-reference/points/upsert-points)
- [Delete by filter](https://api.qdrant.tech/api-reference/points/delete-points)
- [Query points](https://api.qdrant.tech/api-reference/search/query-points)

Each server-owned generation uses its own randomly named collection and a named
`dense` vector with pinned dimension and Cosine distance. Preparation checks the
existing vector schema and indexes tenant, generation, document, source and scope
fields before data ingestion. It rejects incompatible schemas rather than silently
reusing a differently shaped collection.

Points use stable positive SQL chunk IDs within that collection. Payloads contain
SHA-256 tenant tokens, generation IDs, document/file/chunk identifiers, source,
content hash and hashed path ancestors. Raw account names, file paths and document
text are not copied. These unsalted tokens are pseudonyms, not encryption or proof
against guessing; vectors and identifiers remain personal data requiring access
controls and retention policy.

Folder filtering is exact ancestor-token matching, including full-path equality,
so `Notes` does not match `NotesArchive`. It does not require a newer prefix-index
feature. Every search and document-delete forces tenant AND generation filters;
callers cannot provide arbitrary filter JSON. Returned payloads are checked again
for identity/source/scope/document violations. Any violation fails the entire
request instead of silently hiding a violated invariant. Search returns only IDs
and scores; future readers must still hydrate and recheck SQL ownership.

Upsert validates the complete batch before its first request, enforces generation
dimension and finite nonzero vectors, and caps batches at 200 points / 250,000
vector elements / 8 MiB encoded request. Scope ancestry is capped at 128 levels.
Writes use wait=true and strong ordering and only accept completed operations.
Document deletion is filtered and idempotent; an absent collection is already
empty. Search is bounded to 100 results, 3 seconds of server time and 5 seconds
HTTP time. Health uses 3 seconds; other requests cap at 30 seconds. Responses are
limited to 2 MiB with transfer progress and read bounds. There are no hidden
unbounded retries. An outbox worker must perform bounded, observable retries.

## Configure explicitly

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
