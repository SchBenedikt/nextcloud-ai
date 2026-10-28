# Troubleshooting EVA

## A section does not load

Look for missing JavaScript chunks in the browser Network panel. Deploy all
rebuilt `js/` files, including deletions. An old cached main bundle combined
with new chunks can require a reload. Record HTTP errors and app versions
before clearing unrelated data.

## The provider does not answer

In **Settings → Models**, check the active profile, API base URL and exact model
ID. A model name does not grant access. Check key permissions, model access and
account quota with the provider.

The endpoint must be reachable from the Nextcloud server. Localhost refers to
that server or container. Disable key authentication explicitly for a compatible
server that permits it; a blank key alone preserves an existing saved key.

A model-list check does not prove support for tools, images or audio. Verify a
minimal chat before testing advanced operations.

## Chat works but document answers fail

Check Ollama and the embedding model separately from chat. Confirm that the file
is in scope, readable, not excluded and within the configured limits. Expand
its Documents row and inspect extracted chunks before tuning retrieval.

## Indexing stays queued

Check Nextcloud cron and worker logs. Leaving the page does not stop indexing.
The progress route deliberately omits model diagnostics; use Models for
connection checks and Advanced for system diagnostics.

## A setting does not save

Read the validation message above Settings. Core fields auto-save; provider
profiles need **Save & use provider**. Index-related settings stay locked during
a run. Do not refresh away an unsaved provider draft before copying its
non-secret values.

## Background work is still running

Open **Settings → Advanced → Background tasks**. Pausing a queued run differs
from stopping the browser stream. Inspect the existing run before submitting
the same expensive task again.

## A completed answer is followed by "Another chat request is already running"

Update all application nodes to the chat-request lock fix. The previous request
key was 76 bytes, exceeding Nextcloud's 64-character `file_locks.key` column.
On databases that truncate oversized values, the first acquisition could succeed
while release using the original untruncated key matched no row, blocking later
requests. Strict databases could reject acquisition outright and also receive the
misleading busy message. The fixed purpose-specific key is 60 ASCII characters,
separate from the chat-history storage lock. Do not widen or edit Nextcloud's core
lock table and do not delete active locks blindly.

Stream producers now close and release the request slot before exposing a final
answer, confirmation or error. Early disconnects, metadata exceptions and output
failures also release coordination through protected cleanup. Non-lock backend
failures return temporary-unavailable (503), not falsely occupied (429), with
sanitized coordination diagnostics in the server log. Real simultaneous chat
requests still receive a busy response; the request-rate budget remains enforced.

This changes the request-lock namespace, so quiesce chat requests and deploy the
fix together on all application nodes. Do not serve old and new request-gate code
concurrently during rollout. Legacy abandoned rows can expire under Nextcloud's
normal policy; this fix does not clear locks owned by other requests. The
`eva_ai:clear-chat-lock` recovery command addresses chat-history storage and is not
a general request-slot reset. SQL syntax/streaming/concurrency runtime validation
remains outstanding while tests are deferred.

## Reporting a problem

Include app and Nextcloud versions, the screen, expected and actual behavior,
and relevant HTTP status or log entry. Use a synthetic prompt and sample file.
Remove keys, app passwords, private document text and personal identifiers
before sharing logs or screenshots.

## Local aliases and login redirects

Use the host configured for Nextcloud when signing in. A fixed `overwritehost`
that differs from the browser address can redirect a login POST to another origin;
the browser then blocks it with `form-action self` and the login remains pending.
Check the canonical host and reverse-proxy settings rather than weakening CSP.
For a development instance that also accepts localhost, constrain host overwrites
with Nextcloud's `overwritecondaddr` when appropriate. Keep public/proxy behavior
and trusted domains intact.

EVA emits webroot-relative API and streaming paths, so authenticated requests stay
on the browser's origin even when the instance has a canonical host override.
Subdirectory installations retain their configured webroot.

## Answers disappear after an immediate reload

A completed chat now keeps its composer busy until the ordered user/assistant
history writes finish. The native unload confirmation protects unfinished
requests or pending saves, and bounded message writes use fetch keepalive. A
completed response awaiting storage is not handed off as a new background model
request. If storage fails, EVA shows a history warning; visible response text alone
does not prove durable storage. Test completion after the Send control returns,
then reload and verify both turns remain in history.
