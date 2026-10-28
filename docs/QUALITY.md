# RAG quality evaluation (Issue #146)

The authoritative CI workflow and the real Nextcloud app lifecycle matrix are
documented in [CI.md](CI.md). Quality claims in this document are backed by
the same PHPUnit, compatibility, lint, and frontend checks used for pull
requests.

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

## Baseline report

The suite prints a per-query baseline report. The last measured baseline is:

| Language | avg recall@5 | avg MRR | queries |
|---|---|---|---|
| de | 0.91 | 0.80 | 11 |
| en | 1.00 | 0.83 | 3 |

Only clear regressions fail the suite (recall@5 below 0.6 or MRR below 0.5 in
either language). The report includes per-stage detail so a future run can be
compared against this baseline before and after pipeline changes.

## Extending the fixtures

Fixtures live inline in `tests/RagQualityEvaluationTest.php::CORPUS`. Each
document is a `text` plus a `facts` map (`query => section heading`). The
fixtures intentionally cover: long documents, repeated-passage documents,
tables, nested headings, multilingual (German/English) text, mail-like
threads and facts near the end of a file. Add a fixture together with the
feature that changes extraction/chunking so the regression guard covers it.

## Native workspace validation (2026-10-28)

The native overview and settings refactor passes the production build, 48
frontend tests and 30 browser tests. PHP runs 725 tests with 8 environment-based
skips. ESLint reports no errors and 12 existing warnings. Browser coverage
includes all six settings sections at a mobile viewport, provider dialogs,
section history and direct chat submission. The controller regression test
saves provider JSON and reloads it through the settings API; another verifies
that an invalid later credential cannot overwrite an earlier one.

Screenshots in `screenshots/` use synthetic conversations in a browser fixture
with local Nextcloud styles. They are layout evidence, not authenticated
production or real-provider inference evidence.

Document-library follow-up adds browser regressions for retrying chunk loads,
loading the next chunk page and sending native filter selections to the API.
The mobile fixture includes Nextcloud's grid baseline variable so collapsed
navigation behaves like the real themed application.

After the follow-up, the combined tree passes 726 PHP tests (8 environment
skips), 48 frontend tests and all 32 browser tests. The production build and
ESLint also pass (12 existing advisory warnings, no errors).
