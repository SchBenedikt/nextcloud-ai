# Reproducible retrieval benchmark

The benchmark is an offline quality evaluator and exact-cosine oracle. It does
not require Nextcloud, an inference server or a vector database. It makes no
network connections and does not install or populate a search backend.

```sh
php tools/retrieval-benchmark.php exact benchmarks/retrieval/example > exact-report.json
php tools/retrieval-benchmark.php evaluate DATASET_DIR backend-runs.jsonl qdrant-dense > qdrant-report.json
```

The checked-in example is a tiny synthetic **format example**, not evidence of
semantic relevance, production recall or latency. Its category labels demonstrate
the schema; the three-dimensional synthetic vectors do not test language models,
BM25, phrase matching or real source-document diversification.

## Frozen dataset

A dataset directory contains:

- `manifest.json`: version 1 and SHA-256 of the exact bytes of `chunks.jsonl`.
  Record model/endpoint identity, dimension, chunk configuration, backend versions,
  hardware, cache state and concurrency alongside these required fields.
- `chunks.jsonl`: one object per line, ascending unique positive integer `id`,
  positive `document_id`, nonempty `user`, file `path` and a finite nonzero
  numeric `vector` array. Rows are streamed; all vectors are never retained in
  memory at once. Lines are bounded to 1 MiB and vectors to 16,384 dimensions.
- `queries.json`: 1–1,000 query objects with unique `id`, `user`, query `vector`,
  category, optional folder `scope` and `relevance` mapping chunk IDs to grades
  1–3. Categories: semantic, exact, mixed, phrase, diversification, scope, tenant.
  At most 1,000 relevant chunks per query; this JSON file is limited to 8 MiB.
  Store query text here only when the runner requires it; reports omit it.

Paths and users in synthetic corpora are fictitious. Real exports can contain
personal identifiers and embeddings; store them privately with restricted file
permissions and retention. Do not publish real corpora as PR fixtures.

The exact oracle scans the corpus for each query, filters tenant and scope before
scoring, holds at most 41 candidates before trimming to the best 20 and breaks
score ties by ascending chunk ID. It rejects dimension mismatches in eligible
vectors. It deliberately performs no lexical scoring, RRF or diversification;
compare ANN against exact cosine and hybrid search against the current EVA
baseline separately.

## Backend run format

Provide one JSONL row per query:

```json
{"query_id":"scope-1","corpus_sha256":"HASH_OF_CHUNKS","queries_sha256":"HASH_OF_QUERIES","ranking":[1,2,3],"duration_ms":12.5}
```

Rankings are ordered positive integer chunk IDs. Up to 1,000 entries may be
provided, but evaluation considers the first 20. Duplicate query rows, missing
queries, wrong hashes, missing timings, nonfinite or negative durations and
malformed IDs are rejected. The run file is limited to 8 MiB. A corpus checksum
is checked again after evaluation to detect modification during the run.

The runner producing the file must establish that it queried the same immutable
index snapshot and embedding space. Hash fields identify the asserted snapshot;
this evaluator cannot independently prove a remote backend actually indexed it.
Recording live EVA/Qdrant runs and verifying their snapshot is a subsequent
integration step. No live baseline has been measured by adding this tool.

## Results and failure behavior

Reports include hashes, PHP version, chunk/query counts, per-query metrics,
rankings and durations, macro mean metrics and nearest-rank p50/p95/p99 latency.
Peak memory is the evaluator process peak, **not** the backend's memory use.
Exact-oracle latency includes corpus I/O, record validation and vector scoring;
it cannot be compared directly with a service-only ANN latency as if the timing
boundaries were identical. Collect whole-retrieval and isolated stages separately.

Metrics: Recall@5/10/20, Precision@5/10/20 (denominator k, even for short results),
MRR@10, graded nDCG@10, distinct documents, source-document recall and duplicate
chunk rate. Document recall credits any returned eligible chunk from a relevant
document. Repeated chunk IDs never earn relevance credit twice. Empty rankings
score zero; queries without relevant labels are rejected rather than inflating
averages with undefined cases. Category labels remain in per-query results for
stratified comparison.

Foreign-tenant results, out-of-scope paths and IDs absent from the corpus are
counted as isolation violations, not silently removed. Any violation makes the
command exit 1; invalid input exits 2. Successful evaluation exits 0. Relevance
labels themselves must reference existing, eligible chunks. No raw query text,
vector, path or tenant identifier is included in output reports.

The acceptance gates in [BACKEND-ROADMAP.md](BACKEND-ROADMAP.md) remain unverified
until representative EVA and Qdrant runs exist. In particular this tool alone does
not establish million-chunk p95/p99, a cross-tenant security guarantee for a live
adapter, database portability, write consistency or successful rollback.

Evaluation metadata is capped at 100,000 distinct referenced chunk IDs (labels
plus top-20 results); inputs exceeding that bound are rejected. Corpus file paths
must be canonical relative paths without traversal, duplicate separators or
backslashes, so scope metrics cannot be distorted by ambiguous path syntax.
