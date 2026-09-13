# Agentic file discovery

EVA can work with both the derived search index and the files that are currently
in the signed-in user's Nextcloud home. The two paths have different purposes.

## Indexed knowledge

Indexing extracts supported file formats into bounded chunks and makes them
available for semantic and lexical retrieval. It is the best path for questions
across a large library and for cited answers from older material. Background
indexing updates changed files incrementally.

## Direct discovery

`search_files` also searches readable files that have not been indexed yet. It
walks the user's VFS within bounded node/depth/result limits and extracts text
from common PDF, DOCX, XLSX, PPTX, ODF and EPUB containers without creating an
index job. Plain-text files remain searchable even when a Nextcloud MIME map
reports `application/octet-stream`, provided their extension is a known text
format. Unknown extensions receive a bounded UTF-8/control-character sniff and
are accepted only when they look like text; binary content is still rejected
by the NUL-byte guard. File hooks
advance a per-user search revision so a newly uploaded or changed file cannot
remain hidden behind the short-lived direct-search cache.

The scan is intentionally bounded and reports its own coverage. Use
`force_refresh: true` immediately after an upload or edit. For deeply nested
libraries, set `path` to the smallest known folder and increase `max_depth`
(1–10) or `max_nodes` (100–10,000) as needed; `max_results` limits only the
returned matches. The result includes `visited_nodes`, `extracted_documents`
and `truncated`, so EVA can tell you whether an empty result is definitive or
whether the configured scan limit was reached. Direct discovery never starts a
full indexing job and never writes index rows.

When an answer needs a file that is not indexed, EVA may use these read-only
tools in an authenticated web chat:

| Tool | Purpose | Bound |
| --- | --- | --- |
| `list_files` | Inspect a folder structure | bounded depth and entries |
| `search_files` | Find file/folder names and bounded readable text | bounded traversal and file bytes |
| `read_file` | Read a selected text file | 20,000 characters |

Every operation is scoped to the logged-in user's own Nextcloud filesystem and
uses Nextcloud's file APIs, so shares and revoked access are respected. EVA must
use these tools to answer a concrete request; it does not silently crawl a
library or transmit files to another service.

## Learning and changes

Explicit personal facts can be recorded in the user's `KNOWLEDGE.md`. The file
is private to that user, editable in Files, and can be deleted to remove the
learned notes. Automated learning ignores assistant text and questions.

Any mutation—creating, overwriting, moving, sharing, or deleting data—requires
the normal confirmation flow. Read-only discovery never creates or changes a
file.
