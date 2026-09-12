# EVA AI – Features, How It Works, and Limitations

EVA (`eva_ai`) is a self-hosted AI assistant for Nextcloud. The app combines a
chat interface with local Ollama, a searchable knowledge base derived from
Nextcloud content, and controlled Nextcloud actions. This document describes what
EVA can do, which interface provides each feature, and where deliberate security
boundaries exist.

## 1. Interfaces

### EVA Web App

The web app is the full-featured workspace. Users can create, search, rename,
delete, and export chats as Markdown. Responses are streamed; loading, empty,
error, and retry states are reflected in the UI. The document view lets you
browse your personal index page by page. Settings expose Ollama, models, index,
retrieval quality, and tool configuration.

EVA may only suggest changes in an interactive, authenticated context. Before
execution the UI shows the exact tool name and its arguments. Only **Confirm and
execute** carries out the action; **Cancel** changes nothing.

### Admin Dashboard

Administrators get a dedicated **Eva AI** section in the Nextcloud administration
settings (Issue #82). It shows a per-user overview of the RAG index: document and
chunk counts, last index time, background-indexing enrollment, index state, and
the most recent per-user error. From there an admin can queue a re-index for a
user, reset a user's complete index, and toggle enrollment – all without ever
seeing file names, paths, or content (metadata only). Every endpoint is
`#[AdminRequired]`; non-admins receive a 403.

### Nextcloud Files

Via **Open with EVA** a single file can be passed to EVA. With a multi-file
selection EVA opens a shared file context. Answers are derived solely from the
selected, already-indexed files and are not supplemented by other index hits.
This makes the feature suitable for comparing multiple documents or summarising a
specific folder.

### Nextcloud Assistant / TaskProcessing

EVA registers providers for chat, summarisation, headline, topics, translation,
rephrasing, correction, formatting, tone adjustment, and context-aware writing.
Additional providers exist for chat with shared tools and for multi-step agent
interactions. Provider execution requires a running Nextcloud TaskProcessing
worker.

Tool definitions or system instructions passed by the caller do not widen
permissions. EVA uses its own central tool policy. Write actions are only
executed in the dedicated confirmed Assistant step. When the caller asks for a
Talk conversation as context, each room id is verified against Talk's
participant list before a single message reaches the model, and the indexed
older history of those rooms supplements the recent messages.

### Nextcloud Talk

Optionally EVA can be registered as a Talk bot and enabled per conversation. The
bot answers questions using the index of the user who added it. Talk is
deliberately **read-only**: file, contact, calendar, share, and task changes are
not offered there. Particularly sensitive read tools such as profile,
share-listing, and server-status access are also blocked. Automatically included
Talk history is capped in size.

The bot reacts when it is addressed (`@Eva` mention, configured trigger, or a
message that really is a question to it) and stays silent otherwise. Besides the
few most recent messages of the room, it uses the **indexed history of that same
room**: the older passages matching the question are retrieved and added as
context, so an answer can refer to something said weeks ago. Only rooms the user
is a member of are indexed and retrieved, the lookup is scoped to the room, and
membership is checked again at answer time.

Indexing the chat histories is a separate, per-user decision. A switch includes
them in the regular indexing pass, and a dedicated button ("Only index Nextcloud
Talk chats") indexes them immediately, with bounds on how many chats and how many
messages per chat are read.

### Command Line and Background Jobs

`occ` commands support setup, indexing, resetting the index, mount overviews,
direct tool diagnostics, and Talk bot registration. A background job processes
enrolled user accounts incrementally. Large collections are continued across
multiple bounded runs instead of launching a single unbounded process.

## 2. Local Knowledge Search (RAG)

### Indexable Content

EVA can process plain text, Markdown, source code, CSV/TSV, HTML, JSON, XML,
YAML, TOML, RTF, SQL, PDF, Microsoft Office files, OpenDocument files, and
EPUB. It also reads saved mail (`.eml`), web archives (`.mht`/`.mhtml`),
mailboxes (`.mbox`) and Jupyter notebooks (`.ipynb`): a mail contributes its
envelope (subject, sender, recipients, date) and its readable body, and a
mailbox contributes every message it contains. Roughly 35 further text formats
are recognised by extension, among them `patch`, `diff`, `json5`, `tf`,
`proto`, `graphql`, `svelte`, `dart`, `rss`, `kml` and `gpx`. Emails from the
Nextcloud Mail app are included in the index when that app is installed, and so
can be the user's Nextcloud Talk chat histories (see above). Each of these
non-file sources is stored under its own source marker, so a cleanup pass for one
of them can never remove entries of another. Size, path, and count limits prevent
a single run from consuming unbounded resources, and a file that cannot be read
is skipped instead of stopping the run.

On first authenticated launch EVA may create a clearly marked, editable profile
section in `KNOWLEDGE.md`. It contains only the intended baseline data and does
not overwrite existing notes. Personal facts mentioned explicitly later can be
added via `update_knowledge`.

### Processing Pipeline

1. The indexer walks the permitted portion of the personal file tree.
2. Supported content is extracted and split into overlapping chunks. A file
   that fails halfway - a corrupt container, an unsupported encoding, a
   password protected document - is skipped with a logged reason, and the rest
   of the batch is indexed normally.
3. Ollama generates embeddings for these chunks.
4. EVA stores document metadata, text chunks, and vectors in the Nextcloud
   database.
5. Deleted, changed, or no longer readable sources are updated or removed during
   reconciliation.

When a question is asked EVA combines semantic vector search with lexical BM25
search. Reciprocal Rank Fusion merges both rankings. Only a limited number of
matching chunks reach the model prompt as unprivileged context. The answer cites
the source paths used. Found document text is treated as data, not as system
instruction; prompt injections hidden in documents therefore receive no elevated
permissions.

### Index Control

Users can manually start, stop, and reset the index as well as set included
paths, exclusions, maximum file size, and files per run. Starting enrolls the
account for periodic runs; this enrollment can be disabled again. A stop remains
visible as `stopping` until the worker has actually finished. Partial results
prepared before an abort request are not published.

Deleting the index removes only derived document entries, chunks, and vectors.
Original files and chat histories are preserved.

## 3. Available Tools

The following groups describe the capabilities. Exact parameter names, limits,
and return formats are documented in [`TOOLS.md`](../TOOLS.md).

### Files and Knowledge

- List files and folders with bounded results.
- Open readable text files.
- Search file and folder names as well as limited text content; hits include the
  match reason, a snippet, and a flag for truncated searches.
- Create text files and Markdown notes, create folders, and rename entries.
- Delete files or empty folders when the configured delete mode allows it. In the
  default mode EVA may only delete entries marked as self-created via stable file
  IDs; renaming does not remove that association.
- Append explicitly stated personal facts to `KNOWLEDGE.md`. When the limit is
  reached old regular notes are removed while the auto-created identity section
  is preserved.

### Contacts and Profile

- Search reachable address books by name, email, or organisation.
- Create, update, or delete contacts with name, email, phone, and organisation
  when the address book grants write access.
- Read your own Nextcloud profile and update selected fields such as display
  name, email, phone, website, address, organisation, role, headline,
  biography, and pronouns.

### Calendar and Tasks

- List calendars and retrieve events within a bounded time window. Repeats with
  RRULE, RDATE, EXDATE, and RECURRENCE-ID are expanded with limits; timed events
  are emitted correctly as UTC.
- Create, update, or delete events with location, description, categories,
  duration, and reminders. Local times are interpreted in the user's timezone;
  date-only values produce all-day events.
- Search for free time slots. EVA queries the CalDAV server with the relevant
  time range directly and does not load the entire calendar.
- List, create, update, complete, and delete VTODO tasks; supported fields
  include due date, description, categories, status, and priority.
- Read-only calendars are rejected before any change is attempted.

### Email

When the Nextcloud Mail app and its API are available, EVA can list recent or
unread messages, search by subject/sender/preview, read individual messages, and
return the unread count. Search patterns and result sets are bounded. The mail
index can be disabled separately.

### Shares

- List outgoing and incoming shares.
- Create public link as well as user and group shares with optional password,
  expiry date, write permission, and note.
- Update or delete your own shares via their stable provider ID.
- Existing public tokens and URLs are stripped from list results so they never
  reach the model context or chat history. Only a freshly created link is
  returned once as a result.

### Activity, Time, Weather, and Status

EVA can read your personal Nextcloud activity stream, determine the current date
and time in your timezone, provide technical app/Ollama information, and fetch a
three-day weather forecast from Open-Meteo. Before calculations involving
relative date references the model should always use the time tool.

The weather fetch is an optional external network request. It is separate from
the local RAG and Ollama data flow; anyone who does not want any external
request should not use the weather tool or disable it via the policy.

## 4. Security Model

All tools run in the context of the logged-in Nextcloud user. EVA therefore
receives no global file permissions and may not browse other users' data.
`ToolPolicy` is the central allowlist and distinguishes between read, write, and
destructive actions as well as between Web, Talk, RAG, and TaskProcessing.

Key protective measures:

- Write and destructive actions require explicit confirmation on supported
  interactive interfaces.
- Talk and the normal RAG/TaskProcessing suggestion phase remain read-only.
- Paths are normalised relative to the personal home directory; traversal
  outside that scope is rejected.
- Write content, search runs, result counts, context chunks, and agent rounds
  have fixed upper limits.
- EVA re-checks the current read access before a cached RAG hit is used.
  Entries that are no longer accessible are discarded.
- Ollama URLs accept only the intended HTTP(S) components and no embedded
  credentials, query parameters, or fragments.
- Public share tokens are not passed to the model as existing data.
- Orphaned agent states are cleaned up on a timer.

Further details and the threat model are in
[`SECURITY.md`](SECURITY.md); privacy information is in [`PRIVACY.md`](PRIVACY.md).

## 5. Privacy and Data Locations

Files remain in Nextcloud. Index data lives in the EVA database tables, chat
histories in a per-user-hashed AppData namespace, and action flags in AppData.
Model requests go to the configured Ollama URL. When the Ollama service runs on
a different host, prompt and context leave the Nextcloud host accordingly;
operators must secure that connection themselves.

The core features require no commercial AI API key. Optional external features –
currently weather via Open-Meteo in particular – are not equivalent to the local
knowledge search and should be enabled based on your own privacy requirements.

## 6. Deliberate Limitations

- EVA is not an authorisation system: Nextcloud remains the authoritative source
  for identity, shares, and DAV permissions.
- Unsupported, encrypted, or too-large files are skipped.
- Answer quality depends on extraction, index state, search hits, and the
  selected Ollama model. Source citations do not replace expert review.
- File-context chats require already-indexed files.
- Mail, Talk, Notifications, and Assistant only work when the respective
  Nextcloud apps are installed, enabled, and correctly configured.
- TaskProcessing jobs require a worker; without one they remain queued.
- Live web search is opt-in per instance (off until an administrator enables
  it) and works without an API key through DuckDuckGo and Bing. A search query
  leaves the instance, so it must not be used for the user's own data, and a
  page the reader opens is fetched and quoted - including its pictures - which
  makes the source, not the model's memory, the authority for current topics.
- Confirmed actions are protected against accidental execution but not
  guaranteed as distributed exactly-once transactions. Concurrent requests or
  infrastructure errors may still require a re-check of state.

## 7. Typical Use Cases

- "Summarise the three selected project reports and name the differences."
- "Which of my notes mention the 2027 budget?"
- "Show my appointments for the next seven days and find a free 45-minute slot
  on Wednesday."
- "Draft a meeting minutes file as Markdown." – EVA shows the planned file path
  and content before execution for confirmation.
- "Find Erika's contact and then create an appointment." – Reading can happen
  directly; each change is confirmed separately.
- "Which unread emails are about the invoice?" – available when the Mail
  integration is enabled.
- "Create a download link for the report, valid until Friday." – the new link is
  returned once after confirmation; subsequent listings mask it.
- "What changed in the newest Nextcloud release?" – a web answer, with the pages
  it used listed as sources under the answer so every claim can be checked.

## 8. Further Documentation

- [`TOOLS.md`](../TOOLS.md) – full tool parameters and return values
- [`CONFIGURATION.md`](CONFIGURATION.md) – all settings and defaults
- [`ARCHITECTURE.md`](ARCHITECTURE.md) – components, data flow, and tables
- [`SECURITY.md`](SECURITY.md) – security boundaries and operational measures
- [`PRIVACY.md`](PRIVACY.md) – processed data and privacy
- [`DEVELOPMENT.md`](DEVELOPMENT.md) – development, build, and tests
- [`FAQ.md`](FAQ.md) – common questions and troubleshooting
