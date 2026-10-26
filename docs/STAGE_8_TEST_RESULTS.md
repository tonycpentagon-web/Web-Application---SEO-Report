# Stage 8 completion and test results

19 October 2026. Repeatable isolated local staging, synthetic verification tooling and a hosting-neutral source package are prepared. Available Windows checks completed. **Production: NO-GO. Docker, Linux and actual MySQL 8 are BLOCKED on this host.** No production/domain deployment, provider selection, GitHub push, real-client approval/publication or Stage 9 work occurred.

## Environment and isolation

Windows 10 build 19045; PHP 8.4.26, Composer 2.10.3, Node 22.14.0, Python 3.12.10; actual local PDF browser Edge 155. Docker command/Desktop absent at normal locations. wsl.exe exists but --status exits 50 and distribution listing returns installer usage; no usable WSL2 distro was found. MariaDB 10.4.27 client is available, not MySQL 8. No OS software was installed. Missing facilities are not represented as successful tests.

Prepared Linux Compose stack: PHP-FPM/backend, compiled React/Nginx with local self-signed TLS, MySQL 8.0, document/PDF worker, scheduler, Mailpit, internal network and four private named volumes. No development bind mounts, SQL host port, external relay or real client fixtures. Published ports are restricted to loopback. YAML/static isolation and shell syntax checks passed; Docker's own config/build/runtime validation remains BLOCKED. Source images contain only allowlisted code/dependencies; original/reference/private files are excluded by .dockerignore.

Actually started: isolated Windows staging web and combined queue worker on localhost:8188; restored web on localhost:8288 during recovery validation. Original development services/data were not replaced. Both staging environments were safely stopped using recorded-PID/actual-command-line guards; the primary staging web/worker were then successfully restarted for review. The restored web is now stopped, its files retained. Scheduler heartbeat was executed with isolated schedule:run; no persistent Linux scheduler is claimed. Local email capture used private log transport; Mailpit was not run.

Main test DB: `.tools/stage8-local-windows/database.sqlite`. Restore DB: `.tools/stage8-local-restored/database.sqlite`. Both have separate generated key/configuration/private storage/runtime/session-cookie/cache/queue/log files. Bootstrap refuses unmarked roots and forces safe isolated targets even if shell DB/cache/queue/mail variables are inherited. Test fixtures represent three synthetic clients and existing roles, with 50 additional synthetic session users for load tests. No real client data was copied into these environments.

## Final result matrix

| Check | Status | Evidence |
| --- | --- | --- |
| Laravel regression/security/queue/PDF suite | PASS | 123 tests passed, 1 MySQL test skipped, 1,416 assertions; zero final failures |
| React/Vitest | PASS | 70 tests, seven files; existing dashboard polling act-environment warnings remain non-failing |
| TypeScript and compiled production frontend | PASS | noEmit/type checks and Vite build; same-origin staging build explicitly overrides development API URL |
| Python extraction/normalization suite | PASS | 28 tests, including actual available OCR, partial/failing extraction and retained evidence |
| Fresh isolated DB migrations | PASS | All existing migrations applied to a new synthetic SQLite DB; no resets of development |
| Docker/WSL/Linux/MySQL 8 | BLOCKED | Unavailable tooling/runtime; focused MySQL test safely skipped, no compatibility claim |
| Complete synthetic application workflow | PASS | Real HTTP login/CSRF, client/project/report creation, five uploads, queue extraction/normalization, evidence-bound fixture review/commentary, normal status transitions, publication, sharing/revocation and retained history |
| Multi-client/role security | PASS | Three-client backend matrix plus actual manipulated foreign HTTP IDs/download/retry denials; manager/executive/viewer health/admin restrictions; inactive/archive/token cases in suite |
| Authenticated desktop/mobile browser | PASS | Real same-origin user/health/delivery at 1440/390 px, no horizontal overflow/page errors, manager navigation/page denial, origin-only referrers omit synthetic query token |
| Five-file workflow | PASS | Queue extraction observed complete in 8.921 s; statuses honestly include needs_review; normalization observed 1.776 s on first run, 22 business records and retained source references |
| Ten simultaneous upload requests | PASS | Ten distinct documents, 1,009 keyword records, extraction observed 6.882 s; duplicate upload rejected |
| Supported format/size matrix | PASS | Ten actual generated inputs: native/scanned PDF, DOCX, XLSX, CSV, PNG, JPEG/JPG, 1,000-keyword CSV and 80-page PDF; explicit page-cap rejection never called success |
| Seven real private PDF exports | PASS | Preview 7 pages, audit 41, selected overview 2, synthetic published 7, two further previews 7 each, large 1,009-keyword preview 70; checksums/text/watermarks/images/headers/page numbers checked |
| Concurrent offline PDF rendering | PASS | Two actual renderer/Chromium processes completed together in 2.248 s; separate from queue-worker concurrency claims |
| Worker restart/persistence | PASS | Upload remained queued while worker stopped, survived web replacement, processed after worker restart; idle worker crash/replacement tested |
| Mid-job crash / broker or MySQL outage / multi-worker SQL contention | NOT TESTED | Existing failure/retry/idempotency regressions pass, but these host-level scenarios were not simulated |
| Local runtime health | PASS | Actual authenticated HTTP DB/private storage/Python/OCR/offline A4 PDF probes succeeded; observed worker heartbeats and scheduled heartbeat; missing/stale/fault states tested |
| Private email capture | PASS | Synthetic password reset captured/used; invitation password-setup captured; no external delivery or raw token output |
| Real SMTP / public HTTPS / secure-cookie proxy topology | BLOCKED | No host/domain chosen; local fallback HTTP is not production TLS; Compose self-signed TLS not executed |
| Controlled application load | PASS | 10/25/50 distinct synthetic users, 30/75/150 dashboard requests, zero HTTP errors; p95 2.23/5.47/11.27 s, not production capacity approval |
| Synthetic backup/recovery | PASS | 64 archived files hash-verified, SQLite integrity and FK checks pass; restored real HTTP authentication/history/private PDF access; 1.282 s preparation, not production RTO |
| Encrypted/offsite MySQL recovery | BLOCKED | Synthetic archive stayed local; actual encrypted/offsite production workflow unverified |
| Dependency advisory checks | PASS | Composer, frontend npm and production renderer npm feeds zero findings; PyPI version metadata 32 runtime packages, zero findings/unverified versions |
| Source package scan / syntax | PASS | Allowlist/pattern scan clean; Python compile, Node syntax, PHP lint and shell syntax checks passed |
| Full Git history/staged secret audit | NOT TESTED | No .git, branch or index exists; no init/stage/push performed |
| Development data preservation | PASS | All 7,823 rows retained and 821 files unchanged; two sign-in timestamp fields advanced on the existing account, with credentials/role unchanged |

PASS means the described scoped check completed, not that untested hosting or every possible security attack is covered. Read the individual reports for measurement and fixture limitations.

## Defects discovered and corrections

1. Same-origin browser authentication failed despite a valid cookie: frontend no-referrer metadata suppressed both identifying headers needed by Sanctum on GET. Origin-only referrer policy now preserves first-party identification without exposing URL paths/reset tokens. Web templates were aligned; real desktop/mobile and query-token referrer checks pass.
2. Chromium HTTP health failed while CLI/PDF jobs worked: the Windows web subprocess lacked OS temporary-directory context and attempted an undefined temp path. Added ProcessEnvironment with explicit temp/OS context to Python/PDF/probe execution. Actual authenticated HTTP probe now reports Healthy only after successful rendering; failed/missing runtimes still report accurately. Temporary debugging instrumentation was removed.
3. A test hardcoded the Windows Python path; it now restores the configured executable. Optional TEST_REFERENCE_DIR supports fully generated synthetic fixtures in a future container run, avoiding real document copying.
4. Scheduler health was absent; an expiring scheduled cache heartbeat now distinguishes observed/stale/missing/unavailable states. Staging can disable retention cleanup without changing normal production policy or any publication rule.
5. Local staging tools now isolate inherited shell configuration, guard active PIDs/ports and support safe stop/restart without deleting data. Source packaging now includes .dockerignore so its private-context exclusions travel with the release.

Early harness attempts correctly hit existing AI verification and revocation-confirmation guards; fixtures were updated with an explicit synthetic absence decision/confirmation, not weaker rules. The format-cap harness was corrected to expect hard rejection of an oversized-page document, and PDF inspection was corrected to honor selected sections. Those were test-harness corrections, not approvals or source edits on real data. The optional psutil-tool download stalled and was stopped; no completed psutil scan/memory measurement is claimed.

## Hann's preservation acceptance

Read-only acceptance: Draft revision 1, all five originals present, 1,205 normalized records (21 approved, 1,184 pending), 218 business observations, five human verifications, 20 unresolved blockers. Publication eligibility remains false. Zero publications/share links; ten prior PDF exports and three retained failed jobs still present. All client/report/evidence/decision/history fields and files matched. The strict final comparison additionally observed last_login_at and its matching updated_at advancing on the existing account from 04:38:04 to 09:06:06 UTC during the session; its password hash, role and active status remain identical. This intervening authentication activity was retained, not overwritten. The original baseline is preserved; a separate final comparison records this exact exception and still rejects every other difference. Stage 8 created no new Hann's export or review decision; every new workflow artifact belongs to synthetic staging.

## Reproducibility and artifact handling

Start/stop/test instructions: STAGE_8_LOCAL_STAGING.md and deploy/staging-local/README.md. MySQL opt-in and precise disposable schema instructions: STAGE_8_MYSQL_COMPATIBILITY.md. Security/performance/PDF/recovery/readiness reports are siblings in docs. All eight requested Stage 8 documents are supplied, plus a baseline inventory.

Private evidence lives under `.tools/stage8-local-windows/evidence`, `.tools/stage8-local-restored`, and `.tools/stage8-*` baseline/advisory/acceptance files. It includes generated credentials/reset logs and must never enter source control. The hosting-neutral source ZIP is `.tools/releases/pentagon-stage8-source-20261019.zip`; source-only allowlist, no environments/databases/originals/PDFs/dependencies/local tools. Its SHA-256 manifest is checked during packaging. Compiled staging assets are in frontend/dist and deliberately outside the source ZIP. No new application migration was needed in Stage 8.

Manual next actions: install/approve Docker/WSL or provide an isolated Linux test host; build/run the prepared images; execute real MySQL/constraint/concurrency/recovery tests; verify Linux subprocess/browser isolation, TLS/secure cookies/proxy, Mailpit/SMTP, supervision/stuck-job fault handling, aggregate memory/load and encrypted offsite recovery. Resolve those gates and select hosting before requesting explicit production deployment approval. Shared cPanel remains unsuitable unless the provider proves all long-process/runtime/private-storage capabilities; server-admin cPanel/WHM, VPS and cloud servers remain conditional options. No final provider is selected.

## Added or modified source/configuration files

There is no Git history for a commit diff. Generated runtime/evidence/build files are excluded from this inventory.

- `.dockerignore`
- `README.md`
- `backend/app/Jobs/GenerateReportPdf.php`
- `backend/app/Services/ProcessEnvironment.php`
- `backend/app/Services/PythonDocumentProcessor.php`
- `backend/app/Services/SystemHealth.php`
- `backend/pdf-renderer/health.mjs`
- `backend/routes/console.php`
- `backend/tests/Feature/DocumentTest.php`
- `backend/tests/Feature/Stage8MonitoringTest.php`
- `deploy/nginx/pentagon.conf.example`
- `deploy/staging-local/.env.example`
- `deploy/staging-local/Dockerfile.backend`
- `deploy/staging-local/Dockerfile.frontend`
- `deploy/staging-local/Dockerfile.worker`
- `deploy/staging-local/README.md`
- `deploy/staging-local/docker-compose.yml`
- `deploy/staging-local/entrypoint.sh`
- `deploy/staging-local/nginx.conf`
- `deploy/staging-local/php.ini`
- `deploy/staging-local/scheduler.sh`
- `docs/STAGE_8_BACKUP_RESTORE.md`
- `docs/STAGE_8_BASELINE.md`
- `docs/STAGE_8_DEPLOYMENT_READINESS.md`
- `docs/STAGE_8_LOCAL_STAGING.md`
- `docs/STAGE_8_MYSQL_COMPATIBILITY.md`
- `docs/STAGE_8_PDF_VERIFICATION.md`
- `docs/STAGE_8_PERFORMANCE_REPORT.md`
- `docs/STAGE_8_SECURITY_REPORT.md`
- `docs/STAGE_8_TEST_RESULTS.md`
- `frontend/index.html`
- `ops/package_release.py`
- `ops/staging/backup_restore.py`
- `ops/staging/batch-stress.mjs`
- `ops/staging/bootstrap.php`
- `ops/staging/browser-check.mjs`
- `ops/staging/console.php`
- `ops/staging/container-health.php`
- `ops/staging/email-check.mjs`
- `ops/staging/fixture.php`
- `ops/staging/format_matrix.py`
- `ops/staging/inspect_pdfs.py`
- `ops/staging/load_test.py`
- `ops/staging/measure-render.mjs`
- `ops/staging/measure_pdfs.py`
- `ops/staging/process_control.py`
- `ops/staging/queue-persistence.mjs`
- `ops/staging/router.php`
- `ops/staging/scheduler-container.sh`
- `ops/staging/synthetic_files.py`
- `ops/staging/workflow.mjs`
- `ops/staging_setup.py`
