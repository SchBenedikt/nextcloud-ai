# Encrypted chat storage migration

EVA 1.18.27 introduces normalized SQL tables for encrypted chat metadata and
individual encrypted messages. The existing encrypted AppData file remains the
live source of truth in this rollout stage. On current EVA versions, successful
AppData writes are mirrored to SQL in the same per-user critical section, with
only changed message rows rewritten. SQL mirror errors leave the AppData write
intact and invalidate the SQL migration checkpoint. Importing SQL rows does not
enable SQL reads, delete the AppData copy, or alter chat behavior.

## Import one account

Run as the Nextcloud web/PHP user using `occ`:

```bash
php occ eva_ai:migrate-chat-storage USER_ID
```

The command takes a locked AppData snapshot, writes chats in batches of 25,
stores a checkpoint after each batch, then decrypts and compares every SQL chat
against its source record. The source fingerprint is calculated from the
stored AppData bytes; no title or message text is put into migration state.
Rerun the same command after an interrupted run. Completed batches are resumed
from their checkpoint. If AppData changed during the import, the command exits
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

Frontend pagination and restore/load drills remain follow-up work. During a
rolling deployment, keep SQL reads disabled until all nodes run a version that
supports the SQL mirror. Older nodes write only AppData. Rerun and verify the
import after deployment, then enable SQL reads for a canary account.
