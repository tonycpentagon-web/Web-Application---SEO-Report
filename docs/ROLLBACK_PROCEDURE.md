# Rollback procedure

No deployment occurred in Stage 7. This procedure is for a future reviewed release. Preserve the old source/lockfiles, private environment/key, immutable private files and a verified complete database/files backup before any change.

If a release fails, stop new traffic with maintenance mode and gracefully drain workers. Identify failure and schema compatibility before switching the release symlink. The Stage 7 migration adds operational state/indexes and deliberately refuses destructive down rollback. Do not run migrate:rollback, migrate:fresh, db:wipe or delete original/evidence/history tables to reverse code.

If the previous code is compatible with additive schema, restore the previous release pointer, retain the same environment/APP_KEY and persistent storage, rebuild its caches, restart workers gracefully and verify health/auth/upload/private Draft delivery/permissions. Never overwrite files created during the failed window without reconciliation.

If schema/data recovery is needed, restore a verified complete database/files snapshot into an isolated empty destination first. Verify hashes, IDs, history and counts, reconcile post-backup writes and obtain explicit cutover approval. Keep the failed deployment and database untouched for investigation. Production restoration overwrites are not authorized by this stage. Confirm no mail, shares or worker consumers accidentally target the wrong environment.

Record incident, recovery evidence and elapsed time; resume traffic only after checks and responsible operator approval. Never rotate an existing APP_KEY as a rollback shortcut.
