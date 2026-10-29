# Backup and restore runbook

This runbook describes a safe restore boundary for EVA. It is operational
guidance, not evidence that a production restore has been rehearsed. Use a
staging instance with isolated users, provider credentials, and vector targets
for the rehearsal.

## What must be backed up together

Take a consistent Nextcloud backup of:

- the Nextcloud SQL database, including EVA documents, chunks, generations,
  migration state, cleanup retries, and vector outbox;
- the configured Nextcloud data directory, including EVA AppData, encrypted
  chats, chat backups, dirty-index queues, and indexing state;
- the matching Nextcloud configuration and encryption material needed to read
  encrypted app data and provider credentials;
- the EVA application version and any administrator-managed deployment
  configuration.

Restore the database and data directory from the same recovery point. Do not
pair a newer database with older AppData or restore encrypted state without the
matching Nextcloud encryption configuration. Keep secrets and backups under the
same access controls as the production Nextcloud backup.

Qdrant is optional and is not the authority for file access or chunk text. If
configured, take its snapshot using the operator's Qdrant procedure and record
the collection, endpoint profile, and snapshot time. Restoring Nextcloud without
the matching Qdrant state must leave the SQL retrieval path usable; remote
reconciliation or a fresh consented build may be needed before enabling a
Qdrant canary. Never point a rehearsal restore at a production collection.

## Restore sequence

1. Stop web writes and background workers, or place the instance in maintenance
   mode, before restoring. Restore Nextcloud SQL, the data directory, config,
   and EVA code to one compatible recovery point.
2. Keep outbound provider and Qdrant access disabled until the restored
   instance is isolated and its configuration has been reviewed. Preserve the
   original backup before running migrations or workers.
3. As the Nextcloud service account, run the normal Nextcloud upgrade procedure
   for the restored code. Confirm the app is enabled, maintenance is off, and
   Nextcloud reports no pending database upgrade before reopening traffic.
4. Verify a disposable account can list chats and open a conversation. For a
   user whose SQL chat migration is active, check the stored migration state
   before rollback; use the procedure in
   [CHAT-STORAGE-MIGRATION.md](CHAT-STORAGE-MIGRATION.md) rather than clearing
   tables by hand.
5. Check EVA indexing and generation status through the native administration
   surface and the documented OCC inspection commands. Confirm that a verified
   SQL generation is still current or that search safely uses the legacy SQL
   path. Do not manually mark a generation verified or active.
6. Inspect the vector outbox and account-erasure retry backlog. Do not enable
   destructive remote workers until target credentials, collection identity,
   leases, and the restore point are understood. Account cleanup retries can
   resume after the Nextcloud-encrypted identities and their database rows are
   restored together.
7. If Qdrant was restored, verify the intended collection and endpoint in an
   isolated target before enabling remote work. If it was not restored, keep
   SQL selected or canary rolled back; use the explicit build and verification
   flow before a new Qdrant canary.
8. Re-enable scheduled workers, then provider egress, and finally normal user
   traffic. Observe queue depth, oldest queue age, and application logs for
   retries or failed migrations.

## Minimum rehearsal record

For each restore rehearsal, record the EVA and Nextcloud versions, backup
timestamps, database backend, restore duration, chat-read result, SQL/legacy
search path, migration status, outbox and cleanup backlog before and after,
Qdrant choice, and any manual repair. Include a deletion-and-retry scenario on a
disposable account. A passing restore is specific to that database/backend and
deployment topology; it does not establish PostgreSQL/MariaDB portability,
cluster consistency, provider availability, or production recovery time.

Current implementation status: this runbook is new guidance. The integrated
stack has not yet completed a full isolated restore rehearsal or a
cross-database recovery matrix. See [BACKEND-ROADMAP.md](BACKEND-ROADMAP.md) for
the open release gates.
