# Eva AI — Private RAG Assistant for Nextcloud

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-30--35-blue)](https://nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple)](https://php.net)

**Eva AI** turns your Nextcloud into a private, searchable knowledge base powered by Large Language Models. Ask questions in natural language and get instant, cited answers drawn from your own files — fully local with [Ollama](https://ollama.com), or optionally via [Groq](https://groq.com) for cloud-hosted inference.

Your data stays yours. No third-party indexing, no external databases, no cloud required.

---

## Features

### Intelligent File Indexing

Eva automatically reads, chunks, and embeds your files for semantic search. Supported formats include:

| Format | Coverage |
|--------|----------|
| **Plain text** | txt, md, code, csv, tsv, html, json, xml, yaml, toml, rtf, sql, and more |
| **PDF** | Full text extraction via `pdftotext` |
| **Microsoft Office** | docx, xlsx, pptx (including macros and templates) |
| **OpenDocument** | odt, ods, odp with full table and sheet support |
| **EPUB** | Full content extraction |
| **Legacy formats** | doc, xls, ppt via LibreOffice (when installed) |

Format-specific extraction highlights:

- **docx** — Body, tables, text boxes, headers, footers, footnotes, endnotes, and comments
- **xlsx** — Every non-empty cell with references, inline/shared strings, grouped per sheet
- **pptx** — All slides plus speaker notes with slide numbers
- **odt/ods/odp** — Full content including tables and sheet names

### Hybrid Retrieval Engine

Combines **vector-based semantic search** with **lexical search (BM25)** using Reciprocal Rank Fusion (RRF) to deliver the most relevant results — even for exact-match queries.

### Cited Sources

Every answer includes the exact **file path** where the information was found, so you can always verify the source.

### Nextcloud Integration

Eva can perform actions directly within Nextcloud:

- **Files** — List, create, rename, delete, read, search with snippets
- **Contacts** — Find, create, update, delete (own, shared, group, and Circles address books)
- **Calendar** — List calendars/events, create/update/delete events, reminders, free-slot search
- **Mail** — Search, list, read emails (with optional RAG indexing)
- **Shares** — List, create, update, delete link/user/group shares
- **Tasks** — List, create, update, complete, delete (VTODO)
- **Profile** — Read and update your Nextcloud profile

Mutating and destructive actions always require explicit **Confirm and run** — the model never executes changes directly.

### TaskProcessing Providers

13 providers for the Nextcloud Assistant app: chat, summary, headline, topics, translate, reformulate, proofread, reformat, change tone, context write, and more.

### Talk Integration

Optional Nextcloud Talk bot — Eva answers in conversations using read-only tools. Sensitive tools (profile, shares, server status) are intentionally unavailable in Talk.

### File-Context Chat

Right-click any file in the Files app → **"Open with EVA"** for targeted Q&A based on that specific file's content.

### Zero Configuration

Just install and go. Eva automatically detects your local Ollama instance and selects appropriate models. No external services required.

---

## Requirements

| Component | Requirement |
|-----------|-------------|
| Nextcloud | **30 – 35** |
| PHP | ≥ 8.2 (module `curl` required) |
| Ollama | Reachable from the web server (default: `http://127.0.0.1:11434`) |

Recommended models:

```bash
ollama pull gemma4:cloud            # chat model (default)
ollama pull nomic-embed-text:latest # embeddings (default)
```

**Multi-node / horizontal scaling:** Eva works on clustered Nextcloud deployments that share the same database and storage. Chat mutations are serialized per user through Nextcloud's own locking provider. Ollama must be reachable from every app server.

---

## Installation

### Option 1: From Release (Recommended)

Download the `.tar.gz` from the [latest release](https://github.com/SchBenedikt/nextcloud-ai/releases) and install via **Apps → Upload app**.

### Option 2: Manual Installation

```bash
cd /var/www/html/nextcloud/apps/
git clone https://github.com/SchBenedikt/nextcloud-ai.git eva_ai
chown -R www-data:www-data eva_ai
```

### Enable the App

```bash
sudo -u www-data php occ app:enable eva_ai
```

### Prepare Ollama

```bash
ollama pull gemma4:cloud
ollama pull nomic-embed-text:latest
```

If Ollama runs on another machine or port:

```bash
sudo -u www-data php occ config:app:set eva_ai ollama_url --value=http://192.168.1.50:11434
```

### First Start & Indexing

1. Open `/apps/eva_ai` as a user
2. In **Settings**, click **"Start indexing"** to build your knowledge base
3. Start chatting — every answer cites its source files

---

## Configuration

Instance defaults live in `oc_appconfig`; per-user settings are stored in Nextcloud's user configuration.

| Setting | Default | Description |
|---------|---------|-------------|
| `ollama_url` | `http://127.0.0.1:11434` | Ollama instance URL |
| `chat_model` | `gemma4:cloud` | Chat/LLM model |
| `embedding_model` | `nomic-embed-text` | Embedding model |
| `temperature` | `0.1` | LLM creativity |
| `context_size` | `12288` | Context window length |
| `top_k` | `6` | RAG hits retrieved |
| `chunk_size` | `900` | Text chunk size |
| `chunk_overlap` | `120` | Chunk overlap |
| `max_file_size` | `20971520` (20 MB) | Largest file to index |
| `max_files_per_run` | `40` | Files per indexing pass |
| `scope_path` | *(empty)* | Restrict indexing path |
| `exclude_paths` | *(empty)* | Comma-separated paths to skip |
| `actions_enabled` | `1` | Enable chat tools |
| `mail_index_enabled` | `1` | Index emails into RAG |
| `notify_on_complete` | `1` | "AI answer ready" notification |

Example:

```bash
sudo -u www-data php occ config:app:set eva_ai chat_model --value=gemma4:cloud
sudo -u www-data php occ config:app:set eva_ai top_k --value=6
```

---

## CLI Commands

| Command | Purpose |
|---------|---------|
| `occ eva_ai:index [user]` | Index a user's files |
| `occ eva_ai:reset [--user] [--all] [--marks-only]` | Delete index data |
| `occ eva_ai:mounts` | List file mounts (debug) |
| `occ eva_ai:tool` | Run a single tool (test) |
| `occ eva_ai:talk:setup [--remove]` | Register/remove Talk bot |

---

## Data & Privacy

Eva operates on your personal Nextcloud data. All processing with Ollama happens on your server. Your files never leave your infrastructure.

| Data | Location | Retention |
|------|----------|-----------|
| Document metadata | Database | Until file deleted or index reset |
| Text chunks & embeddings | Database | Same as parent document |
| Chat history | AppData | Until user deletes |
| Knowledge base | User home | Until user edits/deletes |
| App configuration | `oc_appconfig` | Persistent |

### Reset a Single User

```bash
sudo -u www-data php occ eva_ai:reset --user=username
```

### Reset All Users

```bash
sudo -u www-data php occ eva_ai:reset --all
```

### Uninstall Completely

```bash
sudo -u www-data php occ app:remove eva_ai
```

---

## Security

All tools are classified by **risk** (readonly / mutating / destructive) and restricted by **execution surface** (web chat, Talk, RAG, TaskProcessing). Mutating and destructive tools always require explicit user confirmation.

See [docs/SECURITY.md](docs/SECURITY.md) for details.

---

## Development

- **Tests:** `composer test` (PHPUnit)
- **Frontend build:** `npm ci && npm run build`
- **CI:** GitHub Actions on PHP 8.2 / 8.3 / 8.4
- **Architecture:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **FAQ:** [docs/FAQ.md](docs/FAQ.md)

---

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)

*Bugs & feature requests:* https://github.com/SchBenedikt/nextcloud-ai/issues
