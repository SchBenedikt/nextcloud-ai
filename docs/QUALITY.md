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
