# EVA — Security model

This document describes how EVA (eva_ai) protects your data and your Nextcloud
instance while an LLM executes tools on your behalf.

## 1. Threat model

EVA gives a locally running LLM access to tools that can read and modify
Nextcloud data (files, contacts, calendars, mail, shares, tasks). The main risks
are:

- **Prompt injection**: a file's content (indexed text, an email body, a shared
  document) instructs the model to perform unintended actions.
- **Privilege escalation**: a tool runs with more rights than intended, e.g. a
  background worker or a TaskProcessing task performing mutating operations.
- **Destructive actions**: deletion of files, contacts, calendar events or shares
  triggered by the model.

The design goal is: **every tool execution must be safe, least-privileged and —
when it changes data — explicitly confirmed by the user.**

## 2. Tool policy (`lib/Service/ToolPolicy.php`)

Every tool is registered centrally with three properties:

| Property | Values | Meaning |
|---|---|---|
| `risk` | `readonly`, `mutating`, `destructive` | Severity of side effects |
| `surfaces` | `web`, `talk`, `rag`, `taskprocessing` | Where the tool may run |
| `requiresConfirmation` | `true` / `false` | Must the user approve first? |

### Risk classification

- **readonly** — no side effects. Most are safe on every surface. Sensitive
  profile, share-listing and server-status reads are intentionally unavailable
  on Talk:
  `list_files`, `read_file`, `search_files`, `find_contact`, `read_profile`,
  `list_calendars`, `list_calendar_events`, `find_free_slots`, `search_mails`,
  `list_mails`, `read_mail`, `unread_mail_count`, `list_shares`, `list_tasks`,
  `recent_activity`, `server_status`, `current_time`, `weather`.
- **mutating** — creates or updates data. Always `requiresConfirmation = true`
  (except `complete_task` and `update_knowledge`, which are low-risk upserts):
  `create_file`, `create_note`, `create_folder`, `rename_file`,
  `update_knowledge`, `create_contact`, `update_contact`, `update_profile`,
  `create_calendar_event`, `update_calendar_event`, `create_share`,
  `update_share`, `create_task`, `update_task`, `complete_task`.
- **destructive** — deletes data. Always `requiresConfirmation = true`:
  `delete_file`, `delete_contact`, `delete_calendar_event`, `delete_share`,
  `delete_task`.

### Surface isolation

Not every surface may run every tool:

| Surface | Context | Mutating tools allowed? |
|---|---|---|
| `web` | EVA web chat UI | ✅ yes (with confirmation) |
| `talk` | Nextcloud Talk bot | ✅ yes (with confirmation), but sensitive profile/share/server-status reads are blocked |
| `rag` | RAG pipeline internals | ❌ no — only readonly tools |
| `taskprocessing` | Assistant app / background workers | ❌ no — only readonly tools (before user confirmation) |

This prevents a background job or the Assistant app from silently creating,
deleting or overwriting data. Talk additionally does not expose profile details, existing share listings or instance server status. The `AgentInteractionProvider` switches the surface to `web` **only after** the user confirmed a proposed tool call, so the confirmation flow keeps working in TaskProcessing.

Talk history is opt-in: TaskProcessing only accepts explicitly supplied room IDs and caps the context at three rooms and the latest 20 messages per room. It never discovers and injects every room automatically.

### Enforcement point

`ActionExecutor::run()` is the single chokepoint: it calls `ToolPolicy::check()`
for the tool name before any operation is performed. Tools that are not
registered, not allowed on the current surface, or that fail validation are
rejected **before** touching any data. This also defends against prompt-injected
fake tool names.

## 3. Confirmation flow (agent mode)

For mutating/destructive tools EVA uses a two-phase flow:

1. **Proposal phase**: the model proposes a tool call; the UI shows exactly what
   will happen ("Create file `notes.md` with …", "Delete event …").
2. **Confirmation phase**: only after the user clicks **Confirm** the tool is
   executed. The surface switches from `taskprocessing` (readonly) to `web`
   (mutating allowed) at that point.

## 4. Additional guards

- **`exec_delete_mode`** (default `own`): the model may only delete files that
  EVA itself created (tracked via app-data `ai-marks/`). Set to `off` to disable
  deletion entirely.
- **`exec_write_types`**: restrict which file types EVA may create
  (e.g. `md,txt`). Empty = all types.
- **`exec_write_max_chars`**: hard cap on the size of AI-created files.
- **`actions_enabled = 0`**: turns the chat into read-only mode — no tools at all.
- **Scope & exclusions**: `scope_path` and `exclude_paths` limit which folders
  are indexed (and thus visible to the model).
- **TLS verification** for weather and external HTTP calls is enabled; unknown
  certificates are rejected.
- **Per-user data isolation**: documents, chunks and chat history are bound to
  the owning user (`user_id` column). The reset commands (`eva_ai:reset`) operate
  per user.

## 5. Authenticated chat-history controls

The sidebar search, per-chat rename/delete actions, and **Settings → Chat history → Delete all chats** are authenticated web-UI operations against the logged-in user's own AppData namespace. They are separate from the LLM tool policy: they cannot read, modify or delete files, and they never grant the model an additional tool.

## 5. Least-privilege in the RAG pipeline

The RAG pipeline only ever runs readonly tools on the `rag` surface. Even if a
document's text contains malicious instructions, the worst outcome is that the
model reads *more* of your own indexed documents — it cannot create, modify or
delete anything through the RAG path.

## 7. Testing

The security policy is covered by `tests/ToolPolicySecurityTest.php`
(risk classification, surface isolation, prompt-injection rejection) and
`tests/TaskProcessingContractTest.php` (provider contracts), and `tests/FrontendContractTest.php` (frontend source contracts). Run locally:

```bash
cd apps/eva_ai
composer test
```

CI runs the suite on PHP 8.2 / 8.3 / 8.4.
