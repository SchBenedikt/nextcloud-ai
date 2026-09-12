# EVA — Configuration reference

All settings are stored in `oc_appconfig` under the app ID `eva_ai`. Personal
settings can be overridden per user (from the app's **Settings** tab), but when
a user has no personal value they fall back to the admin-configured instance
value, and only if that is empty to the built-in default (Issue #73).

```bash
# Read one value
sudo -u www-data php occ config:app:get eva_ai <key>

# Set an instance-wide value (used by every user who did not choose their own)
sudo -u www-data php occ config:app:set eva_ai <key> --value=<value>

# Reset to the built-in default / clear a personal override:
# instance-wide: delete the app value
sudo -u www-data php occ config:app:delete eva_ai <key>
# per-user override: remove only that user's personal value
sudo -u www-data php occ config:user:delete <user> eva_ai <key>
```

Values are plain strings; booleans use `1`/`0`; sizes are stored in **bytes**
(the settings UI shows MB). Invalid values are rejected by the Settings form;
`occ` does not validate, so keep values inside the documented limits.

## Key reference

Legend: **P** = personal setting (per-user override possible),
**I** = instance-wide only, **S** = internal per-user runtime state,
**G** = global scheduler state. Keys marked **Admin only** are readable and
writable exclusively through the admin settings endpoint; the user settings
endpoint rejects them.

### Connection & models

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `chat_provider` | P | `ollama` | `ollama`, `groq` | – | Chat provider; embeddings remain on Ollama. |
| `groq_model` | P | `openai/gpt-oss-20b` | `openai/gpt-oss-20b`, `openai/gpt-oss-120b` | – | Groq Free Plan model selection; no fallback. |
| `ollama_url` | P | `http://127.0.0.1:11434` | plain `http(s)://host[:port]`, no path/credentials | – | Base URL of the Ollama HTTP API; trailing slashes are stripped. |
| `chat_model` | P | `gemma4:cloud` | non-empty string | – | Model used for chat/generation. |
| `chat_model_fallback` | P | `''` | comma-separated model names | – | Models tried in order when the primary chat model is unavailable (Issue #86). |
| `embedding_model` | P | `nomic-embed-text` | non-empty string | – | Model used to embed chunks and queries. |
| `embedding_model_fallback` | P | `''` | comma-separated model names | – | Models tried in order when the primary embedding model is unavailable (Issue #86). |
| `summary_model` | P | `''` | model name or empty | – | Optional dedicated model for heavy text tasks (summarize, translate, proofread, …). Empty = the chat model chain is used (Issue #86). |
| `temperature` | P | `0.1` | `0`–`2` | – | Sampling temperature passed to Ollama (`options.temperature`). Lower = more deterministic. |
| `context_size` | P | `12288` | `256`–`131072` | tokens | Context window passed to Ollama (`options.num_ctx`). |
| `ollama_keep_alive` | P | `5m` | `-1`, seconds, or `500ms`/`10m`/`1h` | – | How long Ollama keeps a model resident after a request (`keep_alive` on every chat/embed call). Higher values avoid model reload latency between messages; `-1` never unloads. |
| `followups_mode` | P | `fast` | `fast`, `llm` | – | `fast` renders follow-up chips from language-aware templates without an extra model call after each answer; `llm` generates them with a small additional request. |

### Retrieval & indexing

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `top_k` | P | `6` | `1`–`8` | chunks | Number of RAG hits fed to the model per retrieval. |
| `chunk_size` | P | `900` | `128`–`10000` | characters | Target size of a text chunk. |
| `chunk_overlap` | P | `120` | `0`–`5000` | characters | Overlap between consecutive chunks. |
| `max_file_size` | P | `20971520` | `1048576`–`2147483648` | bytes | Files larger than this are skipped during indexing. |
| `max_files_per_run` | P | `40` | `1`–`10000` | files | Files processed per indexing pass (bounds job duration). |
| `embed_batch_size` | P | `24` | `1`–`200` | chunks | Chunks embedded per batch (bounds peak memory independent of library size). |
| `scope_path` | P | `''` | path, no `..` | – | Only index files below this path (e.g. `/Documents`). Empty = entire home. |
| `exclude_paths` | P | `''` | comma-separated paths, no `..` | – | Path prefixes to skip (e.g. `/.trash,/Photos`). |
| `index_user` | I | `''` | user id | – | Legacy instance-wide background-job user; not changeable from Settings. |
| `index_enabled` | I | `0` | `1`/`0` | – | Legacy instance-wide indexer switch. |
| `mail_index_enabled` | P | `1` | `1`/`0` | – | Index emails into RAG. |
| `mail_index_max` | P | `25` | `1`–`500` | emails/pass | Emails indexed per pass. |
| `talk_index_enabled` | P | `0` | `1`/`0` | – | Index the user's Nextcloud Talk chat histories, so older parts of a conversation can be quoted in an answer. Off by default because a chat log is the most personal content in an instance. Use the "Only index Nextcloud Talk chats" button (`POST /ocs/v2.php/apps/eva_ai/api/talkIndex`) or `occ eva_ai:talk <user> --index` to index them once regardless of this switch. The whole path is described in [TALK-INDEXING.md](TALK-INDEXING.md). |
| `talk_index_max_rooms` | P | `20` | `1`–`200` | chats/pass | Talk chats indexed per pass. |
| `talk_index_max_messages` | P | `200` | `10`–`1000` | messages | How far back into each chat to index. |

Embedding vectors are cached in Nextcloud's distributed cache for up to 30 days
(user-isolated, content-derived keys; document text is never stored in the key).
Resetting a user's index clears that user's cached vectors.

### Chat tools / actions

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `actions_enabled` | P | `1` | `1`/`0` | – | `1` = chat tools enabled; `0` = read-only chat. |
| `exec_write_types` | P | `''` (all) | `*`, empty, or ≤32 extensions `md,txt,…` | – | Allowed extensions for AI-created files. |
| `exec_write_max_chars` | P | `100000` | `1`–`10000000` | characters | Maximum size of AI-created file contents. |
| `exec_delete_mode` | P | `own` | `off`/`own`/`all` | – | `own` = delete only EVA-created files; `all` = also user files (with confirmation); `off` = deletion disabled. |

### Notifications, Talk & privacy

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `notify_on_complete` | P | `1` | `1`/`0` | – | Send an "AI answer ready" notification (Notifications app). |
| `proactive_enabled` | P | `0` | `1`/`0` | – | Opt in to scheduled, read-only EVA briefings. |
| `proactive_schedules` | P | `[]` | JSON | max. 20 | Briefings with `id`, `prompt`, local `HH:MM` time and ISO weekdays (`1` = Monday … `7` = Sunday). |
| `talk_history_size` | P | `50` | `1`–`500` | messages | Number of previous Talk messages sent as bot context. |
| `talk_write_enabled` | P | `0` | `1`/`0` | – | Allow EVA to post messages into a Nextcloud Talk chat as the signed-in user (`send_talk_message`). Off by default: a message written in the user's name is an act, not a lookup, so each user opts in themselves. Reading a chat (`read_talk_chat`) stays available either way, and only rooms the user is a member of can be resolved. See [TALK-INDEXING.md](TALK-INDEXING.md). |
| `talk_bot_trigger` | P | `Eva` | non-empty string | – | Trigger word (with `@`) the Talk bot reacts to. |
| `talk_classify_all` | P | `0` | `1`/`0` | – | `0` = heuristic pre-filter decides before any LLM call (Issue #77, default); `1` = classify every room message via the LLM (legacy, higher cost/privacy exposure). |
| `weather_tool_enabled` | I | `1` | `1`/`0` | – | **Admin only.** `0` disables the weather tool (external Open-Meteo requests) everywhere (Issue #69). Read and written through the admin settings API only, so a regular user cannot flip it. |
| `index_enrolled` | P/S | `0` | `1`/`0` | – | Per-user opt-in for recurring background indexing. |
| `chat_retention_days` | P | `0` | `0`–`3650` | days | Automatically delete chats not used for this many days (`0` = keep everything). The daily `ChatCleanupJob` applies it per user. |

### Web search

Web search lets the chat model look up information on the internet when the
user's own indexed files cannot answer a question. It is **off by default** and
opt-in per user: each user can individually enable it and choose their provider
in the personal Eva AI settings.

**DuckDuckGo** is the default provider — it works immediately without any API
key or admin configuration. For self-hosted or paid providers (SearxNG, Brave,
Tavily), the administrator configures the URL or API key in the Eva AI admin
settings. The API key is encrypted and never returned to any client.

> **DuckDuckGo from a server:** EVA talks to the same endpoints a browser does,
> in order: the HTML search page, the lightweight page, then the Instant Answers
> API. DuckDuckGo answers automated traffic and many data-center IP ranges with
> an anti-bot page instead of results. When that happens EVA reports it (and the
> Instant Answers API still covers encyclopedic queries), but for reliable
> results on a hosted server use a self-hosted **SearxNG** URL, or a **Brave** or
> **Tavily** API key. You can check the effective behaviour with
> `occ eva_ai:tool <user> web_search '{"query":"nextcloud"}'`.

The chat model decides when to search: it has to call the `web_search` tool
explicitly, and while `web_search_enabled` is `0` that tool is removed from every
surface (web, Talk, Assistant and RAG) and blocked at dispatch, so a switched-off
instance cannot send anything to a search service (Issue #187). Web results are
marked as external sources and must be cited as links - they are never presented
as one of the user's indexed files.

Every provider's results are merged and re-ranked by how well each hit matches
the query (matched terms in the title weigh most, then the snippet and URL path;
known low-value hosts are pushed to the back). The kept results are then fetched
in parallel and enriched with the readable text of the page itself, so the answer
is grounded in the source rather than in a search-engine teaser.

| Key | Scope | Default | Range / values | Unit | Effect |
|---|---|---|---|---|---|
| `web_search_enabled` | P | `0` | `1`/`0` | – | Per-user opt-in. `1` exposes the `web_search` tool to the chat model. Off by default; enabling it means queries leave the server. |
| `web_search_provider` | P | `duckduckgo` | `duckduckgo`, `bing`, `searxng`, `brave`, `tavily` | – | Per-user. Web index. `duckduckgo` and `bing` are free and need no API key; `searxng` is self-hosted; `brave` and `tavily` are hosted APIs that need admin-configured credentials. News articles are not tied to this choice: they always come from the free news feeds (see below). |
| `web_search_url` | I | `''` | `http(s)://host[:port][/path]` or empty | – | **Admin only.** Base URL of the SearxNG instance (JSON output must be enabled there). Required when users choose the `searxng` provider. |
| `web_search_max_results` | I | `8` | `1`–`20` | results | **Admin only.** Maximum results per search across all providers; hard-capped in code so a chat cannot flood its context. |
| `web_search_timeout` | I | `10` | `1`–`30` | seconds | **Admin only.** HTTP timeout for one search request. |
| `web_search_safe_search` | I | `1` | `1`/`0` | – | **Admin only.** Ask the provider to filter adult results. |
| `web_search_fetch_content` | I | `1` | `1`/`0` | – | **Admin only.** `1` fetches the ranked result pages in parallel and adds their readable text as `content`, so the model answers from the page instead of a teaser. `0` keeps only the search-engine snippets (faster, less traffic to third-party sites). |
| `web_search_content_chars` | I | `2000` | `200`–`8000` | characters | **Admin only.** Maximum readable text taken from each fetched page; hard-capped in code. |
| `web_search_candidates` | I | `12` | `3`–`20` | results | **Admin only.** How many engine hits are read and scored before the best ones are returned. A larger field costs one page fetch per extra candidate but lets the ranking reject the wrong first hits instead of trusting them. Never below `web_search_max_results`. |
| `web_search_images` | I | `1` | `1`/`0` | – | **Admin only.** `1` attaches up to three images per result (the page's Open Graph/Twitter image plus in-article images), so the assistant can show the actual figure instead of describing it. Only images inside the fetched article container are used; icons, logos and tracking pixels are filtered. |
| `web_search_browser` | I | `0` | `1`/`0` | – | **Admin only.** `1` reads a result page in a real headless browser when a plain fetch comes back as a shell - the case for client-rendered sites, cookie walls and pages that only exist after JavaScript has run. It needs Node.js and the `playwright` package on the server; the settings page says whether both were found and why not when they were not. When it is off, or the server cannot run it, the search works exactly as before. |
| `web_search_browser_node` | I | `''` | path or command | – | **Admin only.** Node.js executable to use. Empty means `node` is looked up on `PATH` (then `/usr/bin/node`, `/usr/local/bin/node`). Set it when the PHP process has almost no `PATH`, which is common for a web server. |
| `web_search_browser_browsers_path` | I | `''` | absolute path | – | **Admin only.** Directory Playwright's browser builds are looked up in, set as `PLAYWRIGHT_BROWSERS_PATH` for the renderer. Empty means Playwright's own location for the user the web server runs as (`~/.cache/ms-playwright`). Set it when the build lives elsewhere - a shared directory, a different account's home, or a container path. See [BROWSER-RENDERING.md](BROWSER-RENDERING.md). |
| `web_search_browser_timeout` | I | `20` | `3`–`60` | seconds | **Admin only.** How long one rendered page may take before the browser process is killed. Rendering is worth seconds per page, so only pages whose text did not arrive are rendered, at most four per search. |

**Search modes.** The `web_search` tool takes a `mode`: `web` (the configured provider), `news` (Bing News and Google News RSS, which need no key and carry the publication date and the source name; the language and region follow the asking user's own language) or `all` (both merged). Results are ordered with the best and most recent first, and a dated current page outranks an undated one of equal relevance, so a question about something current is answered from the web rather than from the model's older training data.

**News links that cannot be read.** A Google News item is a redirect link, and the page it resolves to is Google's consent interstitial rather than the article (measured live: `news.google.com/rss/articles/…` lands on `consent.google.com`, titled "Before you continue", with ~1,400 characters of cookie notice). Such links are kept in the result list as headline references with their date and publication, but they are never fetched and never rendered: a batch of three of them used to consume an entire 26-second render budget and return nothing, and the interstitial text could otherwise be quoted as if it were the article. Consent and aggregator hosts are therefore refused by the same URL policy that governs every other fetch and render.

**Reading a page.** The `open_website` tool reads a single http(s) page in full (up to 20000 characters) with the passages that match a query, plus its images and publication date. It is gated by the same `web_search_enabled` switch as the search itself.

**Pages that need JavaScript.** A plain HTTP GET reads the bytes a server sends; it cannot run the page's scripts. For a growing share of the web those bytes contain no article at all - the text arrives later from an API call - so an answer built from them is a guess presented as a quotation. With `web_search_browser` on, such a page is loaded in Chromium through `bin/render-page.mjs` and the resulting DOM is run through the same extraction as any other page. Two rules keep it honest and safe: only pages whose statically fetched text is shorter than 400 characters are rendered (a page that already yields prose is never rendered), and at most four per search, in one browser process. The URL policy is applied on the way in *and* to the address the browser finally settled on, because a redirect is a fresh request that the browser makes on its own. Playwright installs its browser separately from the npm package, so the settings page checks for all three pieces (Node.js, the package, a Chromium build) and names the one that is missing; [BROWSER-RENDERING.md](BROWSER-RENDERING.md) has the install and verification commands.

**Pictures.** The `search_images` tool finds pictures of a subject in the keyless public image index and returns them ready to embed (the picture, a caption, a thumbnail that always loads and the page it was found on). It exists because a text search answers a picture request with pages, which is why the model used to reply that it cannot display images. It uses the same `web_search_enabled` switch and is individual-result independent of `web_search_images` (which attaches page images to *search results*); a query that itself asks for a logo or an icon keeps those pictures instead of having them filtered as chrome. The page a shown picture came from is added to the answer's Sources list.
| `web_search_api_key` | I | – | 8–256 chars | – | **Admin only, write-only.** Encrypted API key for `brave`/`tavily`. Send it as `web_search_api_key`; clear it with `remove_web_search_api_key`. It is never read back. |

### Internal per-user runtime state (S)

Managed by the indexer; never user-facing configuration and never inherited
from an instance-wide value.

| Key | Meaning |
|---|---|
| `index_running` | `1` while a per-user index pass holds its claim. |
| `index_started` / `index_heartbeat` | Unix timestamps of claim start / last progress. |
| `index_finished` | `1` after the last completed pass. |
| `last_index_processed` / `last_index_total` | Progress counters of the last pass. |
| `last_index_error` | Message of the last failed pass. |
| `last_index_cache_hits` / `last_index_cache_misses` / `last_index_ollama_requests` | Embedding-cache and request counters of the last pass. |
| `last_index_failed` | Files the last pass had to skip because they could not be read or embedded. A skipped file is retried on the next pass; its previous index entry stays searchable. |
| `index_config_hash` | Hash of the user's indexing settings; a change forces re-embedding. |
| `index_mode` | `idle`/`running`/`stopping` display state. |
| `index_cancel_requested` | `1` when the user asked to stop a running pass. |
| `index_run_id` | Unique id of the current/last pass. |
| `index_enrolled` | Opt-in for recurring background indexing (see above). |
| `knowledge_initialized` | `1` once the per-user `KNOWLEDGE.md` has been created. |

### Global scheduler state (G)

| `proactive_schedule_runs` | JSON map of the last delivered local time slot per scheduled briefing; prevents duplicate notifications after cron retries. |

| Key | Meaning |
|---|---|
| `index_job_running` | `1` while a periodic `IndexJob` run is active. |
| `index_job_started` | Unix timestamp when the current run claimed the scheduler lock. |
| `index_job_max_seconds` | Wall-clock budget (seconds, default `50`, range `10`–`600`) one periodic run may spend before the next cron tick continues (Issue #112). Shared fairly across all selected accounts within a run. Editable on the admin page (Indexing performance) or via `occ config:app:set eva_ai index_job_max_seconds …`. |
| `index_job_interval_minutes` | How often the periodic `IndexJob` runs (minutes, default `5`, range `1`–`60`). Lower values index a large library sooner at the cost of more frequent background work; each tick is still bounded by `index_job_max_seconds`. |
| `index_job_last_user` | Last user finished by a periodic run; the next run rotates past it for fairness (Issue #112). |
| `index_max_concurrent` | I | `2` | `1`–`16` | passes | Maximum index passes running concurrently across all users (Issue #142). Editable on the admin page (Indexing performance) or via `occ config:app:set eva_ai index_max_concurrent …`. |
| `index_scheduler_active` | JSON map `user → heartbeat` of currently running index slots (default `{}`, Issue #142); stale slots are reclaimed after 15 minutes. |
| `index_scheduler_queue` | JSON FIFO list of users waiting behind the concurrency limit (default `[]`, Issue #142). |
| `index_job_stop_requested` | Durable stop request (default `0`) for the periodic background run: the running tick aborts at the next user boundary and the following tick acknowledges (clears) the flag without starting. Set via the admin dashboard “Stop background indexing” action. |

## Settings page

The web UI presents the configuration in groups: **Connection & models**,
**Safety & actions**, **Search & answer quality**, **Indexing & scope**, and
**Talk & notifications**. Use **Save changes** to persist the form;
**Save & start indexing** saves first and stops if saving fails. The UI shows
`max_file_size` in MB while the app stores bytes. Chat-history deletion is
separate from index deletion (deleting the index removes indexed documents and
vectors, not chats or original files).

## Reading the current configuration

```bash
sudo -u www-data php occ config:list apps --app=eva_ai
```

## Troubleshooting

- **First answer is slow** → the model may still be loading; `ollama pull <model>`
  or pre-warm with a short test query.
- **No answers / connection refused** → check `ollama_url` and reachability from
  the web-server user: `sudo -u www-data curl http://127.0.0.1:11434`.
- **Chat has no tools** → verify `actions_enabled=1`.
- **Talk bot does not appear** → run `occ eva_ai:talk:setup`, then activate the
  bot per conversation. If the bot stays silent on ordinary messages, check
  `talk_classify_all` (default `0` keeps human smalltalk away from the LLM).
- **"Chat storage is busy" keeps coming back** → a request is writing the chat
  file, or a request died while holding its lock. The app logs this as
  `eva_ai: chat lock contention` with the user, the wait in milliseconds and
  whether the lock is still held; when the lock is still held after the retries
  it logs at error level instead. Reads never block (they fall back to a
  lock-free read of the intact file), and the lock is not owner-bound, so a lock
  left behind by a crashed request clears itself when the locking provider
  reaches its own timeout — 3600 s for the database provider, which is a
  constructor setting of that provider and cannot be lowered per request. If a
  single user is stuck for that long and you do not want to wait, clear the lock
  for that user with `occ` after confirming no request is running:

  ```bash
  # Reports whether the lock is held and, with --force, releases it
  sudo -u www-data php occ eva_ai:clear-chat-lock <user> --force
  ```

  Prefer waiting, and prefer checking the log first: releasing a lock a live
  request is holding can let two writers touch the same file. Never delete lock
  rows by hand — the locking backend is shared with other apps.

### Optional local OCR

`ocr_enabled` is a per-user boolean (`0` by default, `1` to enable).
`ocr_language` defaults to `eng`; use installed Tesseract language codes such as
`deu+eng` (at most four identifiers). Settings report missing Tesseract/Poppler.
Images and textless PDFs are processed locally. Hard limits: 20 MiB input,
30 PDF pages, 25 megapixels per input image, 2 MiB extracted text and 60 seconds
per file. PDFs are rendered one page at a time at no more than 2000 pixels per
side. OCR errors preserve the last-good index. Install language packages on the
server; the app never downloads documents or language data to a remote service.

## Groq API credentials and free usage

Select Groq in Settings, obtain a key from https://console.groq.com/keys, paste
it into the password field, save and use Check connection. The connection check
queries the model catalog; it does not generate tokens. Keys are encrypted with
Nextcloud ICrypto in the current user's preferences (`groq_api_key_encrypted`).
They are never inherited from an administrator, returned in settings or included
in exports. A blank field preserves the saved key; the removal switch deletes it.
`groq_api_key` and `remove_groq_api_key` are write-only settings request fields.
Deleting EVA user data also removes the credential.

Requests use the fixed HTTPS endpoint https://api.groq.com/openai/v1; redirects
are disabled. Messages, retrieved excerpts and tool outputs leave your server.
This also applies to Assistant tasks and Talk requests using that user's provider.
Groq does not supply embeddings here: indexing and retrieval over indexed files
still require Ollama. Empty-index chat does not require a local model.

The model selection follows https://console.groq.com/docs/rate-limits (checked
2026-09-09). Free accounts have request and token quotas; paid accounts may be
billed by Groq even for these models. EVA never switches models automatically.
HTTP 429 is surfaced as a quota error; shorten large conversations or retry later.
Streaming, function calls and non-streaming generation share the same selection.
Ollama context size and dedicated summary/fallback models do not apply to Groq.
Groq generation is capped at 1024 output tokens per request.

Groq requests use a conservative 28,000-byte serialized input budget (an estimate,
not an exact token count). Older complete conversation turns are removed first;
system instructions and the current turn, including tool calls/results, remain
intact. If the current turn alone is too large, EVA asks for less context before
sending it. Tool descriptions are shortened; tool names and schema constraints
remain available. Saved chat history is unchanged. Groq uses local greeting and
follow-up templates to avoid consuming the account quota for background requests.
Rate-limit errors distinguish oversized requests from temporary limits and show
numeric Limit/Used/Requested and Retry-After values when supplied by Groq, without
exposing raw provider errors, organization IDs or credentials.

### OpenAI-compatible providers

Set `chat_provider` to any lower-case provider id (for example `openai`,
`mistral`, `deepseek`, `openrouter` or `company-gateway`). Configure
`custom_provider_url` with the provider's `/v1` endpoint and
`custom_provider_model` with its model name. The write-only
`custom_provider_api_key` request field is encrypted per user; it is never
returned by the settings API. EVA sends the standard `/chat/completions`
payload, including tools, so any compatible hosted or self-hosted gateway can
be used without a code change.
