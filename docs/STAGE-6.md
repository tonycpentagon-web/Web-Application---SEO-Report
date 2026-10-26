# Stage 6 — secure report delivery

Implemented on 19 October 2026. Existing authentication, source review and workflow states are retained. Hann's Pharmacy August 2026 remains **Draft, revision 1**, August 1–31. No account, password, report, upload, approval, verification marker or source reference was changed.

## Features and workflow

Open a reporting period → **Open Report Delivery**. This page provides Publication Readiness, commentary approval, Draft/Published PDF exports, selected sections, publication history, expiring share links, revocation, export progress/retry/download and publication/access audit history. Existing reporting-period account sharing now includes expiration and PDF permission. Existing archive actions retain their confirmation dialog.

Use the established transitions:

`Draft → Needs Review → Approved → Published → Archived`

Approved is the existing ready-to-publish state; no competing status system was added. To publish:

1. In Draft, inspect original evidence, resolve blocking warnings, record human verification and explicitly approve the required business observations through Data Review.
2. Save all six executive commentary sections in the Draft Dashboard Summary tab. Commentary is never generated or approved automatically.
3. In Report Delivery, explicitly approve the current source-bound commentary.
4. Move the report through Needs Review and Approved on the reporting-period page.
5. In Report Delivery, confirm Publish Report. This freezes a new publication version, records the publisher and queues the full published PDF. Publication does not itself create shares.
6. Create an expiring link or grant selected active Client Viewer accounts access with an expiration date. Enable PDF download only when intended.

The old status endpoint cannot publish directly. It returns HTTP 409 directing callers to the publication endpoint, so no alternate path can publish without a snapshot/confirmation. Existing sharing endpoints also require a real publication, current readiness, Published status and a future expiry. Clearing all account grants remains permitted when blocked.

Readiness includes total/approved/pending/rejected active normalized records, business observations, current human verification, exact blocking warnings, missing source files, required sections, required metrics, source freshness and commentary approval. Existing Stage 5 eligibility remains the authority for record approval, source verification and warning resolutions. Pending unclassified extraction context is counted in total/pending but is not reinterpreted as publishable business content.

Required metrics are `ga4.total_users`, `gsc.clicks`, `gsc.impressions`. Required business sections are website analytics, search console, keywords, SEO activities, AI visibility and key events. These requirements are centralized in `backend/config/delivery.php`; they are deliberately explicit rather than inferred from whatever data happens to be present. No unsupported values are substituted for missing metrics/sections.

## Browser routes

| Route | Access |
| --- | --- |
| `/projects/:id/reports/:reportId/delivery` | Authorized internal report workers; management actions require project manager/admin permission |
| `/projects/:id/reports/:reportId/publications/:publicationId` | Authorized internal historical snapshot view |
| `/projects/:id/reports/:reportId/client` | Active selected Client Viewer account, unexpired grant, Published and eligible report |
| `/shared-report#<random-token>` | Valid unexpired/unrevoked link, Published and eligible report |
| `/projects/:id/reports/:reportId/dashboard` | Existing internal Draft Dashboard |

Hann's Pharmacy delivery:

http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/delivery

The client screen has its own branded layout, approved KPIs, commentary, source-supported charts, tabbed section data, evidence images and methodology. It does not mount the admin sidebar or internal review controls. Internal source originals are never linked through it.

## APIs

Authenticated routes use the existing Sanctum/active-account/API-throttle middleware. All internal delivery routes start with `/api/projects/{project}/reports/{report}/delivery`:

| Method and suffix | Behavior |
| --- | --- |
| `GET /` | Readiness, publication versions, export statuses, link metadata, selected viewers and audit history |
| `POST /commentary-approval` | Requires `revision`, `reason` and accepted `confirmed`; active Draft and manager permission |
| `POST /publish` | Requires current report `version` and accepted `confirmed`; manager permission, Approved status and complete readiness |
| `GET /publications/{publication}` | Immutable historical snapshot belonging to this report |
| `POST /exports` | `type`: draft/published, optional selected `sections`, and `publication_id` for published exports; HTTP 202 |
| `POST /exports/{export}/retry` | Requeues only failed exports |
| `GET /exports/{export}/download` | Authenticated private artifact download; report ownership enforced |
| `POST /shares` | `publication_id`, `label`, future `expires_at` within one year, boolean `allow_download`; plaintext token returned once |
| `POST /shares/{share}/revoke` | Requires accepted `confirmed`; manager permission |

`GET /api/projects/{project}/reports/{report}/client-report` serves the latest publication to a selected unexpired account grant. `?pdf=1` downloads its full published PDF only when permitted.

Public `GET /api/shared-report` accepts a 64-character hexadecimal token only in `X-Report-Token`. `?pdf=1` requests its full published PDF. URL query tokens are ignored. No public route accepts a report ID as authorization. Clients cannot request Draft or selected-section artifacts through either client endpoint. A published PDF must have completed generation before the client can download it; unavailable artifacts return 404.

## Security and immutable history

- Tokens contain 32 cryptographically random bytes. Only SHA-256 hashes are stored, with a unique index. Tokens are returned once; link-management responses exclude both token and hash.
- The share URL puts the token in a browser fragment. Fragments are not sent to frontend servers or normal HTTP access logs. The frontend sends the token in an API header, never a query string. Do not configure proxies/APM/error collectors to log request headers or capture fragment URLs. HTTPS is required in production.
- Public endpoints are limited to 30 requests/minute per IP; export requests/retries to 6/minute per authenticated user, alongside existing API limits.
- Internal routes scope project → report → publication/export/link before granting access. Manager actions use existing project-management authorization. A Client Viewer cannot use internal delivery or original-document endpoints.
- Account grants require an explicit future expiry. Account project/report listings also filter expired grants. Legacy grants without expiry are retained in the database but grant no client access until intentionally updated.
- Client snapshots use a field whitelist. Internal warnings, verification reasons, review controls, source paths, quotes, raw extraction output, credentials and internal notes are excluded. Approved AI screenshots are embedded as raster data; private originals are not exposed. Raster images are restricted to PNG/JPEG/WebP and 12 MB each; unavailable images are identified rather than fabricated.
- Shared/API responses and downloads use private/no-store and noindex/nofollow headers. The SPA also uses robots/referrer meta tags. A fragment is not an authorization substitute: every API request is independently validated.
- Each publication stores report/project ownership, reporting dates, monotonically increasing publication version, publisher/time, approved values and narratives, section records, source filenames/locations/source dates and embedded evidence/branding. A SHA-256 snapshot checksum detects changed snapshot bytes. Application models reject publication updates/deletes. This is application-level immutability, not protection against a database administrator altering both data and checksum.
- The publication transaction locks the report and current sources, rechecks readiness and report revision, inserts the snapshot/version, updates status and records the audit event. A database uniqueness constraint on report/version provides additional protection. A two-process SQLite integration test confirms one concurrent publisher succeeds and the other receives 409.
- Export payloads freeze request-time Draft data or the selected publication snapshot. Published exports do not read later working values. Artifact IDs, generation timestamps, checksums and sizes preserve the PDF artifact version. Duplicate job delivery does not overwrite completed files.
- Delivery audit records contain actor/action/publication/export/share IDs and relevant metadata, never bearer tokens, token URLs, hashes or request headers.

### Suspension and revocation policy

Client access is **suspended on every request** if the working report is not Published, its client/project is archived, its sources are unavailable/stale, verification/approval or validation is no longer eligible, commentary is outdated, or the stored snapshot checksum fails. There is no cached readiness grace period.

Suspension does not rewrite historical snapshots or automatically revoke token records. If an authorized manager restores eligibility and publishes again, still-unexpired/unrevoked links can work again. Explicit revocation is immediate and permanent for that token. Expiry always remains enforced. Links stay pinned to the publication chosen when created; account grants are report-level and show the latest publication. Administrators can inspect historical publications internally even while client access is suspended.

## PDF renderer and layout

Playwright/Chromium was chosen over Dompdf/wkhtmltopdf for modern print CSS, SVG, embedded screenshots, repeated table headers and page counters. It requires no paid service. Dompdf would reduce browser dependencies but needs layout compromises; wkhtmltopdf uses an older rendering engine. The renderer is a local Node subprocess, not a remote browser service.

Official references: [Playwright PDF API](https://playwright.dev/docs/api/class-page#page-pdf), [browser installation and OS dependencies](https://playwright.dev/docs/browsers).

The Blade template outputs A4 cover, client/Pentagon branding, report period, ISO generation timestamp, headers, page numbers, KPI cards, source-supported SVG bars, tables, screenshots and methodology. Table bodies group each observation with its source/status row; headers repeat on page breaks. Images preserve proportions. All values/text are escaped. The browser context has JavaScript and service workers disabled and external/local-file requests blocked. Only embedded raster assets are rendered; it never visits a user-controlled report URL or fetches remote charts.

Draft exports are permitted despite readiness blockers, do not change report status, show DRAFT on every page, identify provisional/unverified rows, retain actual source periods and include validation warnings. They explicitly state “Internal Review – Not for Client Distribution.” Partial exports are supported; retain Sources and Methodology when reviewing validation details. No client endpoint serves a Draft export.

## Start this checkout on Windows

Do not reset the database, overwrite `.env`, create default credentials or run seeders. Dependencies already exist in this checkout. From the repository root:

```powershell
Set-Location backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan documents:doctor
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Separate terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

Use **one combined worker** for SQLite, replacing the older documents-only worker. `queue:restart` gracefully stops older workers for this application; do not start duplicate workers:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:restart
& ..\.tools\php\php.exe artisan queue:work --queue=documents,exports --tries=1 --timeout=960 --memory=512
```

A combined worker is running for this checkout. Document jobs retain their 960-second timeout; PDF jobs specify 240 seconds. PDF subprocess timeout is 180 seconds. The existing database queue retry_after is 1200 seconds. Long document jobs can delay PDFs; production can separate queues with MySQL.

On a fresh renderer installation:

```powershell
Set-Location backend\pdf-renderer
npm.cmd ci
npx.cmd playwright install chromium
```

Local configuration uses installed Microsoft Edge through `PDF_CHROMIUM_PATH="C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"`. This path is only local environment configuration, never a production code assumption. Leave it unset to use Playwright-managed Chromium.

## Linux production dependencies and operation

- PHP 8.2+ with Composer's Laravel requirements, PDO SQLite/MySQL as appropriate, fileinfo, mbstring, XML/DOM and OpenSSL. This checkout was verified with PHP 8.4.26; XAMPP PHP 8.0 is incompatible. `proc_open`/subprocess execution must be enabled. Set PHP memory_limit to at least 512M for large source/snapshot payloads.
- Node 22.14+ is a verified baseline (local v22.14.0). Run `npm ci` separately in frontend and backend/pdf-renderer; deploy renderer production dependencies and lockfile. Build frontend with `npm run build`.
- As the appropriate deployment user, run `npx playwright install --with-deps chromium` in backend/pdf-renderer. OS-library installation may need administrator/root privileges. Ensure the queue service user can access the managed browser cache (`PLAYWRIGHT_BROWSERS_PATH` can provide a shared installation). Install appropriate fonts for source languages, e.g. Liberation/Noto.
- Set `PDF_NODE_BINARY` to Node or its absolute binary path; leave `PDF_CHROMIUM_PATH` unset for managed Chromium. Configure `PDF_TIMEOUT=180` and `PDF_DRAFT_RETENTION_DAYS=30`. Keep renderer timeout below the PDF job's 240-second limit. Set `FRONTEND_URL`, `APP_URL`, trusted session/Sanctum/CORS domains and secure cookies correctly; use HTTPS, APP_DEBUG=false, a nonempty application key and existing account provisioning. Never embed API secrets in Vite variables.
- SQLite is supported for development with IMMEDIATE transactions and one combined worker. MySQL 8 is the production target; apply normal Laravel migrations, use InnoDB/utf8mb4 and configure credentials privately. SQLite tests pass; a MySQL server was not available for execution testing.
- Serve only backend/public and frontend/dist. Keep `.env`, `.tools`, originals, source code and storage/app/private outside public roots. Grant the PHP/queue user write access to private storage and storage/framework/logs/bootstrap/cache. Do not symlink private exports into public storage. Back up the database and private storage together.
- Run long-lived queue processes under Supervisor/systemd and restart them on deploy. A MySQL Supervisor example follows; replace paths/user/PHP binary to match the server:

```ini
[program:insight-exports]
command=/usr/bin/php /srv/insight/backend/artisan queue:work --queue=exports --tries=1 --timeout=240 --memory=512 --sleep=3
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1200
redirect_stderr=true
stdout_logfile=/srv/insight/backend/storage/logs/exports-worker.log

[program:insight-documents]
command=/usr/bin/php /srv/insight/backend/artisan queue:work --queue=documents --tries=1 --timeout=960 --memory=512 --sleep=3
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1200
redirect_stderr=true
stdout_logfile=/srv/insight/backend/storage/logs/documents-worker.log
```

- Run Laravel's scheduler every minute: `* * * * * cd /srv/insight/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1`. Daily `reports:cleanup-exports` expires old Draft payloads/artifacts, preserves export metadata and all published artifacts, and identifies rendering jobs abandoned for over 30 minutes. Inspect first with `php artisan reports:cleanup-exports --dry-run`. Failed jobs can be retried from Report Delivery. Published artifacts and snapshots have no automatic deletion policy.
- Monitor failed jobs, private disk usage, memory and queue latency. Queue retries are explicit so repeat failures do not silently loop. Retry a failed full published PDF from its export record; clients receive no provisional replacement file. Source availability checks on each access intentionally trade throughput for fail-closed behavior; benchmark before scaling to a large client population.

## Verification and acceptance results

| Check | Result |
| --- | --- |
| Full Laravel suite | 104 passed, 1,052 assertions |
| Full React suite | 64 passed across six files |
| TypeScript | Passed |
| Final frontend production build | Passed; 2,271 modules transformed |
| Real Draft/Published Chromium rendering in isolated tests | Passed |
| Simultaneous publication using two independent PHP processes and disposable SQLite | One 201, one 409; exactly one publication |
| Expired/revoked/invalid links, rate limiting, Draft denial, download permission, cross-user scope, confirmation/manager checks | Passed |
| Snapshot preservation, second publication, retry and retention | Passed |
| Desktop 1440px / mobile 390px browser checks | Passed, zero runtime exceptions, no page overflow |
| Existing database rows and original/reference SHA-256 preservation | Passed |

The real Hann's Pharmacy Draft export is available in Report Delivery → PDF Export History. Acceptance artifact: `01m598gybmtxvgkzfpdrctv891`, 696,170 bytes, 46 A4 pages. It retains all 218 business observations, all 70 keyword rows, nine evidence screenshots, all nine sections, expected KPI values (including 4,515 users, 2,950 clicks and 432,736 impressions), source periods including August 30 coverage, source attribution and DRAFT on every page. Automated PDF extraction found no missing keywords or text outside page margins. Representative cover, KPI/chart/table and AI-evidence pages were rendered and visually inspected. The long Draft is intentional: provisional labels, provenance, full observations and warnings remain available. The current source dataset has no client logo; the client name and Pentagon identity appear, and the logo path is supported when configured.

Current live readiness: **21 approved / 1,184 pending / 0 rejected** among 1,205 active normalized records; **218 business observations**, **5 current human verifications**, **213 provisional business observations**, **20 unresolved blocking warnings**. Executive commentary is not saved/approved. Publish and new sharing remain blocked. No Hann's Pharmacy approval/verification/commentary or publication was created for this work. One initial failed PDF attempt and an earlier successful layout iteration remain in export history; final acceptance uses the artifact listed above. These new export/audit/queue records are intentional additions.

Browser checks use the real read-only delivery response and an isolated Example Pharmacy client fixture, with API writes blocked. They do not claim a new real-user login/client delivery, and do not publish or artificially approve Hann's Pharmacy.

Run checks from backend:

```powershell
& ..\.tools\php\php.exe artisan test
& ..\.tools\php\php.exe artisan test --filter=ReportDeliveryTest
```

From frontend:

```powershell
npm.cmd test
npx.cmd tsc --noEmit
npm.cmd run build
```

The sandbox initially denied Vite/TypeScript output/cache files and automatic approval review was intermittently unavailable. Cache-free React tests also passed using `node node_modules/vitest/vitest.mjs run --configLoader runner --no-cache`; the final ordinary production build succeeded after execution access was granted. The existing dashboard polling test emits React act-environment warnings but passes.

For manual PDF testing: sign in with an existing internal account, open Hann's August Report Delivery, Export Draft PDF, wait for completed status, download and inspect cover/watermarks, all sections, source dates, tables and evidence. The report must remain Draft and Publish/Create Link disabled. No default credentials are provided.

For successful sharing tests, use the isolated automated fixture suite. On a separate test report, genuinely complete approvals/verification/commentary, publish with explicit confirmation, wait for its full PDF, create a short-lived link and test the fragment URL in a private window. Test PDF permission on/off, expiry, explicit revocation and archiving. Selected account access must not expose another period/project. Do not use Hann's Pharmacy as an approval shortcut.

Not executed here: Linux deployment/system supervisor, managed Linux Chromium and OS dependencies, MySQL 8 runtime testing, or load/performance testing. The Windows installed Edge path was verified. No paid services are needed. Stage 7 was not started.

Private verification outputs remain under ignored `.tools/stage6-*`: preservation baseline/backup, live readiness/export metadata, PDF inspection/screenshots and browser checks. Do not publish those files.

## File inventory

New migration: `backend/database/migrations/2026_10_19_000001_create_report_delivery.php` creates report_summary_approvals, report_publications, report_share_links, report_pdf_exports, report_delivery_events, and adds expires_at/allow_download to report_shares. It is additive and intentionally refuses destructive rollback.

New backend files:

- `app/Services/ReportDelivery.php`
- `app/Models/ReportPublication.php`, `app/Models/ReportPdfExport.php`
- `app/Http/Controllers/ReportDeliveryController.php`, `app/Http/Controllers/ClientReportController.php`
- `app/Http/Middleware/PrivateReportHeaders.php`
- `app/Jobs/GenerateReportPdf.php`
- `app/Console/Commands/CleanupReportExports.php`
- `config/delivery.php`
- `resources/views/reports/pdf.blade.php`
- `pdf-renderer/render.mjs`, `pdf-renderer/package.json`, `pdf-renderer/package-lock.json`
- `tests/Feature/ReportDeliveryTest.php`, `tests/fixtures/publish-concurrently.php`

Extended backend files:

- `app/Http/Controllers/ReportingPeriodController.php`: no direct status publication; secure expiring account grants.
- `app/Http/Controllers/ReportSummaryController.php`: exposes its existing source fingerprint for consistent commentary approval.
- `app/Models/ReportingPeriod.php`: account grant pivot fields.
- `app/Services/WorkspaceAccess.php`, `app/Services/WorkspaceView.php`: unexpired account filtering.
- `app/Providers/AppServiceProvider.php`, `config/cors.php`, `routes/api.php`, `routes/console.php`, `.env.example`.
- Existing BulkRecordReview/Workspace/Document/Normalization tests: account fixtures now include expirations and old publication-path expectations follow Stage 6 safeguards.

Frontend:

- New `src/pages/report-delivery.tsx`, `src/pages/client-report.tsx`, `src/test/report-delivery.test.tsx`.
- Extended `src/App.tsx`, `src/pages/reports.tsx`, `src/styles.css`, `index.html`.
- `README.md` links this guide and replaces the old documents-only SQLite worker command with the combined queue.
