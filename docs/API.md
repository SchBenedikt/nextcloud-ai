# EVA AI API routes

EVA exposes OCS routes under `/ocs/v2.php/apps/eva_ai/api/`. Most routes require
a signed-in Nextcloud user; the `/api/v1/` integration routes authenticate
with an EVA bearer key and are explicitly scoped to that key's owner.
The Vue client uses `@nextcloud/axios`, which supplies Nextcloud's request token
and OCS headers. Admin routes live under `api/admin/` and require administrator
privileges.

## Application pages

Signed-in pages under `/index.php/apps/eva_ai/` include `chat`, `documents`,
`settings`, `metrics`, and `runs`. The `/runs` page opens the background-run
workspace; it is separate from the OCS endpoints that list and control runs.
The administrator workspace remains at `/admin` and requires an administrator.

## Administrator storage snapshot

`GET /api/admin/vector-storage` is admin-only and read-only. It reads local
configuration and actionable outbox counts, never contacting Qdrant or an
embedding provider. The OCS data on success is:

```json
{"available":true,"retrieval_backend":"sql_legacy","connection":{"enabled":false,"configured":false,"key_configured":false},"erasure_worker_enabled":false,"chunk_sync_available":true,"points_requested":false,"point_worker_enabled":false,"staged_chunks":0,"queue":{"pending":0,"processing":0,"dead":0},"observed_at":1793209009}
```

`observed_at` is a Unix timestamp. Claimed operations can include expired leases;
they do not prove a worker is running. Counts cover pending/processing/dead events,
excluding delivered/superseded history from the aggregate query. This is an
observational snapshot, not an atomic distributed health assessment. Configuration
flags do not establish remote reachability or collection readiness. SQL remains
the only selected retrieval backend in this stack. `chunk_sync_available=true`
indicates the prepared-point consumer exists, not that backfill, verification or
cutover is available. `points_requested` records explicit administrator consent;
`point_worker_enabled` additionally requires the connection/cleanup gates.

Pending operations can now include durable source-deletion tombstones. They stay
queued until the relevant delivery gates allow processing; queue presence does
not prove remote erasure. SQL-only source deletion does not create vector operations.

`staged_chunks` counts generation-tagged SQL rows separately from the NULL-
generation legacy index. It does not assert delivery, verification or readiness.
Both native admin surfaces display it; count failures make the snapshot unavailable.

Metadata/database failures return HTTP 503 with `available=false` (plus the common
error envelope), never zeroed healthy counters or raw database errors. Non-admins
are rejected by `AdminRequired`. No target URL, keys, owner identities, generation
descriptors, file paths or content are returned. Both the EVA admin view and the
server-rendered Nextcloud administration page use the same snapshot service.

## Administrator vector connection operations

The admin overview user rows additionally expose `vectorOwnerState`: open,
blocked, uninitialized or unavailable. Both native administration surfaces show a
blocked-after-erasure notice or unavailable state; neither offers an unsafe
unblock action. Lookups use bounded 100-owner SQL batches and no network calls.
An empty owner table means uninitialized, while a read failure means unavailable.

These OCS endpoints require a signed-in Nextcloud administrator. Writes and the
explicit probe require the normal request token; none has a CSRF exemption:

| Method and path | Behavior |
| --- | --- |
| `GET /api/admin/vector-storage/connection` | Offline form state: `endpoint`, `auth` (`api_key`/`none`), `configured`, `enabled`, `key_configured`. No plaintext/encrypted key is returned. Unlike the compact snapshot, this admin-only form exposes the configured endpoint. |
| `PUT /api/admin/vector-storage/connection` | Full replacement from a JSON body, at most 8 KiB. Requires `endpoint`, explicit `auth`, explicit boolean `enabled`, and a new `api_key` string for `auth=api_key` or null/omitted key for `auth=none`. No retained-key placeholder or query-string credentials. Returns the offline form state. |
| `POST /api/admin/vector-storage/disable` | Disables all Qdrant transport without discarding targets or journal intent. Returns compact connection status. |
| `POST /api/admin/vector-storage/check` | Explicit bounded root-API version/reachability probe using the saved enabled connection. Returns `{available, code}`; an unsuccessful probe is a successful operation with `available=false`, not proof of an empty/ready index. |

Configuration validates before starting replacement, disables transport first,
saves the encrypted target/configuration and enables it last only if explicitly
requested. Replacement performs no HTTP request or collection preparation. It
does not activate chunk synchronization or change retrieval. Enabling requests
can resume already-enabled generation cleanup against current or retained
destinations. A partial save failure can leave transport disabled; reload before
retrying. Retargeting requires fresh credentials or explicit no-key mode.

Validation errors return HTTP 400 with a bounded English message. Unexpected
storage/configuration failures return 503 without raw exceptions or secrets.
Private/loopback network approvals remain instance-owned, not accepted from this
form; the egress policy applies to every explicit probe too. The live probe uses
the adapter's three-second HTTP bound plus operating-system DNS resolution limits.

`GET /apps/eva_ai/admin` is a separate AdminRequired page, linked from Nextcloud's
EVA administration settings. It loads a dedicated frontend entry without mounting
the personal chat shell. The page GET is CSRF-exempt like the app's other page
routes; its OCS mutation endpoints remain protected. Access, CSRF, native rendering
and actual remote transport have not been exercised while tests are deferred.

## Lightweight index progress

`GET /api/index/status` requires a signed-in user and returns:

```json
{"indexing": false, "indexStopping": false, "indexMode": "", "lastStarted": "", "lastFinished": "", "lastError": ""}
```

It checks abandoned-run recovery but does not query models, document totals,
chat history or OCR. The full `GET /api/status` remains available for settings
and diagnostics. Its `providerKeys` object maps configured profile IDs to
credential-presence booleans, never actual keys.

Provider profiles retain the existing settings contract. `PUT /api/settings`
accepts `provider_profiles` and `chat_provider` together, plus a write-only
`custom_provider_api_key`. Profiles can specify `auth: "none"` to omit bearer
authentication; omitted `auth` means `api_key`. Built-in IDs `ollama` and `groq`
are reserved. See [Providers](PROVIDERS.md).

## Error responses

Non-streaming API and admin errors use one OCS data shape:

```json
{
  "error": {
    "code": "conflict",
    "message": "This chat changed in another tab."
  }
}
```

`code` is a stable machine-readable identifier and `message` is suitable for
display to the user. HTTP status codes remain authoritative. NDJSON streaming
endpoints report failures as `{ "type": "error", "message": "…" }` records
inside the stream.

## Compatibility aliases

Older releases exposed camelCase paths. They remain available for existing
clients. New integrations should use the consistent paths below:

| Operation | Method and path |
| --- | --- |
| Start mail indexing | `POST /api/mail_index` |
| Start Talk indexing | `POST /api/talk_index` |
| Stop indexing | `POST /api/index/stop` |

## Personal usage metrics

`GET /api/metrics?days=30` returns usage for the signed-in user only. The
optional `days` parameter accepts an integer from 1 to 365 (default: 30). The
response groups request counts, token totals, average and maximum response
times by provider/model, and daily totals. It also includes slow tool-call
timings. Estimated token totals are marked
as estimates. EVA does not store prompts or responses in this metrics table.
Each model row includes `estimated_cost_usd` when that user configured input and
output rates per million tokens; otherwise it is `null`. The estimate is based
on the recorded token counts and user-entered USD rates, not a live provider
price lookup. The Metrics page can download these sections as a CSV report and
manage the rates.

`PUT /api/metrics/pricing` saves a user-specific array of `{provider, model,
input_per_million, output_per_million}` prices. Rates must be non-negative USD
amounts no greater than 10,000 per million tokens; at most 50 unique model
entries are accepted. Sending an empty `prices` array clears the estimates.

`POST /api/chats/{id}/messages` accepts a `role` (`user` or `assistant`) and
non-empty `text` up to 50,000 characters. Assistant messages may include up to
three string `followups`, an optional non-negative `regenerateRev`, a
`confirmation` object, a `tools` list of objects, and an optional `model` name
up to 128 characters. The server records a `createdAt` timestamp for each new
message. Legacy form clients may send the array/object fields as JSON strings;
malformed or wrongly typed data returns HTTP 400. A successful append returns
`{ "ok": true, "rev": 1 }`, where `rev` is the updated chat revision.

`POST /api/chats/{id}/turns` persists a completed question/answer pair in one
protected write. Supply a safe 16–128-character `requestId`, integer
`expectedRev` (the revision loaded/created by this client), string `question`
and an `answer` object with the assistant fields described above. Both messages
are validated before writing. A new turn cannot carry `regenerateRev`. The
response is `{ "ok": true, "rev": <updated revision> }`; the revision advances
for both messages. Repeating an identical request ID/payload is idempotent;
reusing the ID for different content or submitting a stale revision returns
HTTP 409 without writing. Receipts are bounded to 100 per chat; revision checks
still reject evicted old requests. Missing/foreign chat IDs return HTTP 404.
The native browser uses this endpoint for complete turns, confirmation
placeholders and stopped partial answers. It does not rerun the model to save.

Individual assistant appends carrying a stale or superseded `regenerateRev`
now return HTTP 409 instead of appending an answer to a newer conversation.
A failed or empty-stopped regeneration leaves the original stored conversation
intact. Before another turn, the native client refreshes the revision and
restores that conversation; a retry may use the original base or the freshly
read current revision. If answer storage itself failed, the visible new answer
is preserved for copying and persistence stays unconfirmed.
Successful metadata updates also return the current `rev` so personalization
changes made by the open chat keep its revision guard current.

`POST /api/streamChat` and `POST /api/chat` also accept an optional `images`
list for the current user message. Each entry contains a `name`, a `mime`
(`image/png`, `image/jpeg`, or `image/webp`), and base64 `data`. Up to four
images with a combined decoded size of 4 MB are accepted; files are verified
against their actual image type and limited to 25 megapixels each. Image bytes
are sent to the configured vision-capable chat provider for that request and
are not saved in chat history. The text and attachment names are saved so the
conversation remains understandable after reload.

`POST /api/images/generate` accepts a prompt of 1 to 1,000 characters and a
`count` from 1 to 4. Images are generated by the user's selected
OpenAI-compatible provider, saved in their `EVA` Files folder, and returned as
file IDs, names, MIME types, sizes, modification times, and preview URLs. Each
user may start at most five generation requests per 15-minute window.
`GET /api/images` returns up to 50 recent generated images from that user's EVA
folder; it never lists arbitrary files or another user's images. Each result
includes a `previewUrl` and an authenticated `downloadUrl` at
`GET /api/images/{id}/download`; a user can download only images in their own
EVA folder.

`GET /api/plugins` lists installed third-party EVA tools and their schemas,
risk, execution surfaces, confirmation requirement, and per-user enabled
state. `PUT /api/plugins` accepts `{ "name": "plugin_example_lookup",
"enabled": false }` to disable or re-enable one listed tool for the signed-in
user. Disabled tools are omitted from model tool discovery and rejected if
called directly. Plugin code still runs inside the trusted Nextcloud PHP
process; use an external connector when the code must run outside that trust
boundary.

## Programmatic API keys

Users manage their own credentials with the authenticated routes below. The
secret is returned only once by `POST /api/keys`; storage contains only its
SHA-256 hash. `admin` keys can only be created by a Nextcloud instance admin.

| Operation | Method and path |
| --- | --- |
| List keys and usage | `GET /api/keys` |
| Create a key | `POST /api/keys` |
| Revoke a key | `DELETE /api/keys/{id}` |
| Send a programmatic chat request | `POST /api/v1/chat` |
| List chats | `GET /api/v1/chats` |
| Read one chat | `GET /api/v1/chats/{id}` |
| List indexed documents | `GET /api/v1/documents` |
| Read indexed document chunks | `GET /api/v1/documents/{id}/chunks` |

Create with JSON fields `name`, `scope` (`read`, `write`, or `admin`), optional
`expiresAt` (Unix timestamp), and optional `ipWhitelist` (exact IP addresses).
The name must contain 1–80 characters. `ipWhitelist` accepts a list of IP
address strings or a comma/space-separated string. `GET /api/keys` returns a
`keys` list whose entries contain `id`, `name`, `key_prefix`, `scope`,
`created_at`, `expires_at`, `ip_whitelist`, `last_used_at`, and `call_count`.
The create response contains the one-time `key` secret and its `record` using
the same metadata fields; never store or log the secret in client-visible
history.
Read keys can list chats and indexed documents and read their content in bounded
pages. They are limited to 100 requests per minute; write keys (30/min) can also send a chat request, and
admin keys (10/min) inherit both capabilities. The external chat route never
runs EVA tools or mutating actions. The API key owner must remain an enabled
Nextcloud user.

```sh
curl -X POST 'https://nextcloud.example.com/ocs/v2.php/apps/eva_ai/api/v1/chat' \
  -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer eva_sk_REPLACE_WITH_YOUR_SECRET' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Summarize my indexed documents"}'
```

Keep the returned secret private. The key list includes its name, masked
prefix, scope, expiry, last-use time, and call count, but never the secret.

## Prompt templates

Signed-in users can manage their own prompt templates through the chat API.
Templates support `{variable}` placeholders, optional per-template persona
instructions, and a usage counter. JSON export and import let users share
templates by passing the exported file to another user.

| Operation | Method and path |
| --- | --- |
| List or export this user's templates | `GET /api/templates` or `GET /api/templates/export` |
| Create or update a template | `POST /api/templates` |
| Import templates from `{ "templates": [...] }` | `POST /api/templates/import` |
| Record use and fetch a template | `POST /api/templates/{id}/use` |
| Delete a template | `DELETE /api/templates/{id}` |

Template data is stored in the user's private EVA app data. Import creates new
IDs and accepts at most 100 templates per request; each prompt is limited to
8,000 characters.

## Personal knowledge and selected-file context

`GET /api/knowledge` returns the signed-in user's private `KNOWLEDGE.md` as
`{ "content": "…", "length": 12 }`. `length` is the Unicode character count,
not the UTF-8 byte count. `PUT /api/knowledge` accepts a `content` string up to
60,000 characters and returns `{ "ok": true, "length": 12 }` after the file is
saved.

`POST /api/fileContextStatus` accepts selected Nextcloud `fileIds` and returns
three typed lists: positive integer IDs in `indexed` and `missing`, and
accessible `files` entries containing `fileId`, `name`, and `path`. Files the
current user cannot access are not included in `files` and remain unindexed.

## Scheduled briefings

Briefing definitions are configured through the signed-in user's settings.
They use the account timezone and server cron. Run-now requests are queued for
background delivery; history contains the latest 50 outcomes for that user.

| Operation | Method and path |
| --- | --- |
| Read recent briefing history | `GET /api/briefings/history` |
| Queue an enabled briefing immediately | `POST /api/briefings/{id}/run` |

Each schedule can deliver through Nextcloud notifications, the configured
instance mailer, or a Talk room the user belongs to. Talk posting still requires
the user's Talk-write setting. Delivery failures and partial deliveries are
recorded in history.

| Reset index | `POST /api/index/reset` |
| Delete a chat folder | `DELETE /api/folders` with `{ "name": "…" }` |

The legacy `/api/mailIndex`, `/api/talkIndex`, `/api/indexStop`,
`/api/indexReset`, and `POST /api/folders/delete` paths remain supported.

Normal file-index cleanup deletes canonical source rows and generation chunks
in one SQL transaction. When a source belongs to an optional Qdrant generation,
the transaction records bounded durable tombstone work; it does not make a
Qdrant request on the indexing or chat path. This lifecycle wiring does not
enable generation-based retrieval or change index reset behavior.

### Document list freshness

`GET /api/documents` returns `Cache-Control: private, no-cache` with its ETag.
Clients may retain a private copy but must revalidate before reusing it; an
explicit refresh therefore sees new indexing results. Chunk previews retain
the existing 200-row bounded pagination contract.

### Vector-generation metadata in personal data export

The existing authenticated personal-data export adds `vector_generations`, an
array of the requesting account's generation metadata (empty before preparation).
Rows include immutable embedding/collection identity and lifecycle state, with an
endpoint hash rather than a URL. Provider credentials are not included. Retrieval
of this metadata is ownership-filtered and keyset-paged; a metadata read failure
fails the export rather than falsely presenting it as complete. This addition
creates no vector-management HTTP routes; generation preparation is OCC-only.

## Prepared-point consent

`POST /api/admin/vector-storage/points` requires an administrator, normal CSRF
validation, `Content-Type: application/json` and `{ "enabled": true|false }`.
String/numeric values are rejected with HTTP 400. Success returns
`{ "points_requested": true|false }`. Enabling also allows the existing generation
cleanup worker; disabling points does not disable cleanup. No provider/collection
probe, backfill or retrieval switch runs in the request. Read the shared status
snapshot after saving, including after HTTP 503, to observe authoritative gates.
Existing connection disable stops all Qdrant transfers.

## Generation embedding administration

`GET /api/admin/vector-generations/{userId}?after=<generation ID>` returns
`items` and `next_after` (null except for a full 50-row page). It requires an
existing enabled canonical Nextcloud owner and returns only generation ID,
lifecycle state, provider/profile ID, model, dimension, chunk settings and
creation timestamp. Credentials, endpoint URLs/hashes and vector content are
not included. The `after` cursor is a lowercase 32-character hexadecimal ID.
This GET never contacts the provider.

`POST /api/admin/vector-generations/{userId}/{id}/check` explicitly checks an
owned building generation with a fixed public test sentence. No client text,
vector or credential is accepted. Success returns `available: true`,
`generation_id`, `dimension`, `retrieval_changed: false` and
`document_content_sent: false`. Invalid selections return 400; configuration,
model-space or transport failures return 503 with a sanitized error message.
Both routes require an administrator; the POST retains native CSRF protection.
A successful probe does not activate a generation or verify its complete index.
See [generation embeddings](GENERATION-EMBEDDINGS.md).

## Background generation builds

`GET /api/admin/vector-generations/{userId}/{id}/build` returns `status`,
`backend`, `include_text_payload`, `documents_done`, `total_documents`, `staged_chunks`, `heartbeat_at`, `error_code`,
`can_start`, `retrieval_changed: false` and whether a current verification
certificate exists. Counts describe SQL
preparation. Missing state is `not_started`; a completed pass is `staged`, never
an activated or verified index. No lease token, destination, content or vectors
are returned, and this read performs no provider request.

`POST /api/admin/vector-generations/{userId}/{id}/build/{operation}` accepts
`start`, `pause`, `resume` or `cancel`. Start requires JSON
`{"confirm_document_transfer":true}`. For a generation created with the optional
Qdrant text-payload capability, start and resume additionally require
`{"confirm_text_transfer":true}`. This is separate consent to copy chunk text
into Qdrant. Other operations accept an empty JSON
object. The path parameter is named `operation` because Nextcloud reserves and
removes `action` before controller dispatch. All routes require native admin
authorization; mutation retains native CSRF protection. Invalid requests return
400, unavailable/disabled build controls 409, and database failures sanitized 503.

Mutations return the same authoritative status shape. Start is idempotent and
does not reset an existing build. It schedules background work instead of running
model requests synchronously. See [generation builds](GENERATION-BUILDS.md) for
consent, source-enrollment/destination gates, lease semantics and remaining
verification requirements.

`GET /api/admin/vector-generations/{userId}/{id}/verification` returns the
verification `status`, `phase`, backend, point counts, sample count, heartbeat,
sanitized error code, `can_start`, `verified`, `can_activate`, `can_rollback`,
effective `retrieval_backend`, `include_text_payload` and `active`. The matching POST route accepts
`start`, `pause`, `resume`, `cancel`, `retry`, `activate` or `rollback` with an
empty JSON object. These administrator-only controls schedule or manage bounded
background work; verification never performs provider or vector-store I/O in
the request. Activation only updates local SQL state and performs no remote I/O.
SQL verification checks local vector shape, revisions, content hashes and source
manifests; Qdrant verification additionally reconciles the configured remote
store. Opted-in text-payload generations also require zero live points with a
missing text field and compare sampled remote text with the exact SQL source;
dense-only generations never send or retrieve chunk text.
Neither path sends source text to an embedding model. A verified certificate
is invalidated atomically when canonical source data changes. SQL activates
automatically after current verification; Qdrant requires explicit canary
activation and can be rolled back to SQL. See
[generation verification](GENERATION-VERIFICATION.md).

`POST /api/admin/vector-generations/{userId}/{id}/shadow` accepts a short
administrator-supplied `query`, `top_k` from 1 to 20 and an optional Files
`scope_path`. It is available only after a current verification certificate and
requires active point delivery. The explicit probe sends the sample to the
generation-pinned embedding provider and configured vector store, compares the
exact SQL dense ranking with Qdrant and returns overlap and timing aggregates.
It does not return point IDs, save the sample/vector, answer a chat or change the
retrieval backend. Query/embedding, remote vector, SQL row and total wall-time
budgets are enforced. The native admin UI requires a deliberate button press.
See [generation shadow comparison](GENERATION-SHADOW.md).
