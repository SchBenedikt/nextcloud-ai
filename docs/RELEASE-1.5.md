# EVA 1.5: implementation and deployment notes

This change finishes the implementation started for the issue backlog. It does **not** claim to resolve every open issue. Final runtime acceptance is outstanding: at the owner's request no further tests or Nextcloud installation were performed. The production frontend was rebuilt; a build is not a functional test. Earlier development checks do not establish that this final revision passes the test suite.

## Conversations and projects

Conversations move from appdata JSON to user-scoped database metadata and ordered messages. The first access imports legacy chats under a shared Nextcloud lock and transaction. Import failures roll back instead of silently accepting malformed JSON. The new store does not truncate conversations to 200 messages; it cannot restore messages already discarded by older releases. History is paginated, and Markdown export requests all pages. Search covers titles and message text with matching snippets. Projects support assignment, renaming, archiving and deletion without deleting their chats. Chats support pinning, archiving, custom instructions, and branching with an optional edited user message. Branching preserves the original; automatic regeneration is not included.

The migration creates `eva_ai_chat_items` and `eva_ai_messages`, plus chunk provenance. Back up the database and appdata before deployment. Legacy files remain as recovery data, but are not updated after migration. Downgrading therefore does not preserve newly written database conversations automatically. The app identifier remains `eva_ai`, preserving existing routes and configuration namespaces.

## Retrieval and indexing

Dense retrieval scans indexed vectors in bounded pages and keeps a bounded best-candidate pool instead of sampling only a fixed initial set. Lexical retrieval and dense scores are combined, with per-document diversity. Full scanning still has linear CPU/database cost; it is not an approximate-vector index. File-context chat retrieves relevant chunks within the selected accessible document IDs instead of taking only document beginnings. Source numbers now identify the corresponding evidence chunk.

Chunking preserves repeated passages and line boundaries and records normalized character offsets and heading hints. Provenance is not a claim of exact original PDF page coordinates. Document chunk APIs and UI use bounded pages; aggregate document totals are calculated separately from the current page.

Embedding requests run in batches of 24. Replacement documents remain unpublished until embedding completes, preserving the previous searchable document if staging fails. Per-user periodic jobs replace long sequential work in the scheduler; requested indexing yields after one pass and schedules a continuation. This does not implement a global worker-concurrency controller or incremental file-event indexing.

## Extraction

Bounded ZIP/XML extraction supports common OOXML and OpenDocument containers, including spreadsheet sheet/cell labels and formula text, presentation notes and EPUB XML content. Invalid or unsupported content can fail explicitly; arbitrary HTML EPUBs and every Office layout are not guaranteed. Source files and cumulative extracted XML are limited to 32 MiB, with a 5,000-entry ZIP limit and network-free XML parsing.

Optional host tools provide additional coverage: LibreOffice (`soffice`) for legacy Office files, `pdftotext` or `mutool` for PDF text, and `pdftoppm` plus `tesseract` for opt-in scanned-PDF OCR. Image OCR also uses Tesseract. No tools or Nextcloud server are installed by this change. Subprocesses have a 30-second timeout each; PDF OCR is capped at 20 pages. That is a per-process limit, not a 30-second total-document deadline. Availability appears in settings.

## Models, context and privacy

Operational settings inherit administrator defaults when no user override exists. Consent and user state remain user-specific. External weather requests and classification of Talk messages without explicit bot mentions require opt-in. Personal knowledge can be disabled in answers.

Model roles use Ollama capability metadata when available; unknown capabilities require user choice. Known incompatible model roles are rejected, but this does not perform a real embedding inference before saving. Capability discovery is cached and bounded. Optional summary and tool models and up to three chat fallback models are configurable. Fallback selects an installed model before generation; it does not retry a partially streamed answer or mix embedding models. The actual streamed model is displayed. Chat HTTP timeouts are 90 seconds per request, not a total multi-round deadline.

A heuristic context budget reserves space for output, removes older context and retains the active question and system rules; oversized mandatory instructions fail explicitly. The estimate is not the provider's tokenizer. Retrieved excerpts and personal knowledge are presented as untrusted evidence, separately from the active question. Standalone chat uses the shared Vue workspace.

## Certificate request

The existing upstream request used `eva-ai` and `eva/eva-ai.csr`, inconsistent with the application's actual `eva_ai` identifier and the required directory convention. The replacement public CSR is `docs/certificates/eva_ai/eva_ai.csr`, with CN `eva_ai`, for upstream path `eva_ai/eva_ai.csr`. A fresh RSA-4096 private key is kept outside this repository. The CSR is not a signed certificate; Nextcloud approval and issuance remain external requirements. Do not publish the private key.

## Issue coverage and remaining work

Related implementation areas: #60, #61, #62, #63, #66, #69, #73, #74, #75, #77, #78, #81, #83, #84, #86, #87, #89, #90, #91, #92, #96, #112, #118, #140, #141, #143, #144, #147, #148, #151, #152, #153. These links describe scope, not completed acceptance. In particular #81 lacks automatic regeneration, #83 lacks a full account-deletion integration, #89 lacks suggested follow-ups, #141/#142 lack a global memory/concurrency guarantee, and #153 does not expose all project metadata in the UI.

Other open requests remain outside this completed batch, including web search (#54), mail attachment/body expansion (#64), file hooks (#79), knowledge management (#80), admin dashboard (#82), Talk commands/room settings (#85), advanced retrieval filters (#88), activity-query work (#113), screenshots (#119), browser/live integration coverage (#133–#136), evaluation corpus (#146), Forms (#149), and audit events (#150). The PR deliberately does not automatically close these issues. Nextcloud-version compatibility, migration on a real database, concurrent workers and provider interoperability still require acceptance verification.
