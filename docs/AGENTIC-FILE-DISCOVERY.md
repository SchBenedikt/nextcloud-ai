# Agentic file discovery

EVA can work with both the derived search index and the files that are currently
in the signed-in user's Nextcloud home. The two paths have different purposes.

## Indexed knowledge

Indexing extracts supported file formats into bounded chunks and makes them
available for semantic and lexical retrieval. It is the best path for questions
across a large library and for cited answers from older material. Background
indexing updates changed files incrementally.

## Direct discovery

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
