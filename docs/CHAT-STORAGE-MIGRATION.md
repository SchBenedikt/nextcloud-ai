# Encrypted chat storage migration

EVA 1.18.27 introduces normalized SQL tables for encrypted chat metadata and
individual encrypted messages. New and empty accounts enter the SQL path
automatically. A scheduled Nextcloud background job migrates existing accounts
in small batches, verifies encrypted SQL records against AppData, then enables
SQL reads per user. AppData stays available for explicit rollback. While SQL
reads are disabled, successful AppData writes mirror only changed SQL message
rows. Mirror errors leave AppData intact and invalidate the checkpoint.

The job is registered on app enable/update and checks up to two accounts every
five minutes. It imports at most two batches of five chats per account per run,
persists its account cursor, and returns to an unfinished account on the next
tick. Empty accounts need no copy and switch to SQL on first chat-store access.

## Import one account

Run as the Nextcloud web/PHP user using `occ`:

```bash
php occ eva_ai:migrate-chat-storage USER_ID
```

The command takes a locked AppData snapshot, writes chats in batches of 5,
stores a checkpoint after each batch, then decrypts and compares every SQL chat
against its source record. The source fingerprint is calculated from the
stored AppData bytes; no title or message text is put into migration state.
Rerun the same command after an interrupted or `in-progress` run. Completed
batches are resumed from their checkpoint. If AppData changed during the import, the command exits
with `source-changed-retry`; rerun it to restart from the new source snapshot.

After the import reports `complete`, enable the per-user SQL read flag with:

```bash
php occ eva_ai:migrate-chat-storage USER_ID --activate-sql
```

This repeats the source fingerprint check before enabling SQL reads. Until the
flag is enabled, AppData remains the active store and mirrors successful
mutations into SQL. Enabled users read chats and metadata from SQL; writes go
transactionally to SQL without rewriting the AppData file. The indexed metadata
page avoids loading message text for normal chat lists. Explicit content search
still decrypts messages in the requested owner scope because encrypted messages
are not searchable in SQL.

## Recovery and restore

- Keep `chats.json` and `chats.json.backup` in AppData intact. Do not remove or
  rename them as part of this import.
- A database error leaves the checkpoint in `running`; rerun after correcting
  the database issue. A failed verification resets and retries on the next run.
- A failed live SQL mirror marks the checkpoint failed; rerun the command to
  rebuild SQL from the authoritative AppData snapshot.
- With SQL reads enabled, database read/write failures surface as chat errors;
  EVA does not silently fall back to a stale AppData snapshot. Restore SQL
  connectivity, then use `--deactivate-sql` to export current SQL into AppData
  before rolling back the app.
- `source-changed-retry` means a chat was written while the SQL copy was in
  progress. AppData remains authoritative; rerun to copy the latest snapshot.
- To restore from a Nextcloud backup, restore the database and the EVA AppData
  directory from the same backup point. Because AppData remains active, it can
  continue serving chats even if the imported SQL tables are cleared and
  rebuilt with this command.
- Before rolling back to an older EVA build or disabling SQL reads, restore the
  current SQL snapshot to AppData and disable the flag:

  ```bash
  php occ eva_ai:migrate-chat-storage USER_ID --deactivate-sql
  ```

  The flag is per user so canaries can be rolled back independently. Complete
  this export before deploying code that cannot read the SQL tables.
- Account deletion removes the SQL chat rows and checkpoint as well as EVA's
  AppData chat folders. Import batches and account erasure share the same
  per-user lock, and the migration stops if the Nextcloud account disappears.

The normalized SQL sidebar uses updated-time/chat-ID keyset pages and decrypts
metadata only for the requested page. AppData users and older clients retain
the offset-compatible path while migration is incomplete. Chat content search
still reads encrypted message records explicitly; the schema adds no plaintext
search index.

Frontend pagination is implemented. Mixed-version deployment/rollback
rehearsal, sustained load, and restore drills remain follow-up work. The
background job never removes AppData. In a mixed-version cluster, older EVA
nodes write AppData without SQL mirroring; wait until all nodes run the new
code before the scheduled migration activates SQL reads.
