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
includes 44 frontend unit tests, 33 fixture-browser tests and a PHP suite with
722 tests (8 skipped). The source-generation staging/deletion probe also ran
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
