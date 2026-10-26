# Stage 8 backup and restoration

**PASS for isolated synthetic Windows recovery; BLOCKED for MySQL/encrypted offsite recovery.** No development DB was overwritten and no real backup was sent anywhere.

ops/staging/backup_restore.py only accepts its fixed marker-bearing .tools/stage8-local-windows source and a new sibling .tools/stage8-local-restored destination. SQLite's backup API captures a consistent database copy. A synthetic ZIP includes that database, all private uploaded/evidence/PDF files, fixture recovery account metadata, separate staging environment and sanitized configuration templates, with a SHA-256 manifest. Restore checks use the traversal/nonempty-destination safeguards from ops/backup_drill.py. Every file hash is verified before intentionally adapting restored routing/runtime paths; the staging APP_KEY is retained. Existing destination or snapshot refuses overwrite.

Actual result: 64 files, checksum match, SQLite integrity_check=ok and zero foreign_key_check violations. Snapshot counts: 58 synthetic users, three clients/projects/reports, 15 documents, 1,087 normalized records, 22 verifications, one publication, seven exports and 16 delivery events. Restore preparation took 1.282 seconds in this local run. This is not a production RTO. The later invitation/restart drills added further synthetic records after this checkpoint; the archive represents its recorded snapshot time.

The new restored web process uses localhost:8288, independent runtime/cache/session-cookie paths and original fixture recovery credentials. Real HTTP login, publication/history access and private PDF download from the restored copy passed. No original development account or key was involved.

Reproduce from a fresh initialized synthetic environment, after workflow/batch tests:

```powershell
.tools/python/python.exe ops/staging/backup_restore.py
node ops/staging/browser-check.mjs
```

The archive is unencrypted synthetic test data inside an ignored, nonpublic local tools directory. It contains generated staging credentials: do not publish it or treat .gitignore as access control. Retain local filesystem permissions. Production requires encrypted complete paired MySQL/private/config snapshots, protected restore credentials, approved retention and an offsite restore rehearsal; see BACKUP_AND_RECOVERY.md. None of those external guarantees was tested here. No automatic retention deletion was installed. Shutdown retains the recovery evidence; cleanup must identify only the disposable staging resources.
