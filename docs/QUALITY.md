# RAG quality evaluation (Issue #146)

The CI workflow and real Nextcloud app lifecycle matrix are documented in
[CI.md](CI.md). This guide describes the reproducible method and source-defined
thresholds. Record measured results for the actual revision/corpus/configuration;
old review counts do not establish the current tree's validation status.

EVA ships a deterministic, offline quality evaluation that measures the
extraction/chunking part of the RAG pipeline end to end. It exists so that
performance or chunking changes cannot silently reduce answer depth,
retrieval precision or citation validity.

## Running the evaluation

```bash
# From the app directory (PHPUnit is a dev dependency):
vendor/bin/phpunit --bootstrap tests/bootstrap.php \
  --debug tests/RagQualityEvaluationTest.php
```

This is a local benchmark mode: it uses **synthetic, privacy-safe fixtures**
(no real user data), runs the production `Chunker`, and ranks chunks with a
deterministic lexical stand-in retriever, so results are reproducible without
embeddings or an Ollama server. It never uploads data anywhere.

## What is measured

* **Extraction / chunking completeness** – ground-truth facts from the
  beginning, middle and end of each fixture must survive chunking, and chunk
  source offsets must stay in document order (stable provenance).
* **Structural context** – every chunk of a headed section retains its heading
  as a prefix, so retrieval keeps section context (Issue #147) and markdown
  tables stay readable text, not an undifferentiated wall.
* **Retrieval ranking** – recall@5 and MRR are computed per ground-truth query
  with a deterministic token-overlap retriever and aggregated per language
  (German and English queries).
* **Index health guards** – no chunk may exceed the configured chunk size
  (context truncation risk), every chunk must map back verbatim into its
  source document (citation/provenance validity), and duplicate chunks must
  stay rare (diversity floor) while intentional repeats are preserved
  (Issue #62).

## Regression thresholds

The source fixture uses chunk size 150 and overlap 30. The suite prints a
per-query report and aggregates German and English results. It fails below
recall@5 0.60 or MRR 0.50 in either language. Update this guide and the test
together when intentionally changing those thresholds.

Keep before/after reports with the source revision and fixture configuration.
For the production SQL/Qdrant dense/hybrid comparison, use the separate
[retrieval benchmark](RETRIEVAL-BENCHMARK.md), including its corpus provenance,
latency, exact-reference, ownership and cutover requirements. A lexical fixture
score alone provides no evidence for ANN recall, live inference or production
latency. The [migration ledger](BACKEND-ROADMAP.md) tracks unverified rollout gates.

## Extending the fixtures

Fixtures live inline in `tests/RagQualityEvaluationTest.php::CORPUS`. Each
document is a `text` plus a `facts` map (`query => section heading`). The
fixtures intentionally cover: long documents, repeated-passage documents,
tables, nested headings, multilingual (German/English) text, mail-like
threads and facts near the end of a file. Add a fixture together with the
feature that changes extraction/chunking so the regression guard covers it.

## UI and runtime evidence

Check native settings, provider forms, chat submission, document pagination and
navigation at narrow/wide viewports and in light/dark themes. Screenshots using
synthetic fixtures establish layout only. Authenticated chat, provider requests,
cron, migrations, concurrent writers and restore/rollback need their own runtime
evidence. Report skipped or unexecuted checks explicitly for each delivery.

## Authenticated local recovery checks

The local recovery batch resumes verification after the earlier test pause. It
includes 44 frontend unit tests and a PHP suite with
724 tests (8 skipped); the fixture-browser suite also covers delayed history
persistence and structured file-context failures. The source-generation staging/deletion probe also ran
against the installed MariaDB connection with its fixture transaction rolled back.
It checked idempotent staging, metadata invalidation, preserved legacy rows,
fresh point IDs and document deletion with durable tombstones. These are local
results, not PostgreSQL/SQLite or representative ANN/load evidence.

An opt-in browser suite covers real login, native pages, settings autosave/reload
and two real model answers followed by history reload. Use a disposable administrator
account named `eva-runtime-audit-*`, with English UI, the first-run wizard completed,
and an available configured chat model. It makes real model requests and creates
conversations only in that account. Never use a personal account. Keep credentials
in a private JSON file (`user` and `password`, mode 0600), outside the repository:

```sh
EVA_TEST_BASE_URL=http://localhost \
EVA_TEST_CREDENTIALS_FILE=/private/eva-audit.json \
npx playwright test tests/runtime --workers=1 --reporter=line

NEXTCLOUD_ROOT=/var/www/html/nextcloud \
EVA_TEST_USER=eva-runtime-audit-example \
php tests/runtime/source-lifecycle.php
```

Run the SQL probe as the web-server user; it bootstraps Nextcloud and rolls back
its fixture transaction, without contacting a vector/embedding service. Remove the
disposable account after verification. The browser suite requires configuration
and is skipped if its environment variables are absent. A mock browser run does
not substitute for it. Hosted image/audio providers, external app actions,
Qdrant transport/cutover, distributed races, restoration and load/soak still need
separate configured evidence; they are not established by these smoke checks.

The authenticated reload check also exposed a completion race after the first
recovery deployment: chat became ready before its history writes settled. The
follow-up keeps the composer busy through storage completion, adds bounded fetch
keepalive/unload protection and tests a deliberately delayed assistant save. The
browser harness explicitly compiles in development mode instead of webpack's
implicit production default, avoiding unnecessary minification during checks.

The native Files smoke check created a disposable WebDAV text file, obtained its
native file ID, waited for indexing, asked the real configured model through the
file-context interface, verified the expected code, then deleted the test file.
It exposed and verified the nullable-error correction: success responses with
`error: null` must remain success responses. Failure envelopes still retain their
stable code/message structure.

## Optional point-transport probe

Run against a disposable loopback Qdrant instance, never a production URL. The
probe uses Nextcloud's native HTTP client and an isolated in-memory SQLite schema;
its access-control double avoids touching a real account. It creates and removes
only its randomly named generation collections. Production configuration and SQL
are not changed. Native source guards have separate unit tests.

```sh
docker run --rm --name eva-qdrant-test -p 127.0.0.1:16334:6333 \
  -e QDRANT__TELEMETRY_DISABLED=true qdrant/qdrant:v1.16.0
# In another terminal, as the web-server user:
NEXTCLOUD_ROOT=/var/www/html/nextcloud \
EVA_VECTOR_TEST_URL=http://127.0.0.1:16334 \
php tests/runtime/point-transport.php
```

The checked cases include actual delivery, SQL revision acknowledgement, tenant/
generation query isolation and delayed older or higher-revision live writes after
a tombstone. This does not establish Mail/Talk deployment access, replica/network
fault tolerance, bulk throughput or complete-generation verification. Ordinary
PHP/frontend tests do not launch Qdrant.

## Completed-turn persistence checks

The native chat writes question/answer pairs through one `/turns` request,
including confirmation placeholders and stopped partials. The store uses one
user lock, revision check, bounded idempotency receipt and encrypted-file write.
This reduces two full chat-file writes to one; it does not replace the planned
normalized SQL message store or make SimpleFS writes atomic renames.

History POSTs have a 15-second client deadline, including response-body reads.
The timeout also settles when a transport ignores AbortSignal. It reports an
unconfirmed save and restores the composer; it cannot cancel a write already
executing on the server. Turn revisions and regeneration tokens reject writes
overtaken by newer mutations. Reload is required after an unconfirmed save.

Browser checks cover one complete request, delayed storage, an indefinitely
stalled transport and regeneration readiness. Store/controller checks exercise
idempotence, payload collisions, stale revisions, one-write ordering, owner
binding, validation and stale regeneration rejection. The authenticated runtime
suite additionally checks the actual endpoint's retry/conflict behavior.

A bounded payload below 60,000 encoded bytes uses fetch keepalive. Browsers have
a finite dismissal quota, so oversized turns do not use it; the native unload
warning remains. Confirming dismissal or killing the browser before a large
save finishes is not a durability guarantee. Persisting completion server-side
and normalized SQL chat storage remain separate migration work.

The first installed 1.18.8 candidate exposed a native-only receipt bug: terminal
background cancellation cleared the ID reused by the new turn request. Strict
server validation rejected the save, and regeneration then found no stored user
message. The correction gives the turn a separate stable receipt ID and makes
the browser fixture reject malformed receipts too. This failure is not counted
as a passing runtime validation; the corrected installation must pass the real
consecutive-answer, regeneration and reload path before integration.

A later review identified the failed-regeneration revision gap introduced by the
new turn guard. Error/empty-stop paths now refresh the server revision and
restore the unchanged stored conversation before another send; pending retries
accept the original base or the freshly read current revision. Failed answer
writes keep their visible text instead of hiding an unconfirmed response. The
refresh is bounded to ten seconds. Separate fixture cases cover model errors
and abort before the first model event, while the live suite can replace a real
regeneration response with an error after the native backend advances its marker.

## Generation embedding protocol probe

The new explicit probe uses actual native HTTP and isolated in-memory SQL,
without changing a real owner's preferences, credentials or global flags:

```sh
EVA_EMBED_FIXTURE_COUNTER=/tmp/eva-embedding-fixture-local.count \
php -S 127.0.0.1:16435 tests/runtime/embedding-fixture-server.php
# In another terminal, as the web-server user:
NEXTCLOUD_ROOT=/var/www/html/nextcloud \
EVA_EMBED_TEST_URL=http://127.0.0.1:16435 \
EVA_EMBED_FIXTURE_COUNTER=/tmp/eva-embedding-fixture-local.count \
php tests/runtime/generation-embedding.php
```

Stop the fixture server afterward and remove its disposable count file. The
fixture key is public synthetic test data, not an application credential. The
checked path includes actual profile/key SQL mutations after DNS resolution,
zero transfer after that mutation, per-owner key/cache isolation, native response
parsing and compatible index ordering. It does not establish a hosted provider's
availability, generation backfill, complete verification or retrieval cutover.

## Native account-erasure regression drill

The 1.18.9 local lifecycle drill exposed an actual Nextcloud API mismatch:
`ChunkMapper::deleteForUser()` used Doctrine's `distinct()`, which is absent from
Nextcloud's QueryBuilder. Native deletion consequently retained document/chunk
ownership rows. Later scheduler passes could rediscover those stale owners.
The query now uses Nextcloud's `selectDistinct()`; cleanup also leaves home
erasure to core instead of reopening the deleted account's storage.

Run the source-lifecycle probe above against MariaDB to exercise actual per-user
chunk/document cleanup, repeated calls and another tenant's preserved rows. The
fixture transaction rolls back. To check the installed listener and AppData too,
create a **disposable** audit account and, as the web-server user, run:

```sh
NEXTCLOUD_ROOT=/var/www/html/nextcloud \
EVA_TEST_USER=eva-runtime-audit-example \
EVA_TEST_DELETE_ACCOUNT=1 \
php tests/runtime/account-erasure.php
```

This command creates public fixtures and **deletes the account**, including any
of its existing data. It checks document, chunk, agent, generation and preference
removal, chat/dirty/mark AppData removal, the retained erasure fence and a cleanup
retry after core has forgotten the account. Never substitute a personal account.
Run fixture setup as the same web-server user; root-created dirty-queue files can
otherwise prevent the fixture from being written and invalidate the setup.

Local results: both native SQL lifecycle checks and the installed account-delete
plus retry drill passed; six focused PHP tests passed with 76 assertions. Five
previously deleted, dedicated audit accounts were repaired through the same
cleanup service, with zero retained EVA source/config/agent/generation rows and
zero chunks for their former document IDs. No frontend source changed, so no
frontend compilation was needed. These are sequential local checks, not proof of
in-flight legacy-writer fencing, a durable cleanup retry coordinator, distributed
races or external vector erasure; those acceptance gates remain open.

## Canonical generation-source preparation

The source-input refactor passed a PHP suite of 772 tests / 13,661 assertions /
8 skipped. After the final bounded-stream and optional-converter corrections,
65 focused extraction/indexer/chunk/provenance/Mail tests passed with 3,122
assertions. Cases include text beyond 100,000 characters, explicit pinned settings,
stable plan hashes, same-size content changes, a source growing after metadata
capture, access revocation, changed IMAP fallback, oversized headings, retained
overlap and early stop at a chunk budget. File/blob format tests now exercise the
shared extractor used by the ordinary Indexer too.

The installed Nextcloud probe uses actual native DI, VFS and SQL source metadata.
It creates and deletes its own fresh disposable account; the password remains
only in memory. Run as the web-server user:

```sh
NEXTCLOUD_ROOT=/var/www/html/nextcloud \
EVA_TEST_USER=eva-runtime-audit-source-example \
EVA_TEST_CREATE_DELETE_ACCOUNT=1 \
php tests/runtime/generation-source.php
```

The local run passed full-source preparation beyond the agent excerpt, pinned
chunk bounds, repeat plan identity and changed-source refusal. The disposable
account and source rows were removed through native deletion. No provider was
contacted, no migration worker was enabled and no frontend compilation was needed.
Mail/Talk preparation has contract coverage; live IMAP/room reconstruction,
converter/OCR time budgets, durable backfill control and complete remote
verification remain separately required evidence.

## Resumable background builds (1.18.10)

The installed migration added build and per-source manifest tables without
starting work or enabling external storage. Local Nextcloud 35 RC3 upgraded to
1.18.10 successfully. Existing non-build schema discrepancies remain separately
reported; upgrade success is not a globally clean schema claim.

The isolated build probe uses native SQLite migrations/repositories and checks
owned keyset processing, pause after inference, partial resume, changed-plan
restart, exclusive claims, lease expiry/reclaim, stale-token rejection, atomic
chunk/journal/checkpoint rollback, cancellation, target rotation, personal opt-out,
no-progress source-budget failure, account erasure, export and legacy/tenant
isolation. It does not run external provider calls unless explicitly configured:

```sh
NEXTCLOUD_ROOT=/var/www/html/nextcloud EVA_BUILD_SQL_FIXTURE=1 \
php tests/runtime/generation-build.php
```

With the loopback embedding fixture running, optional
`EVA_BUILD_HTTP_FIXTURE_URL=http://127.0.0.1:16435` and a fresh
`EVA_BUILD_NATIVE_TEST_USER=eva-runtime-audit-build-example` exercise actual
native VFS/access/extraction plus real HTTP embedding and isolated SQL
checkpoints/journal. The script creates/deletes that disposable account; no
live transport flag or vector collection changes. This combined path passed.

The separate native database probe uses the installed MariaDB connection and an
existing dedicated audit owner/document. All its SQL mutations are enclosed in
an outer fixture transaction and rolled back; only admission-switch methods are
test doubles. It passed native claim, chunk/outbox/checkpoint rollback and commit,
pause/resume and stale-token checks without provider/native-source I/O inside
the transaction:

```sh
NEXTCLOUD_ROOT=/var/www/html/nextcloud EVA_TEST_USER=eva-runtime-audit-example \
php tests/runtime/generation-build-sql.php
```

Run these developer probes as the web-server user with development dependencies.
The native probe refuses a nonempty build queue instead of touching unrelated
work. They establish sequential local behavior, not multi-process/distributed
failure ordering or a full database portability matrix.

Native browser checks cover generation metadata, an explicit origin-policy
refusal, the disabled-storage build gate, real boolean-consent handling, the
Nextcloud administration link and ordinary-user HTTP 403. An actual runtime
failure exposed Nextcloud removing the reserved `action` route parameter; the
corrected controller uses `operation`. A fixture-only success did not count as
native API validation. Native component browser checks additionally cover start,
pause/resume, staged-but-unverified presentation and uncertain writes without
automatic POST retries. No search activation or live Qdrant rollout is claimed.

## Persisted generation verification (1.18.12)

Two focused worker tests passed (12 assertions): an exact SQL/remote point and
source-manifest match issues a certificate, while a missing remote point fails
closed without issuing one. PHP syntax checks passed for every changed PHP file;
translation JSON parsed and the production webpack build compiled both app entry
points. The local Nextcloud 35 RC3 upgrade applied EVA 1.18.12 and the new
verification table, returned from maintenance mode, and reports
`needsDbUpgrade=false`. The anonymous app route returns the expected 401; no
authenticated frontend session was checked. The platform still reports the
previous unrelated Social/Mail schema drift and `oc_eva_ai_usage.estimated` type
discrepancy. Cross-database migration, native OCS/admin browser flow, concurrency,
source-writer coverage under load and real Qdrant reconciliation remain open.

## Bounded shadow comparison (1.18.13)

Three focused shadow-service tests passed (15 assertions), including verified-only
admission, aggregate-only output and stale-revision rejection. The Qdrant
inventory/search contract tests passed (4 tests / 38 assertions), including the
short shadow timeout and enforced owner/generation/scope filter. Combined with
the verification worker and bounded-indexer tests, the focused batch passed 14
tests / 86 assertions. PHP syntax, translation JSON and the production webpack
build passed. Local Nextcloud 35 RC3 upgraded EVA to 1.18.13, returned from
maintenance mode and reports `needsDbUpgrade=false`; the installed tree matches
the built source. The new OCS route returns the expected unauthenticated 401.
The admin-triggered comparison is not yet evidence of a live Qdrant ranking run,
a chat-path shadow sample or a multi-day soak; those remain open, as do
authenticated browser coverage and cross-database checks. Existing unrelated
Social/Mail and `oc_eva_ai_usage.estimated` schema discrepancies remain.

## Local SQL generation build and verification foundation (1.18.14)

The SQL backend now binds generations to a deterministic local target, writes
ready generation chunks and revisions directly in Nextcloud SQL, and bypasses
Qdrant point-outbox delivery. Its bounded verifier checks local vector shape,
revision, chunk hash and source manifests. Qdrant transport rejects SQL
generations, and SQL source cleanup creates no remote erasure work. The native
admin status shows backend-specific verification and hides Qdrant shadow probes
for SQL generations. New OCC generation metadata defaults to SQL.

Validation for this slice: PHP syntax checks for changed services/controllers
passed, translation JSON/JS catalogs were checked for exact parity, `git diff
--check` passed, and the production webpack build compiled both app bundles.
PHPUnit, browser, native database and live Nextcloud upgrade checks were not run
at the user's request to defer tests/PR checks. Normal file indexing and chat
search still use `sql_legacy`; SQL generation build and verification do not yet
prove search cutover, ranking quality, background incremental updates or
cross-database behavior. No retrieval activation is claimed.
