# Bounded generation shadow comparison

The shadow panel is an explicit administrator diagnostic for a staged,
currently verified generation. It is never called by chat, file-context or
search flows. SQL remains the answer path and the comparison cannot activate a
generation.

## What it compares

After the administrator enters a short sample query and presses **Compare top
results**, EVA sends that query once to the generation's pinned embedding
provider and compares two dense rankings over the same generation vectors:

1. An exact cosine ranking calculated from the generation's SQL chunks.
2. The filtered Qdrant top-k result, restricted server-side to the generation's
   owner, generation ID, live points and optional Files folder scope.

Returned Qdrant IDs and revisions are checked against current SQL before metrics
are shown. The response includes candidate counts, overlap/recall/precision,
first-result agreement and SQL/embedding/vector timings. It does not return IDs,
chunk content or the sample query.

## Privacy and limits

The query is sent only after an administrator explicitly submits the native
settings form. The UI says which pinned embedding provider and vector store
receive it. The sample and vector are transient: EVA does not save them. Logs
contain only generation ID, sanitized stop reason and aggregate counts/timings;
they never contain sample text, vectors, result IDs, source paths or answer
content. A Files scope path is hashed by the existing vector filter before it
reaches Qdrant.

The request accepts at most 8 KiB of valid UTF-8 text, at most 20 results, and
requires the query to fit the generation's chunk size. Only one probe per
generation may run at once. Embedding has a 3-second transport timeout, Qdrant
has a 2-second timeout, SQL scans at most 20,000 rows or 3 seconds, and a
12-second guard rechecks generation, target, point delivery and certificate
identity before and after transfers. If a limit is reached or a source revision
changes, the comparison stops without changing retrieval.

## Evidence boundary

This diagnostic compares dense ranking for a manually selected sample; it does
not compare the full hybrid lexical/RRF behavior, establish production recall,
measure workload latency or replace a multi-day shadow/soak period. It does not
provide canary or primary vector search. These are later roadmap gates. SQL stays
the rollback-free active path throughout.
