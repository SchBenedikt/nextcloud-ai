# Generation-bound embeddings

A generation fixes its embedding provider/profile, endpoint hash, model and
vector dimension. The new generation adapter uses that identity directly rather
than the chat provider, model discovery or a fallback chain. Ordinary SQL
indexing/retrieval still uses the existing `EmbeddingProvider`; installing or
upgrading EVA does not start a backfill or require an external database.

## Explicit configuration check

In EVA administration, **Embedding generations** lists up to 50 generations for
the selected indexed account. Selecting an account/generation only reads SQL
metadata. **Check embedding model** explicitly sends one public test sentence;
it bypasses the embedding cache and sends no Files/Mail/Talk content. It checks
the provider-reported model and every returned vector's dimension, finite values
and nonzero magnitude. A passing check is not complete-generation verification,
ANN quality evidence, a progress checkpoint or search activation.

The corresponding OCC command is:

```sh
occ eva_ai:vector:embedding USER GENERATION_ID
```

It requires an existing, enabled canonical owner, an open owner fence and an
owned `building` generation. Generation metadata can still be created with
`eva_ai:vector:generation create`; this check does not create collections or
change its lifecycle. No secret, arbitrary text or vector upload is accepted by
the check API. The server restores its previous personal-settings scope even
when the probe fails.

## Provider contracts and credentials

- Ollama uses `POST /api/embed` with the generation model and `truncate: false`.
  There is no fallback to `/api/embeddings` or another model. The reported model
  must match; Ollama's omitted default `:latest` suffix is treated as the same
  name. Input truncation must fail instead of silently embedding different text.
- OpenAI-compatible profiles use their configured base URL plus `/embeddings`,
  the generation model and `encoding_format: float`. Responses are reordered by
  their explicit input indexes; missing, duplicate or out-of-range slots fail the
  whole batch. Existing profile chat-model selection does not override the
  generation model. Providers without this embedding contract cannot build such
  a generation merely because chat works.

These payloads follow the [Ollama embed API](https://docs.ollama.com/api/embed)
and [OpenAI embeddings API](https://developers.openai.com/api/reference/resources/embeddings/methods/create).
The adapter does not request dimension reduction; the configured generation must
match the provider's actual default output space. It does not infer model identity
from a legacy SQL vector, response dimensions alone or the configured chat model.
Named-model validation cannot prove that an operator/vendor has not changed the
weights behind the same name; generation verification and operational model
version pinning remain required before cutover.

Configuration is captured from fresh owned `preferences` rows, falling back to
instance `appconfig` and the existing Ollama default. It bypasses request-local
settings caches. Profile URL/auth and encrypted-key snapshots are rechecked after
DNS resolution, before transfer and before accepting/caching the result. Key
rotation, profile removal/retargeting or owner/lease guard failure cannot silently
switch an in-flight request to another destination. Compatible-provider keys use
the existing encrypted per-owner preference format, never an instance/other
account's credential. Plaintext keys and captured configuration are never API
responses or log fields.

Native Nextcloud HTTP transport applies `ProviderEndpointPolicy`, DNS pinning,
redirect refusal and administrator-owned private-origin approval. Local Ollama
origins also require that explicit approval on this generation path; the legacy
SQL path is unchanged. HTTP connections to an approved private origin are an
administrator choice; other origins require TLS. The adapter uses a 30-second
request/read deadline, five-second connect deadline, eight inputs and 131,072
vector elements per request, with a four-MiB response ceiling. There are no
implicit retries or unbounded recursive batch splitting on this path. The explicit
background builder supplies the fresh source/lease/cancel guard; the probe supplies
fresh native owner/generation guards. No external I/O belongs inside a SQL
transaction.

## Proven cache entries

Generation requests use the existing distributed embedding-cache namespace and
30-day TTL, but a separate versioned key family. Identity includes owner,
provider/profile, endpoint, requested model, dimension and metric. Exact text
bytes are hashed; distinct whitespace is not silently coalesced. Duplicate exact
inputs in one request are sent once and restored to their original positions.
Different generations may reuse a proven vector only with the same embedding
identity and text. Existing per-user cache erasure covers these entries too.

Legacy cache records are not automatically promoted: their configured-model key
alone does not establish which fallback model produced a historical vector. Cache
outages remain misses/best-effort writes. Complete response batches are validated
before any returned vector is published or cached. A cached result still checks
current owned configuration and the caller's guard.

The ordinary indexer may seed this generation-specific key family with vectors it
has just received from the provider, but only when the enrolled user's active or
building SQL generation matches the exact model, endpoint, dimension, metric and
chunk settings. This lets the subsequent bounded build or mutation worker reuse
identical chunk text without repeating provider work. A matching active SQL
generation is extended through the transactional mutation journal; it does not
start a redundant full rebuild. Qdrant primary selection is left unchanged.

The automatic local SQL provisioner creates Ollama generations from the regular
indexing settings. If an administrator explicitly activates an SQL generation
using an OpenAI-compatible embedding profile, later legacy-index batches preserve
that selected generation; they do not silently replace it with an Ollama build.
Changing the immutable provider/model requires preparing and verifying a new
generation through the generation controls.

## Validation and remaining backfill work

The optional `tests/runtime/generation-embedding.php` probe uses native Nextcloud
HTTP, isolated in-memory SQLite and the disposable loopback PHP fixture server.
It covers both response contracts, owned encrypted credentials, actual post-DNS
profile/key mutation rejection, exact-input coalescing, cross-generation reuse and
cross-owner isolation. It does not call a real hosted provider or prove native
user/source access; those guards have separate tests.

Durable document/chunk cursors, claim leases, heartbeat, pause/resume/cancel,
canonical native source extraction, reconciliation and complete remote ID/revision/
dimension/sample verification are the next stage-09 slices. The check introduced
here deliberately does not advertise any of those as completed functionality.


The explicit build/control workflow is described in [GENERATION-BUILDS.md](GENERATION-BUILDS.md). A successful model probe and a staged pass still do not establish complete generation verification or search activation.
