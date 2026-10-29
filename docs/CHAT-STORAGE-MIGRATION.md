# Encrypted chat storage migration

EVA 1.18.27 introduces normalized SQL tables for encrypted chat metadata and
individual encrypted messages. The existing encrypted AppData file remains the
live source of truth in this rollout stage. Importing SQL rows does not enable
SQL reads, delete the AppData copy, or alter chat behavior.

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

## Recovery and restore

- Keep `chats.json` and `chats.json.backup` in AppData intact. Do not remove or
  rename them as part of this import.
- A database error leaves the checkpoint in `running`; rerun after correcting
  the database issue. A failed verification resets and retries on the next run.
- `source-changed-retry` means a chat was written while the SQL copy was in
  progress. AppData remains authoritative; rerun to copy the latest snapshot.
- To restore from a Nextcloud backup, restore the database and the EVA AppData
  directory from the same backup point. Because AppData remains active, it can
  continue serving chats even if the imported SQL tables are cleared and
  rebuilt with this command.
- Account deletion removes the SQL chat rows and checkpoint as well as EVA's
  AppData chat folders.

SQL cutover, automatic background migration, frontend pagination, and a
mixed-version write recovery protocol are separate follow-up work. Do not
enable a SQL read path before those pieces are complete.
