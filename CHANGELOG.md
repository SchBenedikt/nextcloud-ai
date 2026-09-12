# Changelog

All notable changes to **EVA (eva_ai)** are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project
follows [Semantic Versioning](https://semver.org/).

## [1.11.0] - 2026-09-12

### Fixed

- **Talk chat histories were never indexed.** Every transcript threw
  `Call to a member function getInt() on null` (a configuration read from a
  property that does not exist), the pass caught it per room and reported
  "processed: 0" without an error, so the feature looked enabled and had never
  stored a single message. Found on a live instance; the log line was
  `Undefined property: TalkTranscriptService::$config`.
- **A room with one long message was unreadable.** The transcript was built
  through Nextcloud's Comments API, which validates a comment against a
  1000-character limit *while reading it*, while Talk accepts much longer
  messages - EVA's own Talk answers already exceed it. One such message made the
  whole room throw and be skipped. The rows are now read directly, with the same
  filters, so no message length can silence a room.
- **A room with nothing but machinery is no longer stored.** A changelog room or
  a room holding only the "conversation created" line produced a header-only
  document that was searchable as an empty room.
- **An abandoned index run blocked indexing for good.** A worker killed mid-run
  leaves its claim behind, and every later manual run and cron pass answered
  "already running" while nothing was running. The rule that ends such a run now
  lives in one place (`AppConfig::recoverAbandonedRun()`) and is used by every
  entry point; it used to be written out four times with different details.
- **Pictures from the web were shown as links.** Nextcloud's own image policy is
  `img-src 'self' data: blob:`, so a correct markdown image in an answer was
  blocked by the browser and degraded to a link. Both pages that render answers
  now allow remote pictures - an image the answer embeds is the whole point of
  the picture feature - while the pictures themselves stay constrained: no
  referrer is sent and they load lazily.

### Added

- `occ eva_ai:talk <user>` reports Talk availability, the effective settings,
  every room with its readable message count and its stored document, and can
  run the pass (`--index`) and prove recall (`--room <id> --ask "<question>"`).
- [docs/TALK-INDEXING.md](docs/TALK-INDEXING.md): the whole path - live context
  versus indexed history, what is deliberately left out, where the data is
  stored, how membership is enforced at answer time, and what each failure looks
  like.
- The assistant now shows pictures without being asked when the topic is
  something visible (a product, device, place, building, event, person, animal,
  dish or artwork): a web search about such a subject also looks for pictures and
  embeds two to four of them.

## [1.10.2] - 2026-09-12

### Fixed

- **Google News links are no longer fetched or rendered.** They are redirect
  links that resolve to Google's consent interstitial, not to the article: a live
  probe returned `consent.google.com`, titled "Before you continue", with ~1,400
  characters of cookie notice. Three such results in one news search consumed the
  entire 26-second render budget and produced nothing, and the interstitial text
  could otherwise have been quoted as if it were the article. They stay in the
  result list as headline references - the publication and date are real
  information - but the URL policy now refuses aggregator and consent hosts on
  every fetch and render path, including a redirect that lands there.

## [1.10.1] - 2026-09-12

### Fixed

- **Browser rendering reported itself ready when it could not work.** Playwright
  ships as an npm package that downloads its browsers separately, so installing
  the package alone leaves a feature that looks available, is silently never
  used, and costs a process spawn per page for nothing. The settings page now
  checks for a Chromium build as well and reports the one missing piece, and
  `web_search_browser_browsers_path` points the app at a browser installed
  somewhere other than the web server account's home directory.
- **The renderer now searches the directory that was actually checked.** The web
  server's environment usually has no `HOME`, so a child process could look in a
  different place than the app had just verified. The browsers path is pinned for
  the renderer, which makes the diagnosis and the execution agree by construction.

### Added

- `occ eva_ai:browser` reports what the server has (Node.js, renderer script,
  browsers path, Chromium build, page timeout) and renders the URLs it is given,
  so "can this server read JavaScript pages" is answerable from the shell and
  usable as a deployment check; it exits non-zero when rendering is not usable.
- The admin test search states how many of its result pages needed the browser,
  which is the one thing a result list cannot show: a setting that is on and
  never used looks exactly like one that is working.
- [docs/BROWSER-RENDERING.md](docs/BROWSER-RENDERING.md): the install commands
  for Node.js, Playwright and Chromium, how to verify each step, and what each
  failure message means.

## [1.10.0] - 2026-09-12

### Added

- **Web search reads pages that need JavaScript.** A plain HTTP request reads the
  bytes a server sends; it cannot run the page's scripts, so for a growing share
  of the web it sees an empty shell - and an answer built from a shell is a guess
  presented as a quotation. With the new `web_search_browser` setting on, a page
  whose text did not arrive is loaded in a headless Chromium
  (`bin/render-page.mjs`) and read through the same extraction as any other page.
  Measured on real sites: `reddit.com/r/nextcloud/` returns no readable text at
  all statically and 20,177 characters through the browser, while a site that
  already delivers its article is never rendered at all. To stay inside a chat
  turn, only pages under 400 characters of static text are rendered, at most four
  per search, in a single browser process.
- The admin settings page reports whether the server can actually run it, and
  names the missing piece (Node.js, the Playwright package, or the executable
  path) instead of offering a switch that would quietly do nothing. `occ`
  config keys: `web_search_browser`, `web_search_browser_node`,
  `web_search_browser_timeout`.

### Security

- Browser rendering is held to the same URL policy as every other fetch, applied
  on the way in *and* to the address the browser finally settled on: a public URL
  that redirects to an internal address has its content discarded, and a page
  that cannot load is not a way to reach the local network. Content is only ever
  read after both checks pass.

## [1.9.1] - 2026-09-12

### Fixed

- **An answer source with nothing to open no longer looks like a link.** An
  indexed mail message or Talk room has no page in the file tree, so the source
  list fell back to `href="#"` - a link that appears clickable and only jumps to
  the top of the page. Such a source is now named as plain text, while files and
  web pages keep their link and the web badge.
- **Indexed mail and indexed Talk histories can no longer delete each other.**
  Both live in a synthetic negative file-id space, so a reconciliation pass that
  selected every negative id handed one producer's rows to the other; each pass
  now selects its own `source`. Related boundaries: an empty mailbox is no longer
  read as "every message was deleted" (which would wipe the mail index when the
  Mail app is merely unreachable), and a pass bounded by `talk_index_max_rooms`
  no longer drops rooms outside the bound.
- **A Talk transcript stops being quoted the moment the user leaves the room.**
  Room membership is re-checked at answer time, not only at index time, and an
  unverifiable membership fails closed and purges the stale transcript.
- **The Talk bot no longer goes silent on a decorated answer.** The yes/no
  classification now reads the word rather than the first characters, so
  `**Ja**`, `"yes"` and `1. Yes, ...` are answers instead of refusals, while a
  clear no still keeps the bot quiet.

## [1.9.0] - 2026-09-12

### Added

- **Image search.** Asking to see pictures ("zeig mir Bilder von X", "show me
  pictures of X", "what does X look like") now returns and embeds real pictures.
  A dedicated `search_images` tool finds them in a keyless image index, ranked so
  a title that mentions what was asked for wins over engine order, and the pages
  the pictures come from are added to the Sources list. A text search is the
  wrong instrument for a picture request, which is how the model used to end up
  telling users it cannot display images; it is now told explicitly that it can,
  and refusal is named as wrong.
- **A picture that cannot be loaded degrades to a link** with its caption instead
  of leaving a broken-image icon, because remote hosts block hotlinking and move
  files. Both chat surfaces install the fallback.
- **Nextcloud Talk chat histories can be indexed** and answered from. A new
  button in the settings and the documents view (
  `POST /ocs/v2.php/apps/eva_ai/api/talkIndex`) indexes the conversations the
  user is a member of, one document per chat, with its own switch
  (`talk_index_enabled`), room and message bounds
  (`talk_index_max_rooms`, `talk_index_max_messages`) and a Notes-app-style
  "only index chats now" action that runs regardless of the switch.
- **Answers in Talk use the indexed history.** When the bot is addressed in a
  room, it still sees the recent messages live, and now also the older passages
  of *that same room* that match the question - so "what did we decide about X
  last month?" can be answered. Rooms are checked against Talk's participant
  list at answer time, and the lookup is scoped to the room, so one conversation
  can never surface in another.
- **The Assistant uses the same history.** Its Talk context now verifies room
  membership instead of trusting the room ids it is handed, and adds the
  indexed older passages for the question on top of the recent window.
- Index entries carry a **source** (`files`, `mail`, `talk`). The mail and Talk
  indexes both live in the synthetic negative file-id space, so reconciliation
  now filters by source - otherwise a mail cleanup pass would have deleted the
  Talk rooms (and vice versa).

### Fixed

- The Assistant answered **without any file context** whenever the run-status key
  behind the RAG gate had been reset, even though the user's documents were
  indexed: the gate now counts the real documents.
- The Talk bot answered in hardcoded German regardless of the conversation's
  language, including its system prompt, slash-command replies, summary prompt
  and fallback errors. All of them are English now and the answer follows the
  language of the message; the regression guard covers this file too.
- The Talk bot's response pre-filter only knew German trigger words, so an
  English conversation that asked a question in plain words was classified as
  small talk. It now recognises both languages.

## [1.8.0] - 2026-09-12

### Added

- Answers now list the **web pages they were built from**. Until now a web
  answer only carried the URLs the model chose to write into its prose, so a
  user could not check a claim whose link the model omitted. Every page a
  `web_search` or `open_website` call really retrieved is attached to the
  answer and shown in the Sources list, labelled "Web" with the site it came
  from and an excerpt, after the cited files. Only successful, http(s) results
  count, and a page reached by several searches is listed once.
- Indexing reads **saved mail, web archives, mailboxes and notebooks**: `.eml`,
  `.mht`/`.mhtml` (MIME containers), `.mbox` and `.ipynb`. A mail yields its
  envelope (subject, sender, recipients, date, including MIME encoded-word
  subjects) plus the readable body; a multipart mail uses its plain-text part
  rather than indexing the same sentence twice as HTML, a mailbox contributes
  every message instead of only the first, and a notebook keeps markdown and
  code cells apart. Bodies in ISO-8859-1 or Windows-1252 are converted to UTF-8
  instead of failing the insert.
- Around 35 further text formats are recognised by extension - `patch`, `diff`,
  `json5`, `hjson`, `tf`/`hcl`, `proto`, `graphql`, `svelte`, `astro`, `dart`,
  `nix`, `rss`, `atom`, `kml`, `gpx` and more - so they are indexed instead of
  being skipped as binary.

### Changed

- The Assistant task providers send **English** instructions to the model. They
  used to send German prompts for summarise, reformulate, proofread, reformat,
  headline, topics, tone, context-write and translate, which pushed German
  phrasing into answers for every user regardless of their language. The
  "answer in the language of the input" rule is preserved, and the language
  picker keeps showing each language in its own script.
- User-visible messages from the code are English, so they can be translated by
  the catalog instead of appearing in German for an English user: task and chat
  errors, tool errors (mail, weather) and the Assistant's action-confirmation
  prompt. `tests/EnglishOnlyMessagesTest` guards this.

### Fixed

- A MIME boundary is no longer compared case-insensitively. Lowercasing the
  `Content-Type` header before reading the boundary made every multipart mail,
  web archive and newsletter index as an empty body with only its headers.
- A multipart message that declares no usable boundary, and a message nested
  deeper than five levels, are handled without recursion into the file.

## [Unreleased]

### Added

- Web search can search the **news** as well as the web. The `web_search` tool
  gains a `mode`: `web` (the configured provider), `news` (Bing News and Google
  News RSS, no API key) or `all` (both merged). News items carry their
  publication date and source name, and the feed language follows the asking
  user's own language. A news search works with every provider, because it reads
  the free feeds rather than the web index.
- A second no-key web provider: `bing`, which is read through Bing's RSS
  endpoint and returns real titles, direct result URLs and descriptions. Google
  offers no such endpoint — its result page is a JavaScript application that
  cannot be read server-side without the paid API — so `bing` is the honest
  no-key alternative, not a Google scraper that would silently return nothing.
- New `open_website` tool: the model can open one http(s) page and read its full
  text (up to 20000 characters) plus the passages matching its query, the page
  images and the publication date. This is what turns "a page mentions X" into
  an answer that actually quotes the source.
- Results are ranked with recency as a tie-breaker: a dated, current page
  outranks an undated one of equal relevance.

### Changed

- The assistant may now run several searches for one question: the tool loop
  allows eight rounds instead of four, and if the budget is spent entirely on
  tools the model is asked once more with the tools disabled, so a completed
  tool chain produces an answer instead of an empty reply. The system prompt
  tells it to refine the query, switch to news for current topics and read a
  promising page in full before giving up, and that the search results are
  newer than, and therefore outrank, its own memory.

### Fixed

- Images in answers are displayed. The chat renderer deliberately downgraded
  every Markdown image to a text link, so the figures a web search returns never
  appeared. They now render as pictures, with `referrerpolicy="no-referrer"` (the
  image host learns nothing about the instance) and `loading="lazy"`, wrapped in
  a link to the full-size original; `data:`, `javascript:` and every other unsafe
  scheme still fall back to text.

- A news search answers with current coverage. Age used to count only -1 for
  anything older than three years, which a single matching word (+1 to +3)
  outweighed, so `mode: news` returned 2016 and 2018 pages above this year's
  articles. Currency is now weighted by mode: a plain web search still treats
  age as a tie-breaker (the 2019 article that explains a topic can still win),
  while a news search ranks a three-day-old item far above an old one and
  demotes an undated item, because an undated news item cannot be shown to be
  current. A future date is treated as a wrong date, not as freshness.
- Web search now picks the best results instead of the first ones. Every
  provider is asked for more hits than are returned, the candidates are read,
  and a second ranking pass scores them on what their pages actually say
  (term coverage in the body, exact phrase matches) before the best are kept.
  A hit that could not be read is demoted below one that was, and repeated
  domains are demoted so five hits from one site cannot crowd out other
  sources. `web_search_candidates` (3–20, default 12) sets the field size.
- Web search results carry `highlights`: the passages of the page that mention
  the query, so a long page's answer survives the per-page text limit instead
  of being cut off after the introduction.
- Web search can return images from the result pages (the page's own preview
  image plus pictures inside the article, up to three per result) and the
  assistant can embed them in its answer. The images come from the pages that
  are already being read, so they cost no extra request. Icons, logos,
  spacers, tracking pixels and any non-http(s) URL are filtered out.
  Controlled by `web_search_images` (default on).
- The admin page configures how often the background indexer runs
  (`index_job_interval_minutes`, 1–60, default 5 instead of 15).
- The admin page shows how many files the last indexing pass had to skip, both
  per account and in total.

### Security

- `open_website` and every other server-side page fetch can no longer be pointed
  at the local machine or the internal network. The new tool lets the *model*
  choose a URL, which made URL validation a security boundary rather than a
  formatting rule: `http://127.0.0.1`, the cloud metadata service
  (`169.254.169.254`), `192.168.x.x`/`10.x.x.x`, `[::1]`, `localhost` and
  `.local`/`.internal` names are now refused before any request is made, a host
  is refused when **any** of its addresses is private, and a name that does not
  resolve fails closed. Redirects are followed by hand instead of by cURL
  (`CURLOPT_FOLLOWLOCATION` off), so every hop is validated too — otherwise a
  public URL could simply redirect to `127.0.0.1` and bypass the check.
  URLs that are only displayed (search hits, result-page images the browser
  loads) are deliberately exempt: resolving them server-side would drop images
  whenever an unrelated lookup failed.

### Changed

- A single unreadable or unembeddable file can no longer end an indexing pass.
  The batch is halved until the offending input stands alone, that one document
  is skipped (its previous index entry stays searchable) and the rest of the
  batch is indexed as usual. A failing extraction is counted as skipped rather
  than as a run error, so the background index no longer stops on one bad file.
- A model server that is genuinely unreachable still reports an error instead
  of silently skipping every document, and a malformed batch response still
  falls back to the per-text endpoint.
- Indexing a large library issues far fewer database round trips: the per-file
  index state is read in one query instead of one query per file, and a file
  whose metadata is unchanged is no longer rewritten on every pass.

## [1.5.0] - 2026-09-12

### Added

- The admin page now configures indexing throughput: how many index passes run
  in parallel (`index_max_concurrent`, 1–16) and how many seconds one cron run
  may spend indexing (`index_job_max_seconds`, 10–600). Both keys were
  previously only settable through `occ config:app:set`.
- Per-account indexing can be switched on or off directly in the admin account
  table, and the account table now shows the indexing state, document and chunk
  counts and the last error at a glance.

- Optional per-user web search grounding: DuckDuckGo (free, no API key),
  self-hosted SearxNG, Brave or Tavily. It is off by default and the model only
  calls it when the indexed files cannot answer a question (#187).
- Web search now answers from the sources themselves: all search endpoints are
  merged, the hits are re-ranked by how well they match the query, and the top
  pages are fetched in parallel so their readable text reaches the model as
  `content` instead of a search-engine teaser. Sponsored/internal links and
  navigation, script and footer markup are stripped. Up to 20 results and up to
  8000 characters per page can be configured (`web_search_fetch_content`,
  `web_search_content_chars`).
- Optional Groq chat with encrypted per-user API keys, GPT-OSS 20B/120B selection,
  streaming and tool calls, explicit quota errors and no automatic model fallback.
- Optional bounded Tesseract OCR for images and scanned PDFs (#84).
- Chunk provenance for headings, pages, slides and sheets; migration in 1.4.8 (#147).
- The chat header now shows the real conversation title (restored from storage or
  auto-derived from the first question) instead of a static placeholder, and
  untitled chats display a translated "New chat" label instead of a hardcoded
  German default that leaked into the English UI.
- User messages get a copy button in the web and standalone chat, matching the
  assistant answers.
- `occ eva_ai:repair-chats <user>` inspects a corrupt chat store and - after
  explicit `--yes` - backs the damaged file up and reconstructs a minimal valid
  store that keeps every parseable chat (#184).
- A pending tool confirmation in the web and standalone chat is persisted with
  the assistant message, so a reload rebuilds the inline panel instead of
  leaving a dangling question; approving after a reload replaces the placeholder
  (no duplicate answer) and a claim token prevents the same action from running
  twice (#185).
- Ownership markers are written before the filesystem action they record and
  finalized with the resulting node afterwards, so an interruption between the
  two leaves a pending record that the next ownership check reconciles into a
  grant instead of a silently missing one (#183).
- Browser tests exercise the standalone chat end-to-end against a mocked API:
  streaming render + ordered persistence, the confirmation panel with its
  idempotency token, duplicate-approve rejection after a reload, and the stop
  button (abort + partial-answer persistence) (#133).
- The RAG quality baseline is documented with current German/English numbers,
  methodology and CI thresholds in `docs/RAG-QUALITY-BASELINE.md` (#146).
- Controlled-backend tests cover the Ollama streaming client: malformed lines,
  tool-call arguments split across chunks, UTF-8 split at read boundaries and
  connection errors, all deterministic without a live server (#134).
- Faster incremental indexing: every document now stores the indexed file's
  modification time, and full passes skip files whose mtime+size fingerprint is
  unchanged without re-reading or re-parsing them - renames and touches still
  refresh the stored metadata, and the content hash stays authoritative for
  changed files (migration in 1.4.9).
- Filename/path-aware retrieval: a query that names a file (e.g. "Budget
  2026.xlsx") now matches lexically via the stored name/path even when the
  chunk text never repeats the name, while literal body matches always
  outrank the name bonus.
- Deeper extraction: PDF text keeps the physical layout (`-layout`) so tables
  and columns stay structured; HTML/EPUB titles and `h1`-`h6` headings become
  markdown section anchors that the chunker carries into every chunk.

### Changed

- The admin settings page was rebuilt with native Nextcloud elements only
  (section, grid tables, native inputs and buttons) and no inline styles, so it
  follows the active theme including dark mode. Feedback is shown inline and as
  a native notification.
- Background indexing makes real progress on large libraries: the periodic job
  now drives bounded passes for each selected account until its fair share of
  the run budget is spent, instead of stopping after a single pass. On a settled
  library an unchanged file is also skipped without fetching its file node, so
  the per-file cost of a full scan drops to the metadata check.
- Liveness heartbeats are throttled to a short interval instead of being written
  once per indexed file, removing a database write and a global scheduler lock
  per file.
- The home/start page no longer shows the quick prompt input field: it belongs
  to the chat view, while the overview keeps its action buttons and stats.
- Streaming no longer yanks the scroll position: while an answer streams, the
  chat only follows the bottom when the user is already near it, so reading
  earlier context is not interrupted (web and standalone chat).
- Regenerate/edit no longer truncates the stored history before the model
  responds: the truncation and the optional user-text edit are committed
  atomically with the persisted answer, so a failed model call or a reload
  during regeneration never destroys the previous answer (#182). Regenerate
  requests validate against the chat's revision, so two tabs editing the same
  chat cannot silently overwrite each other - the stale tab gets an inline
  "modified in another tab" notice instead.

### Fixed

- The admin settings save endpoint did not exist as a route, so every "Save"
  button on the admin page hit a 404 and silently changed nothing. The settings
  routes are registered again.
- Save feedback on the admin page no longer reports success when the request
  failed: HTTP errors and validation errors are surfaced with the concrete reason.
- DuckDuckGo web search returned no results: requests were sent without the
  headers and gzip support a browser sends, so DuckDuckGo answered with its
  anti-bot interstitial instead of results. The result parser also assumed a
  single attribute order. Both are fixed, sponsored and internal DuckDuckGo
  links are filtered out of the results, and an empty result set now reports
  the concrete reason instead of silently claiming success (#187).
- Chat storage failures now distinguish a corrupt store (actionable message
  pointing to `occ eva_ai:repair-chats`) from generic persistence errors (#184).
- Use a shared Markdown parser for tables, nested lists/emphasis, safe links and
  streamed code fences, with matching chat styles and desktop/mobile browser checks.
- Run Groq contracts against real Nextcloud interfaces in the PHP 8.2–8.4 CI matrix.
- The app page no longer fails with an intermittent HTTP 500 when the chat store
  is briefly locked: page-load reads now take a shared lock, retry on contention
  and degrade to a lock-free read of the intact file, while mutations still
  require the exclusive lock and report a friendly 503 "busy" instead of a 500.
- The App Store listing renders as text again: the `info.xml` description is
  flush-left, because CommonMark turned its four-space indentation into a code
  block.
- App version bumped to 1.4.9 so the new mtime migration actually runs on
  update; `tests/ReleaseMetadataTest` now fails when a migration targets a
  version above `info.xml`, and `docs/VERSIONING.md` documents the rules.

- Bound Groq request history, avoid background greeting/follow-up API calls, and
  report actual token limits/retry timing instead of a generic Free Plan error.

- Atomically claim confirmed actions before execution and retain durable receipts
  so concurrent confirmations cannot execute the same proposal twice (#179).


### Fixed (September 8 review)

- Correct regenerate/edit streaming responses and reject invalid message targets before changing history (#170).
- Bound citation ranges and preserve first-mention order (#171); fix fenced-code boundaries and literal inline code/URLs (#172).
- Preserve corrupt chat/folder storage instead of overwriting it, and serialize getChat reads (#173).
- Keep archived/customized/scoped empty chats separate from New chat (#174).
- Share a UTF-8-safe NDJSON reader between chat frontends, including final unterminated events and explicit parse errors (#175).
- Serialize ownership-marker reads and updates with Nextcloud's shared locking provider (#176).
- Use the longstanding callback response API for streaming on supported Nextcloud versions (#177).
- Align frontend metadata with app version 1.4.7 and derive webpack's version from package metadata (#178).
- Add frontend behavioral tests to npm test and CI. See docs/REVIEW-2026-09-08.md for validation and remaining limitations, including native confirmation concurrency (#179).

### Added (1.4.7)

- **Provider capability layer (Issues #86/#148/#151):** EVA now reads each
  installed Ollama model's declared capabilities from `/api/tags` instead of
  classifying models by their names alone. Older Ollama servers without the
  capabilities field fall back to a conservative name/family heuristic that
  is explicitly marked as such.
- **Model fallback chains (Issue #86):** new per-user settings
  `chat_model_fallback` and `embedding_model_fallback` (comma-separated).
  When the primary model is not installed, EVA resolves the first installed
  candidate with the matching capability; if no candidate is usable it fails
  with a clear error that lists what is actually installed. A dedicated
  optional `summary_model` lets heavy text tasks (summarize, translate,
  proofread, …) use a separate model while chat keeps the lightweight one.
- **Versioned provider snapshot (Issue #151):** the status endpoint now
  reports `provider` with version, online state, snapshot age, request
  latency, capability availability, per-model roles and the resolved model
  including whether a fallback was used - all metadata, never content.
- **Capability-aware settings (Issue #148):** the model picker separates
  embedding and chat models by provider capability, shows installed status
  next to the configured model, explains fallback usage, and rejects a
  role-mismatched model selection before it is saved.
- Index config hashes include the embedding fallback chain so a changed
  fallback re-indexes automatically.

### Changed
- **Issue #143:** retrieval ranking is now deterministic for identical inputs and bounds repeated chunks per document (diversity cap), so one document cannot crowd out all other relevant evidence while a single-document query still fills the full window.
- **Issue #77:** the Talk bot no longer sends every room message to the LLM for classification. A deterministic heuristic pre-filter (bot name/trigger, question mark, assistant-directed phrasing) decides first; only plausibly-addressed messages reach the classifier. New `talk_classify_all` setting (default `0`) restores the legacy classify-everything behaviour.
- **Issue #112:** the periodic `IndexJob` now has a bounded wall-clock budget (`index_job_max_seconds`, default 50) and continues round-robin from the last processed user (`index_job_last_user`), so one slow user can no longer starve every other user's index pass.
- **Issue #118:** `docs/CONFIGURATION.md` is now a complete configuration reference (every AppConfig key with scope, default, limits, unit and `occ` examples) guarded by a documentation-sync unit test.
- **Issue #150:** mutating/destructive tool calls now write a per-user, privacy-conscious action history (time, surface, tool, sanitized parameters, outcome) to AppData; passwords, tokens, message bodies and other sensitive values are redacted. Users can inspect and clear their history in Settings.
- **Issue #83:** GDPR support: a **Download my data** action in Settings exports chats, personal knowledge and index metadata as JSON, and a `UserDeletedEvent` listener removes every eva_ai row, AppData folder, KNOWLEDGE.md and per-user config when an account is deleted.
- **Issue #91/#140:** inspecting a document's chunks is now bounded and paginated (`limit`/`offset` on `documentChunks`); the Documents view loads large documents in pages with a "Load more chunks" button instead of one huge response.
- **Issue #69:** the weather tool (external Open-Meteo API calls) can be disabled via a new **Allow weather forecasts** setting in Settings, keeping all tool traffic on your own server.
- **Web chat:** complete, explicit tool calls (shares, calendar events, tasks, files, contacts, profile, knowledge) now execute directly without a confirmation dialog; the dialog is only shown when required data is missing or ambiguous. Missing fields are highlighted right away, the prompt instructs the model to never invent missing values, and the dialog copy now explains what is missing. Newly created shares still show the copyable-link chip after direct execution.
- **Issues #60/#66:** the indexer extracts the complete content of office documents instead of partial sections. DOCX now includes headers, footers, footnotes, endnotes, comments and tables; XLSX covers shared and inline strings sheet by sheet with cell references and sheet names; PPTX adds speaker notes and numbers each slide; ODS sheet names are preserved as boundary markers. Legacy binary `.doc`/`.xls`/`.ppt` files are converted via LibreOffice headless when installed (otherwise skipped with a logged reason, never silent).

### Added
- Confirmation requests in the web chat now render a native, editable form for every tool that requires confirmation (shares, calendar events, tasks, files, notes, contacts, profile and knowledge) instead of raw JSON; destructive actions get a red warning style, unknown tools still fall back to readable JSON, and successful share creations show a copyable link chip.
- **Issue #145:** user-isolated, content-addressed embedding cache with 30-day bounded retention, model/endpoint/schema metadata validation, duplicate-miss coalescing, reset cleanup, and index-status hit/miss/request counters.
- **Issue #109:** English and German translation bundles for the Vue workspace, file actions and standalone chat.
- Opt-in single-process frontend build for memory-constrained hosts (`EVA_LOW_MEMORY_BUILD=1`).
- **Issue #99:** calendar event listing and free-slot detection now expand recurring events with bounded support for RRULE, RDATE, EXDATE, and RECURRENCE-ID.

### Changed
- **Issue #63:** file-context chat now budgets the document excerpts against the configured model context instead of truncating every document at a fixed 12000 characters. A single large document may use most of the window; several documents share the budget fairly (unused share of short documents is redistributed).
- **Issue #96:** chat conversations keep up to 1000 messages (was 200) and trimming is no longer silent: every dropped message is counted on the chat and the chat view shows a notice that the oldest messages were trimmed.
- **Issue #152:** the chat list search now also finds chats by their message content, not only titles. Content hits show an excerpt around the first match and the number of matching messages.

### Fixed
- Indexing can no longer be permanently blocked by the per-user index lock. Two related defects are fixed: the lock key is now bounded to 40 hex chars (the full sha256 exceeded the varchar(64) key column of Nextcloud's file_locks table, so acquire/release silently failed and stale rows collided with every later attempt), and when a worker still crashes while holding the lock, the queue endpoint and the indexer reclaim the expired row once the tracked run state is idle or stale - never while a live worker with a fresh heartbeat is running. Previously such a row blocked "Indexing could not be queued" forever on instances whose cron never ran the file-lock cleanup job.
- **Issue #62:** the chunker no longer runs `array_unique()` on its output, so genuinely repeated passages and overlap-induced duplicate chunks stay in the index; `chunk_index` stays sequential and the stored `chunk_count` always matches the number of written chunks.
- **Issue #73:** a personal setting now falls back to the admin-configured instance value (`occ config:app:set eva_ai …`) when the user has no explicit personal value; explicit choices still win, and per-user runtime state never inherits instance values.
- **Issue #92:** non-streaming chat calls are bounded (default 120 s total with an idle read timeout) instead of holding a PHP-FPM/TaskProcessing worker for up to 600 s; TaskProcessing generation providers now stream internally and report progress while generating, and timeouts surface as clear errors.
- **Issue #61:** once an index exceeds the candidate pool, the dense (semantic) candidate set is built by scanning the entire index in fixed pages and keeping the best cosine scores - no more query-derived random window that silently skipped semantic-only matches.
- **Issue #74:** the Documents summary (`totalChunks`, `totalSize`, `total`) is computed from full-index SQL aggregates with the same search filter, so paging with "Load more" no longer changes the totals.
- **Issue #78:** chat mutations are serialized through Nextcloud's shared locking provider (oc_lock) instead of a node-local `flock()` file, so concurrent writes cannot race on clustered multi-node deployments.
- **Issue #113:** the activity tool only queries when the Activity app is enabled for the user, uses a defensive stable read instead of a hard-coded column list, no longer hides the app's own entries, and formats timestamps in the requesting user's timezone.
- **Issues #115, #97 and #76:** validate and normalize `exec_write_types` before saving, log and propagate Mail database failures instead of treating them as empty mailboxes, and reuse one short-lived Ollama `/api/tags` status result for repeated UI polls.
- **Issue #104:** calendar event listing and free-slot searches now use CalDAV time-range queries instead of loading every object from every selected calendar.
- **Issue #132:** bind EVA-created file grants to file IDs; reject legacy path grants, prune stale IDs, preserve grants across renames, and do not claim existing files or folders.
- **Issue #72:** move personal knowledge out of privileged system prompts and mark retrieved context as untrusted data.
- Persist native confirmation results before optional model summaries; failed summaries retain truthful results without leaving completed actions pending.
- Native Assistant proposal tools and confirmed execution use separate policy surfaces; plain Assistant chat remains read-only.
- Enforce per-document context limits with interleaved chunks, bound mail searches and escape literal search wildcards, and initialize the authenticated user for model lookup.
- Generate file-action links through the Nextcloud router for subdirectory installations and load translations in the Files entry point.
- Align the build environment with the Node.js 24 requirement of `@nextcloud/files`.
- Restore standalone empty-chat state and connect its Markdown export button.
- Remove obsolete reflection calls from tests for PHP 8.5 compatibility.
- **Issue #71**: `RagService` now receives a `LoggerInterface` through
  constructor injection, so the access-revocation purge path no longer
  throws an undefined-property error that was silently swallowed by the
  surrounding `try`/`catch`; the audit log entry is now actually written.
- **Issues #108, #114 and #117**: Ollama URLs reject credentials/query/fragment components individually, connection checks stop after an unreachable server or missing model instead of waiting through multi-minute model timeouts, and chat pairs are persisted strictly as question then answer.
- **Issues #95, #100 and #103**: Talk blocks sensitive profile/share/server-status tools, existing share listings redact link capability secrets and URLs, and TaskProcessing only injects explicitly supplied Talk rooms with caps of three rooms and 20 messages per room.
- **Issues #102, #105 and #98**: the `chatwithtools` TaskProcessing provider now ignores caller-supplied tool definitions and keeps the application-owned policy prompt, abandoned agent state is pruned after 30 days, and a tool-only streaming round completes with a truthful summary instead of a false no-text error.
- **Issues #67 and #68**: mutating and destructive model tool calls now stop at a central confirmation gate; the web chat displays the exact pending call with Confirm/Cancel controls and executes it only through the authenticated confirmation endpoint. Nextcloud Talk is read-only, so room members cannot create, modify or delete files, shares, contacts, calendar events or tasks.
- **Issues #65 and #94:** stopping an index run now leaves cancellation and terminal state ownership with the worker, so the UI shows `stopping` instead of a false completion; embedding requests have bounded read timeouts, staged replacement rows are discarded when cancellation arrives, and a restart requested during stopping is queued behind the old worker.
- **Issue #70:** `search_files` now searches bounded readable text content as well as names, returns short content snippets, reports when traversal/result limits were reached, and skips binary or oversized files without aborting the search.
- **Issue #93:** Knowledge-base trimming now removes only the oldest non-profile lines, preserves the automatic first-run identity section, logs the trim, and reports the warning in the tool result.
- **Issues #101 and #106:** calendar event timestamps returned by `list_calendar_events` are now converted to UTC before the `Z` suffix is emitted, and share updates/deletes resolve the provider-aware share ID directly instead of scanning only the first 500 shares of each type.
- **Issue #110:** large queued indexing jobs now enqueue a continuation after the bounded pass budget instead of stopping with a misleading safety-limit error.
- **Issue #111:** legacy app-ID migration now copies each user's settings during the user-manager callback instead of collecting every UID in memory first.
- **Issue #116:** legacy `ragchat_*` tables are now explicitly removed when their current `eva_ai_*` counterparts already exist after a partial upgrade.
- **Indexing API**: use native Nextcloud request parameters with a single non-recursive JSON fallback instead of a recursively named input wrapper; duplicate starts now remain idempotent while genuine worker-lock conflicts still return an actionable 409.
- **Frontend**: the main Vue bundle is now emitted as `eva_ai-main.js` (was
  `eva-ai-main.js`), matching the name the page controller looks up. The app
  page no longer renders as an empty shell after the app-ID migration.
- **Issue #16**: calendar/task defaults now use the user's timezone and the
  weekday calculation is correct on Sundays (was off by one for `date('w')`).
- **Issue #15**: deleted emails are removed from the RAG index during
  reconciliation instead of lingering in retrieval results.
- **Issue #14**: every indexed document is re-checked for read access before
  its chunks are returned to the model; stale documents are purged.
- **Issue #13**: dense (semantic) candidates are no longer gated behind the
  keyword `LIKE` filter, preserving retrieval quality on large indexes.
- **Issue #11**: writes to shared calendars and address books are rejected
  unless the user holds an explicit write grant (DAV permission checks).
- **Issue #10**: file tools work again in `occ taskprocessing:worker` (CLI) by
  setting up the user filesystem before resolving the home folder.
- **Issue #8**: AppData namespaces for chats and AI marks are derived from a
  SHA-256 hash of the user ID, eliminating cross-user collisions.
- **Issue #7**: background indexing runs independently per user instead of
  being blocked by other users' partial indexes.
- **Issue #6**: an incomplete (bounded) index scan can no longer delete valid
  documents that were simply not visited yet.
- **Issue #5**: global configuration and index management endpoints are
  restricted to administrators.

### Changed
- **Responsive workspace and providers**: the New chat action now uses a block-level full width with Nextcloud's native wide modifier, exactly matching the Documents/Settings navigation-item width, shared chat content expands on large screens, notification entries use the EVA app icon, and Assistant provider labels are standardized as `Eva · Local`, `Eva · RAG`, `Eva · Tools`, and `Eva · Agent`.
- **Frontend navigation**: the native chat search now has the primary **New chat** action directly below it, and per-chat rename/delete actions use Nextcloud's native icon wrapper for stable alignment.
- **Issue #2**: the app ID is migrated from `eva-ai` to `eva_ai` (info.xml,
  namespaces references, routes, AppData folder, DB rows, Talk bot URL, JS
  bundles and docs). Existing installs keep their data; the Talk bot was
  re-registered under the new URL.

### Reliability and bug fixes
- Index cancellation is now bounded at the Ollama embedding boundary and does not publish staged chunks after a stop request; the worker alone records the real finish time and releases the run claim.
- **Issue #49:** background indexing now persists per-user enrollment, including empty or reset indexes, with an explicit Settings opt-out.
- **Issue #57:** abandoned streaming requests now stop when the client disconnects and close the Ollama response stream without starting another tool round.
- **Issue #58:** frontend API failures now preserve HTTP status and bounded server messages instead of becoming silent null responses.
- File-context chat now includes the current user's personal `KNOWLEDGE.md` as personal context while keeping selected-file excerpts as the only document evidence.

### Added
- On first use, EVA creates an editable, per-user `KNOWLEDGE.md` profile section from the Nextcloud user ID, display name and optional email without overwriting existing knowledge or importing sensitive profile fields.
- **Issue #3**: automated test asserting every TaskProcessing provider ID is
  unique and prefixed with the app ID.

- **Security**: external HTTP calls (Open-Meteo weather) now enforce TLS
  certificate verification (`CURLOPT_SSL_VERIFYPEER`/`SSL_VERIFYHOST` were
  disabled, enabling man-in-the-middle attacks). Fixes issue #9.
- `TalkSetup` command and `TalkBotRegistrar` no longer hard-depend on the
  optional Talk app: `occ` works again even when Talk (spreed) is not enabled.
  The `BotServerMapper` is resolved lazily at runtime after checking that Talk
  is active.
- Tool policy surfaces: read-only tools are now correctly allowed on the RAG
  surface; the `AgentInteractionProvider` switches to the `web` surface after
  user confirmation so mutating tools keep working in the confirmation flow.
- `AgentInteractionProvider::injectRagContext()` used an undefined `$this->config`
  property instead of the injected `AppConfig` — fixed.
- `indexEmails()` was missing the `oldDocId` key in a batch array, which could
  crash on "Undefined array key" — fixed.
- Removed a stray NUL byte in `Indexer.php` that made tooling treat the file as
  binary.
- `info.xml` metadata cleanup (author, bug tracker URL) and `package.json`
  version sync.

### Added
- Central tool permission policy (`lib/Service/ToolPolicy.php`) with risk
  classification (readonly/mutating/destructive), surface isolation
  (web/talk/rag/taskprocessing) and enforcement in `ActionExecutor::run()`.
- Automated test suite: `tests/ToolPolicySecurityTest.php`,
  `tests/TaskProcessingContractTest.php`, `composer.json` (PHPUnit), bootstrap
  and `phpunit.xml.dist`.
- GitHub Actions CI (`tests.yml`) running PHPUnit on PHP 8.2/8.3/8.4, PHP
  syntax checks, frontend builds, generated-bundle emission checks and migration/provider
  regression tests; composer install retries on transient SSL failures.
- Privacy documentation (data lifecycle, retention, reset commands) in the
  README and `docs/PRIVACY.md`.
- `Indexer` now streams file discovery via a generator instead of materializing
  the whole tree in memory.
- Docs: `docs/SECURITY.md`, `docs/CONFIGURATION.md`, `docs/ARCHITECTURE.md`,
  `docs/DEVELOPMENT.md`.

### Housekeeping
- Pending regression contracts for open issues #99–#106
  (`tests/OpenIssuesPendingContractTest.php`): each test documents the fix
  contract and is skipped until the issue is implemented, so CI stays green.
- New CI workflows: `quality.yml` (composer/npm dependency security audits on
  push and pull requests) and `nightly.yml` (nightly `occ app:check-code` and
  the test suite against every supported Nextcloud release line and `latest`).
- New documentation: `docs/FAQ.md` (setup, indexing, chat, Talk, Assistant and
  privacy troubleshooting).
- Issue and pull-request templates (`.github/ISSUE_TEMPLATE/*`,
  `.github/PULL_REQUEST_TEMPLATE.md`) matching the repository issue format.
- Backlog grooming: related open issues are cross-linked and small, isolated
  fixes are labelled `good first issue`.

## [1.4.0]

### Fixed
- Repair migration for legacy hyphenated index names (`eva-ai_*` → `eva_ai_*`)
  so the schema works on MySQL/MariaDB and PostgreSQL; obsolete `ragchat_*`
  indexes are dropped idempotently.
- Indexing reconciliation now removes stale entries for deleted or unreadable
  files and re-chunks modified files based on content hash.

### Added
- Nextcloud 35 compatibility (`max-version` 35).
- Email indexing toggle (`mail_index_enabled`) with per-pass limit
  (`mail_index_max`).
- TaskProcessing providers for the Assistant app, including an agent with a
  confirmation flow for mutating tools.
- File-context chat from the Files app ("Open with EVA" / multi-select).

> **Note:** Entries for versions before 1.4.0 are reconstructed from the current
> codebase and migration history and may not exactly match the original release
> notes.

## [1.3.0]

### Added
- Talk bot integration (automatic registration, `eva_ai:talk:setup` command).
- Shares tool (list/create/update/delete), tasks tool (VTODO), profile tool.
- Notifications "AI answer ready" (Notifier).

## [1.2.0]

### Added
- Hybrid retrieval (vector + BM25, RRF fusion).
- Weather, server status, activity feed and current-time tools.
- Free-slot search for calendars.

## [1.1.0]

### Added
- Calendar tool (create/update/delete events, reminders, German/ISO/relative time
  formats).
- Contacts tool (own, shared, group and Circles address books).
- Mail app integration (search/list/read/unread) and initial email indexing.

## [1.0.0]

### Added
- Initial release: RAG indexing of files, hybrid search, chat with source
  citations, file tools (list/create/rename/delete/read/search/notes), knowledge
  base (`KNOWLEDGE.md`).
