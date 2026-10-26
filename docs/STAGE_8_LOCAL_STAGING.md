# Stage 8 reproducible local staging

Read STAGE_8_BASELINE.md and deploy/staging-local/README.md. Docker/WSL2/MySQL 8 execution is **BLOCKED** on the current host. The Linux/MySQL Compose package is prepared, not certified. No provider was selected and nothing was publicly deployed.

## Windows fallback actually exercised

Separate directory: .tools/stage8-local-windows. Separate SQLite database, private storage, generated APP_KEY, credentials, encrypted database sessions, database cache/queues, logs and exports. Original backend .env is not loaded: bootstrap shadows it and forces isolated DB/storage/cache/queue/mail settings, including inherited shell environment. All seeded users/documents are synthetic. PHP's localhost-only development server serves compiled React assets and existing Laravel routes on 8188; restored copy uses 8288. This is not PHP-FPM, Linux, MySQL or production HTTPS.

From repository root, using existing local tools:

```powershell
.tools/python/python.exe ops/staging_setup.py init
# Init refuses any existing staging directory; it never resets or re-seeds it.
$env:VITE_API_URL='/'
npm.cmd --prefix frontend run build
.tools/python/python.exe ops/staging_setup.py start
.tools/python/python.exe ops/staging_setup.py schedule
node ops/staging/workflow.mjs
node ops/staging/batch-stress.mjs
```

Build with VITE_API_URL=/ explicitly: a development frontend environment may otherwise point compiled assets at port 8000. Development .env and credentials are unchanged. The frontend's origin-only referrer metadata is required for same-origin Sanctum GET authentication while omitting URL paths/query tokens. Real authenticated browser checks validate this behavior.

Use `staging_setup.environment()` in Python when invoking ops/staging/console.php or fixture.php; the former runs existing Artisan against the isolated root. Create load users and run the checks using these exact guarded wrapper commands:

```powershell
.tools/python/python.exe ops/staging_setup.py load-users
.tools/python/python.exe ops/staging/load_test.py
.tools/python/python.exe ops/staging/format_matrix.py
.tools/python/python.exe ops/staging/backup_restore.py
node ops/staging/browser-check.mjs
node ops/staging/email-check.mjs
```

Run load tests before password-reset tests. Fixtures, backups and artifact filenames use exclusive creation where practical: these commands describe a fresh run, not a destructive reset loop. Format and restore tools refuse populated outputs. Re-running the workflow on its retained published fixture can add test export history; it never re-approves a real client. Review state first rather than blindly repeating it.

For PDF inspection and measured offline rendering:

```powershell
.tools/python/python.exe ops/staging_setup.py inspect
.tools/python/python.exe ops/staging/inspect_pdfs.py
.tools/python/python.exe ops/staging_setup.py benchmark-html
.tools/python/python.exe ops/staging/measure_pdfs.py
.tools/python/python.exe ops/staging_setup.py health
.tools/python/python.exe ops/staging_setup.py queue-restart
```

Health probes may briefly return Unavailable during a real failure; run only after dependencies are installed. Queue restart is graceful and needs worker replacement afterward. A stopped primary staging environment can be started again with `staging_setup.py start`; recorded live PIDs and occupied port 8188 are refused. No files are removed. Queue persistence was also exercised by graceful worker restart, uploading while stopped, replacing only the verified synthetic web process and restarting the worker. See test-results scope/limits.

Shutdown only the recorded Stage 8 Windows processes:

```powershell
.tools/python/python.exe ops/staging/process_control.py stop-disposable
```

The helper verifies the exact .tools staging roots, marker, recorded PID and actual Windows process command line/port, and refuses to terminate a worker with reserved work. Prefer isolated Artisan queue:restart before shutdown. It never deletes files or databases. Existing development workers on ports 8000/5173 are outside this process guard. Retain staging directories/evidence for review; no recursive cleanup script is supplied. For a new run, archive retained evidence and provision a separately reviewed disposable name rather than overwriting it. Compose shutdown and precisely named-volume cleanup commands are in its README.

Actual startup services: Windows staging web/worker and separate restored web. Scheduler heartbeat executed by explicit schedule:run, not a verified persistent Linux scheduler. Mail capture uses private Laravel log transport, never an external relay. Mailpit/HTTPS/MySQL services were prepared but not started.
