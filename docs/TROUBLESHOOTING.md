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

## Reporting a problem

Include app and Nextcloud versions, the screen, expected and actual behavior,
and relevant HTTP status or log entry. Use a synthetic prompt and sample file.
Remove keys, app passwords, private document text and personal identifiers
before sharing logs or screenshots.
