# EVA — Frequently Asked Questions (FAQ)

Common questions and troubleshooting. If your problem is not covered here,
please open an issue using the templates (they follow the same Triage /
Summary / Reproduction / Expected behavior format used across this repo).

---

## Setup & connection

### "Check connection" fails with my Ollama instance

- Verify the URL is reachable **from the web server**, not from your browser:
  `http://127.0.0.1:11434` only works when Ollama runs on the same machine as
  Nextcloud. For another machine use
  `http://<host>:11434` (e.g. `http://192.168.1.50:11434`).
- The connection check always uses the endpoint you configured (per user), so a
  wrong `ollama_url` shows up in the check result.
- Make sure Ollama listens on the network, not only on localhost:
  `OLLAMA_HOST=0.0.0.0:11434` (or set `OLLAMA_HOST` in the Ollama systemd unit).
- HTTP/HTTPS: both are supported, but TLS certificates must be valid
  (self-signed certificates are rejected).

### Which models do I need?

```bash
ollama pull gemma4:cloud            # chat model (default)
ollama pull nomic-embed-text:latest # embeddings (default)
```

Other chat models usually work too; the embedding model must be a
text-embedding model.

### Why do I get a 400 error as a non-admin user?

Some endpoints are admin-only by design (global configuration, index
management). Normal users can use their own settings. If you get a 400/403 on
a user-facing action, check `nextcloud.log` for the exact message — most tools
return a German or English explanation.

---

## Indexing

### Indexing takes very long / "Stop" seems to do nothing

The worker only checks for a stop request **between** embedding batches. A
single batch can take up to 600 s, so stopping can appear unresponsive for a
few minutes. See issues #65 and #94. The `index_finished` state may also be set
while the worker is still finishing up — wait for the run to actually finish.

### Starting an index returns 409 Conflict

A 409 means an index run is already active for this user (worker lock).
Duplicate starts are handled idempotently; a genuine lock conflict is reported
explicitly. Wait for the running pass to finish or stop it first.

### A document expands but shows "no chunks"

This happens when chunk rows for the document are missing or were dropped.
Known related problems: the chunker previously removed duplicate paragraphs
(issue #62) and the extraction may deliver partial content (issue #66). The
document is usually not re-chunked until its content changes — use
`occ eva_ai:index <user>` to re-run the pass.

### Documents load slowly / not all documents appear at once

The document list is paginated and loads more entries via **"Load more"**.
Chunk inspection of very large documents loads all chunks at once (issue #91)
— for very large files this can take a moment.

### How do I exclude folders from indexing?

Use **Settings → Indexing & scope** → **Exclude paths** (comma-separated path
prefixes) and save. Exclusions are stored per user and applied on the next
indexing pass.

### Which formats are indexed?

Text, Markdown, code, CSV/TSV, HTML, JSON, XML, YAML, TOML, RTF, SQL, PDF,
Office (docx/xlsx/pptx incl. macro/template variants), OpenDocument
(odt/ods/odp), EPUB and more. Full-content extraction of all Office formats is
tracked in issues #60 and #66.

---

## Chat & tools

### The answer doesn't see the whole document

In the **file-context chat** (right-click → "Open with EVA") the model receives
a bounded excerpt of the selected files (issue #63). In the **normal chat** the
RAG retrieval picks the most relevant chunks — very large indexes can miss
semantic-only matches (issue #61).

### EVA always answers in the wrong language

EVA answers in the language of your question. KNOWLEDGE.md and retrieved
context are taken into account; if you want a fixed behaviour, put an
instruction in `KNOWLEDGE.md` (e.g. "Always answer in German.").

### EVA knows personal facts about me — where do they come from?

On first use, EVA writes a small `KNOWLEDGE.md` profile section into your home
folder (user ID, display name, optional email). You can edit or delete it
anytime; it is never overwritten automatically. See docs/PRIVACY.md.

### The weather tool sends data to Open-Meteo — can I disable it?

Currently there is no opt-out setting (issue #69). The weather tool only sends
the location name to `open-meteo.com`. Disable the tools entirely in Settings
("Enable chat tools") if you don't want any external call.

### My chat history is long — is everything kept?

Chats are stored per user in the app data. Very long conversations are
silently bounded (issue #96); agent conversations are bounded as well
(issue #105).

---

## Nextcloud Talk bot

### The bot does not appear in a conversation

The bot registers on app boot when Talk is enabled. Activate it per
conversation in the conversation settings ("Bots"). Re-register explicitly:

```bash
sudo -u www-data php occ eva_ai:talk:setup
```

### Which documents does the bot use?

The bot answers based on the indexed documents of the user who added it
(optionally with the last `talk_history_size` messages as context).

---

## Nextcloud Assistant / TaskProcessing

### Tasks stay "scheduled" and never run

TaskProcessing needs a worker cron:

```bash
sudo -u www-data php occ taskprocessing:task-type:set-enabled core:text2text:chat 1
```

and a cron entry, e.g. `/etc/cron.d/eva_ai-taskprocessing`:

```
* * * * * www-data /usr/bin/php -d error_reporting=0 /var/www/nextcloud/occ taskprocessing:worker -t 60 -i 2 >/dev/null 2>&1
```

### Why do the provider names differ between surfaces?

The Assistant app shows the provider labels `Eva · Local`, `Eva · RAG`,
`Eva · Tools`, `Eva · Agent`. Some Assistants versions add the task type to the
name, so the same provider can appear as "Eva (local) RAG Chat (Ollama)" or
"Eva". This is a naming inconsistency of the host app — the underlying
providers are identical.

---

## Privacy & data

### Where is my data stored?

Everything runs on your own server. See the data-lifecycle table in
docs/PRIVACY.md (documents, chunks, chat history, agent state, app
configuration) and the reset commands (`occ eva_ai:reset`).

### Are external services used?

Only optionally: the weather tool calls Open-Meteo (see above). The RAG
indexing, embeddings and chat all run against your local Ollama instance.
