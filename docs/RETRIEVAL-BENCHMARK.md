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
The explicit OCC integration below can record live SQL and Qdrant runs and
verifies their snapshot. No live baseline has been measured by adding this tool.

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

## Explicit live EVA snapshot and run

An administrator can export one existing user's index and record the current
SQL Searcher through OCC. These operations are not run during installation,
requests, cron or upgrades. Stop indexing and quiesce index/configuration changes
before running them; the command does not suspend the scheduler or acquire a
long-lived lock. Fingerprints are a before/after consistency check, not a claim
of serializable database snapshot isolation across the entire benchmark.

Create a private query seed file with actual chunk relevance labels:

```json
[
  {"id":"my-query-1","text":"A question for the frozen corpus","category":"semantic","relevance":{"123":3},"scope":"Documents"}
]
```

The ID `123` is an example; choose real relevant chunk IDs from the user's index.
Supply 1–100 queries. The export rejects malformed query shapes before inference.
Query seed text is explicitly sent to the user's configured embedding endpoint;
no document text is sent by this export. Existing stored embeddings are copied.

```sh
# Run as the Nextcloud web-server user from the Nextcloud directory.
php occ eva_ai:retrieval:benchmark export alice /private/eva-baseline --queries=/private/query-seeds.json
php occ eva_ai:retrieval:benchmark record alice /private/eva-baseline --output=/private/eva-sql-runs.jsonl

# From the EVA repository, evaluate the two baselines separately.
php tools/retrieval-benchmark.php exact /private/eva-baseline > /private/exact-report.json
php tools/retrieval-benchmark.php evaluate /private/eva-baseline /private/eva-sql-runs.jsonl eva-sql-legacy > /private/eva-report.json
```

Export directories and output files must be new. They are created with private
permissions (0700 directory, 0600 files) under a restrictive temporary umask.
Exports contain real user identifiers, paths, names, vectors and query text;
treat the entire directory as personal data. Content/provenance hashes are
included instead of chunk text. The endpoint is represented by a hash; provider
credentials are never exported. Failed exports remove the files they created;
failed recordings remove incomplete result files and restore the previous config
user context. Existing targets are never overwritten.

The SQL mapper uses tenant-bound keyset pagination with at most 500 rows per page.
Snapshot bytes pin IDs, document metadata, content/provenance hashes and vectors.
Full keyset fingerprints are checked before and after query embedding and after
recording. A separate direct-SQL hash of the user's preferences and instance app
configuration detects changes without relying on IConfig's process-local cache;
only that hash, not raw configuration or secrets, is exported. A running index or
changed corpus/configuration/revision aborts the operation. Unrelated user changes
are not read; unrelated settings for the selected account can conservatively
invalidate the snapshot.

Record verifies that fresh query vectors equal the frozen vectors, then passes
that exact vector to Searcher without a second embedding request. Lexical, RRF,
phrase and diversification rules remain in place. This explicit benchmark mode
forces the frozen dense query even when interactive cloud-chat policy would use
lexical-only retrieval. Each row declares `query_vector_mode=frozen` and reports
`embedding_preflight_ms` separately. The Searcher duration excludes fingerprint
and preflight work and does not rely on embedding-cache availability; it must not
be labeled a cold-cache end-to-end chat measurement. A changing or nondeterministic
embedding provider causes rejection instead of comparing different vector spaces.
The full quality/latency evaluator validates the recorded file before success;
isolation violations make recording fail.

Only the explicitly selected user's index is exported. A returned foreign chunk
not present in this corpus is an unknown-ID isolation violation; it cannot be
classified by the foreign owner's identity without a deliberately constructed
multi-tenant offline corpus. This avoids automatically exporting another account's
data just to run an adversarial evaluation.

Legacy chunks do not carry a trustworthy embedding-model identity. Manifests
therefore set `embedding_provenance=legacy_unverified` and
`vector_migration_eligible=false`. The declared configuration documents the current
runtime, not the model that produced every old chunk. Successful snapshot recording
does not authorize adopting these vectors into a verified production generation.
No live snapshot or performance measurement is claimed by committing this command.

## Explicit live Qdrant generation snapshot and run

For a verified, active Qdrant generation, export and record the exact generation
through the same explicit OCC command. Stop indexing first. The selected user's
generation must be active with no pending source changes; exports fail closed if
the active generation, target, account configuration, search revision, or SQL
generation rows change during fingerprinting or recording.

```sh
php occ eva_ai:retrieval:benchmark export-qdrant alice /private/eva-qdrant \
  --queries=/private/query-seeds.json
php occ eva_ai:retrieval:benchmark record-qdrant alice /private/eva-qdrant \
  --output=/private/eva-qdrant-runs.jsonl
php tools/retrieval-benchmark.php exact /private/eva-qdrant > /private/eva-qdrant-exact.json
php tools/retrieval-benchmark.php evaluate /private/eva-qdrant /private/eva-qdrant-runs.jsonl eva-qdrant-dense \
  > /private/eva-qdrant-report.json
```

The generation export includes only delivered chunks owned by the selected user,
their pinned SQL vectors, source metadata and hashes. Use relevant chunk IDs from
the exported `chunks.jsonl` in the query seed file's `relevance` mapping. Query
text is embedded through that generation's pinned provider configuration.
Dense retrieval sends the vector to Qdrant; native hybrid retrieval also sends
query text only when text payload was explicitly enabled for that generation.
The recorded duration includes Qdrant search and SQL hydration/validation;
query embedding preflight time is reported separately. It does not represent
full chat latency or establish percentile performance from a single run.

Files and directories must be new and are created with private permissions.
Real generation exports include user identifiers, paths, names and vectors;
keep them private and retain them only as long as needed. These commands remain
explicit administrator operations and add no automatic indexing, chat-path, or
provider calls.
