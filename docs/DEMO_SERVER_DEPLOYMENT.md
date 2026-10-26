# Demo server deployment manual and compatibility decision

Status: PREPARED, NOT DEPLOYED. The actual server OS, control panel, access, resources, domain, MySQL version and SMTP provider have not been supplied. No remote compatibility or deployment approval is claimed. Existing local services/data remain intact. No Docker/WSL installation is needed or performed.

## 1. Obtain the server facts first

Record OS/release/architecture, control panel and plan limits; SSH/sudo access; PHP CLI AND FPM version/extensions/settings; disabled process functions; actual MySQL server version/strict mode/utf8mb4/InnoDB/grants; available Python virtualenv/pip/OCR; Node/Chromium/fonts/OS libraries; worker/scheduler facilities; private persistent disk, memory/CPU and upload limits; intended DNS/TLS; SMTP sender/provider; encrypted backup destination; provider policies for subprocesses and long-running jobs. Supply access through the approved connection mechanism, never a plaintext password or key in a ticket/chat/source file.

Copy only the reviewed sanitized source package to an unpromoted release. On the intended host run the read-only inventory with its actual Python environment:

```bash
/path/to/venv/bin/python ops/demo-host-audit.py --root /srv/pentagon/releases/RELEASE_ID --php /usr/bin/php --node /usr/bin/node --probe-runtime --output /private/audits/pentagon-host.json
```

If an authorized private 0600 MySQL defaults file is available, add `--mysql-defaults /private/mysql-audit.cnf`. This runs SELECT VERSION/settings only; it does not install software, modify databases or print credentials. The client binary version alone cannot verify the MySQL server. Audit output must stay outside public document roots. A successful script exit means inventory collected, not deployment approved. Review every manual gate in its JSON.

Local-only audit evidence is private under .tools/product-host-audit-windows.json: Windows, portable PHP 8.4.26, Python 3.12.10, Node 22.14.0, working extraction/OCR and PDF probe. CLI pcntl/posix are unavailable locally; Linux process management, target MySQL, TLS and SMTP remain unverified. Tools absent from PATH are not proof that a hosting feature is unavailable.

## 2. Hosting decision matrix

| Actual host | Decision prerequisites |
|---|---|
| Managed/dedicated Linux VPS | Suitable only after PHP-FPM, MySQL, virtualenv/OCR, Chromium libraries/fonts, private persistent storage, durable workers, cron, SMTP/TLS and resource tests pass |
| cPanel/Plesk with SSH and process support | Conditional: provider must demonstrate subprocesses, long timeouts, worker restart/supervision, Python packages and Chromium. Ordinary restricted shared hosting is unsuitable if any are unavailable |
| Web-only shared hosting without worker/runtime support | No-go for the current architecture. Choose compatible hosting; a separately designed processing service is not included in this package |
| Windows hosting | Current local environment remains supported for development; production-like IIS/service deployment needs a host-specific audit and service configuration. Linux templates are not certified for Windows |

See SERVER_REQUIREMENTS.md, VPS_DEPLOYMENT.md and CPANEL_DEPLOYMENT.md. Resource estimates are planning figures, not a 30-user capacity guarantee. Measure 10-30 simultaneous sessions and simultaneous extraction/PDF work on the actual host, starting with one document and one PDF worker.

## 3. Prepare a release without exposing local data

Generate the source archive with `python ops/package_release.py --output .tools/releases/pentagon-demo-source.zip`. Existing output is never overwritten. Its manifest records file SHA-256 values. The package excludes .tools, vendor, node_modules, storage, compiled dist, uploads/reference assets, database/log/backup/key files and real .env files. The twelve explicitly named synthetic monthly PDF fixtures are included for isolated acceptance tests; all business PDFs remain excluded. Only approved deployment/operator documentation is included. Share/reset tokens and obvious secrets are scanned; historical Git secrets are not certified because this checkout has no Git metadata. Build artifacts and dependencies are prepared on the verified target/build environment, not copied from this Windows runtime.

Use `/srv/pentagon/releases/RELEASE_ID` for code, `/srv/pentagon/shared/private` for source/evidence/PDF files, a protected shared environment file, private logs, a service-user virtualenv and Chromium cache. Use a non-root application/worker user. Serve only frontend/dist and backend/public/index.php through the reviewed web-server routes. Never serve the repository, .env, SQLite, backups or private storage. Do not run storage:link for private originals.

Populate deploy/env/backend.env.example privately using target credentials. Preserve an established APP_KEY; generate a key only for a genuinely new empty environment. Use production/debug=false, HTTPS origins, secure encrypted HttpOnly sessions, durable shared cache/queues, a dedicated least-privilege MySQL account, private storage and working SMTP. Configure existing renderer/Python paths and service-user browser cache. No local .env, APP_KEY, credentials or source client data is included in the package.

Install reviewed platform libraries/fonts, Composer, Node, MySQL client, Python virtualenv, selected OCR engine and Chromium under the intended service account. Run Composer platform checks against the target PHP; install the pinned core Python requirements and the selected optional OCR requirements. `backend/pdf-renderer/npm ci` installs the locked renderer dependencies; install its matching Playwright Chromium and OS libraries separately. Shared hosting needs provider permission for these operations.

## 4. Database and backup gate

Use actual MySQL 8 with strict mode, utf8mb4 and InnoDB. First rehearse the existing guarded MySQL integration tests in a dedicated empty `pentagon_test_*` schema, never a live schema. A new synthetic demo may use a new empty application database and additive `artisan migrate`; do not run migrate:fresh, reset or seeding against an existing database.

Local SQLite-to-MySQL business-data conversion is not assumed safe or performed. If existing data must move, agree the dataset and privacy scope first; rehearse a mapped conversion preserving ULIDs, relationships, JSON, checksums, approvals, publication bytes, grants and audit history in an isolated restored database. Verify counts and hashes before cutover. Keep the local original untouched. Existing target data requires a verified matching database/private-files/environment backup before any schema change.

Use ops/backup-production.sh only after reviewing paths, restricted MySQL defaults credentials, encrypted restic repository/password file, service names and a maintenance window. It pauses writers, takes matching database/files snapshots and checks the repository. Follow BACKUP_AND_RECOVERY.md for an isolated restore drill. No deletion/retention operation is automated.

## 5. Guarded preparation commands

After compatibility and backup review and explicit preparation approval, configure the target-only variables, including the actual new release directory, private 0600 environment file and virtualenv Python. Set PENTAGON_DEMO_COMPATIBILITY_APPROVED=yes, PENTAGON_DEMO_PREPARATION_APPROVED=yes and PENTAGON_BACKUP_VERIFIED=yes only after those reviews. Run `bash ops/prepare-demo-release.sh`. It refuses non-Linux hosts, current symlinks, unreviewed paths, a different existing environment, or missing approvals. It installs locked application/build dependencies, performs production configuration checks, builds same-origin React assets, runs the document doctor, additive migrations and cache/probe commands. Chromium/OS packages and optional OCR must already be provisioned.

The script DOES NOT promote a release, change DNS, install/start services, create client accounts, publish reports or expose a site. A prepared release is still not approved for deployment.

## 6. Configure and verify services

Adapt deploy/nginx/pentagon.conf.example to the actual hostname/certificate/PHP-FPM socket. Test nginx syntax and path isolation before enabling it. GET login/reset routes serve React; POST authentication routes reach Laravel; API/Sanctum routes use backend/public/index.php. Private files and arbitrary PHP remain inaccessible. Use a valid trusted HTTPS certificate and exact same-origin configuration where possible. Configure trusted proxies only for the actual TLS proxy.

Adapt deploy/supervisor/pentagon.conf.example to the actual PHP path, non-root service user, private logs and browser cache. Keep separate documents/exports queues, retry_after greater than job timeouts, and stopwaitsecs longer than the longest running document job. Do not run simultaneous SQLite workers against local data. Install the reviewed scheduler cron from deploy/cron.example under the service account. Verify real worker/scheduler heartbeats through protected System Health; a configured service is not proof it ran.

Configure SMTP privately and verify password setup/reset delivery to an authorized test client, token expiry/single use and generic reset responses. Never email plaintext passwords. Until that test passes, log mail is local-only and does not satisfy demo invitation delivery.

## 7. Acceptance and promotion approval

On an isolated target demo exercise two assigned employees on different clients, a manager and client users; generate two months from six files, review, publish, share, private-browser login/return, history, PDF permission, cross-client denial, expiration/revocation and worker failure/retry. Run 10-30 independent sessions, stale concurrent edit conflicts, immutable publication checks, real extraction/OCR and PDF/font inspection, HTTPS/header/path-denial tests and a backup restore drill. Record actual resource/latency/failure results.

Only after reviewing that evidence and obtaining explicit deployment approval may an operator promote the prepared release, gracefully replace workers and verify the protected/public smoke tests. Do not silently redirect current or start production workers during preparation. Keep the previous release and compatible backup set. Follow ROLLBACK_PROCEDURE.md; code rollback is distinct from a potentially destructive database restore. Never automatically run migrate:rollback on retained reporting history.

## 8. Operations

Monitor queue depth/age, failed jobs, extraction warnings, PDF failures, storage/memory, SMTP delivery, scheduler/worker heartbeats and backup/restore evidence. Inspect and retry specific failed source/export jobs; do not clear audit history or bulk-retry unrelated work. After code/template changes restart long-lived workers gracefully and refresh compiled view caches for that release. Update through a new release and repeat the target acceptance gates. No target-host deployment or 30-user production capacity has been certified locally.
