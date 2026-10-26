# Monthly report automation and client portal verification

Verified locally on 19 October 2026 in the existing Laravel/React Windows workspace. No production deployment, GitHub push, Docker, WSL or new server software was used. Existing PHP, Node, Python, Edge and the configured SQLite database were reused.

## Open the application

| Purpose | Actual local URL |
|---|---|
| SEO team login | http://127.0.0.1:5173/login |
| Monthly Reports | http://127.0.0.1:5173/monthly-reports |
| Create Monthly Report | http://127.0.0.1:5173/monthly-reports/new |
| Hann's preselected monthly creation | http://127.0.0.1:5173/monthly-reports/new?project=01m51mtexy2cda3etrd7ge03t0 |
| Client login | http://127.0.0.1:5173/client-login |
| Client My Reports / authorized months | http://127.0.0.1:5173/portal |
| Client account settings | http://127.0.0.1:5173/portal/settings |
| Hann's existing private Draft | http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/preview |

Sign in using existing accounts. The main database still has the original Super Admin and Hann's Draft. The synthetic administrator, client accounts, published reports and test links exist only in isolated test databases; they were not added to the real client database. The actual client portal shows an empty state until an authorized administrator publishes and assigns a report to a Client Viewer.

The existing services on ports 8000 and 5173 are left running. If stopped, use separate PowerShell terminals:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host=127.0.0.1 --port=5173
```

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:work database --queue=documents,exports --sleep=2 --tries=1 --timeout=960 --memory=512
```

One additive migration was applied: `2026_10_19_120000_create_report_generations_table`. There was no reset, reseed or business-data migration in the real database.

## SEO team browser steps

1. Sign in. Create/select a client under Clients and create/select an SEO project.
2. Choose Monthly Reports > Create Monthly Report. Filter by client, select an authorized active project and select month/year. The existing project report list also provides this link.
3. Choose Create and upload documents. The server creates one Draft for that project/month and rejects duplicate date ranges. If a month already exists, open it from Monthly Reports.
4. Select the 5-6 PDF sources together in Monthly source PDFs and click Generate SEO Report. The files upload as one multipart batch. Existing server limits, file signatures, MIME checks and duplicate-checksum protection apply.
5. The coordinator waits for extraction, classifies the documents and runs the existing normalizer. The page shows real document progress, failures, classifications and the saved generation attempt. It polls while work is active.
6. Retry failed/stale source extraction when appropriate, then Retry report generation. Previous originals, extraction attempts and generation attempts remain retained. Stale worker attempts expose retry controls; interrupted generation also closes its own normalization attempt.
7. Open Preview report dashboard. Pending records are visible with provisional labels; approvals are not required to generate an internal Draft.
8. Export Draft PDF from the preview, or use the existing Export & delivery page. Drafts remain private and watermarked.
9. Use Data Review to inspect/correct source values, verify evidence, resolve warnings and make explicit review decisions. Bulk review remains subject to the existing eligibility checks. Save and explicitly approve the current source-bound commentary.
10. Follow Needs Review > Approved, then explicitly Publish Report in Publish and Share. The server checks readiness again and freezes an immutable snapshot. Missing verification, approvals, sources, sections, required metrics or commentary continue to block publication.

## Client invitation and sharing browser steps

1. In Team Management create an active account with role Client Viewer. The system generates a random inaccessible initial password; the client sets their own password through the expiring password-setup invitation. Existing password reset, validation and rate limits are reused.
2. On a published reporting period, select the permitted Client Viewer, a future expiry and the desired PDF permission, and save the assignment. No Draft account assignment is accepted.
3. In Publish and Share create an expiring link for the publication. Copy it using the existing one-time link UI. Merely possessing its URL never grants access: the recipient must log in and have an active assignment to that exact report.
4. In a separate/private browser open the link. The app redirects to client login, retaining the fragment token through login. The client sees the published snapshot and can return to My Reports to choose authorized projects and reporting months.
5. The client dashboard includes real KPI cards, channel/device/keyword/activity/event charts, sortable/searchable/paginated tables, original screenshot enlargement, approved narrative and source attribution. It uses the existing Pentagon report styling and contains no administration, review or publishing controls.
6. My Reports allows comparison of two assigned published months in the same project. It displays source-period metric values and absolute differences; missing/conflicting values are Not comparable, and CTR differences are percentage points. It does not infer causes or normalize unequal source periods.
7. PDF download appears only with permission. The server independently checks both the link and account download permissions, current publication availability and that the artifact is a complete published PDF. Draft and partial/internal audit exports cannot be downloaded by clients.
8. Revoke a link to stop that URL. Remove the report assignment to stop account access to that report through every account URL. These are distinct controls. An already rendered page cannot be erased from a recipient's memory; refresh/new requests and all downloads enforce revocation immediately.
9. Client users trying internal URLs are redirected to My Reports. Backend source, generation, review, export-management and team endpoints independently reject unauthorized access. Account Settings and Logout remain available in the client header.

## Invitation delivery: local configuration

The existing installation uses `MAIL_MAILER=log`; external SMTP delivery is not configured. Local invitations now go to **backend/storage/logs/mail.log**, a dedicated private log channel. This fixes the previous warning-level application log discarding password-setup emails. The invitation API and UI explicitly identify local-log delivery rather than claiming an external email arrived.

For local testing, an authorized administrator can retrieve the setup URL from that private mail log and open it in the separate client browser. Do not publish the log or expose a token through an application endpoint. Setup tokens are hashed in the database, expire normally, and are consumed by password setup.

Real clients receiving email outside this machine require an SMTP provider, sender and privately configured credentials. No SMTP service was installed and no real email was sent. The user was asked which delivery provider to use; local mail-log delivery was retained pending that information. Configure the existing Laravel SMTP mailer when settings are supplied, then verify delivery to an authorized test recipient. The report workflow and security checks do not depend on external mail delivery.

## Verification results

- Laravel: **129 passed, 1 skipped, 1,744 assertions**. The skipped opt-in MySQL 8 test requires a separately configured test database; this task retains the existing SQLite setup.
- React: **91 passed in 10 files**.
- Python extraction/normalization: **28 passed**.
- TypeScript and same-origin production Vite build: **passed**. Large reporting/chart code remains in separate lazy-loaded chunks.
- SQLite integrity: **ok**.
- Live-data preservation: **7,828 original business/history rows and 823 original files preserved with zero field/hash differences**. The before-change SQLite backup and baseline are private under .tools/. Existing failed jobs were not cleared or bulk-retried.

The backend integration test performs the complete workflow from fresh synthetic data: creates a client/project, creates August, uploads six real PDFs, extracts/classifies/normalizes, previews without approvals, creates September with six different sources, checks August unchanged, exports a real Draft PDF, demonstrates publication blocking, explicitly reviews synthetic records/commentary, publishes eligible snapshots, invites a Client Viewer, sets their password, logs in, checks assignment and cross-client isolation, tests PDF permission, expiration and revocation. Review automation in the tests is confined to disposable synthetic fixtures; it is not an application feature and does not touch Hann's.

| Synthetic checkpoint | August | September |
|---|---:|---:|
| Users | 812 | 937 |
| New users | 701 | 820 |
| GSC clicks | 91 | 118 |
| GSC impressions | 2,100 | 2,600 |
| Reported CTR | 4.33% | 4.54% |
| Reported events | 13 | 19 |

Both sets include traffic channels, devices, a page-group keyword ranking, retained duplicate activities, original AI image evidence and event names/counts. The application contains no hardcoded Hann's metrics.

A real Edge/Playwright scenario also exercised the existing React UI against an isolated instance of the same Laravel code and database queue. It used real HTTP/API responses and real extraction/PDF jobs, not mocked report responses. The browser used the existing frontend and forwarded API requests to the temporary loopback test backend; multipart forwarding supplied the exact selected fixture bytes because Chromium interception omits uploaded file bytes. The scenario was resumed after test setup fixes and passed all fifteen checks, including actual password setup/login, source image dialog, month comparison, enabled/disabled PDF permission, refreshed revoked-link denial, account revocation and logout. Mobile viewport and document widths both measured 390 pixels, with zero browser JavaScript errors. The temporary test services are stopped after verification.

Private evidence: .tools/monthly-portal/browser-results.json, pdf-results.json, client-desktop.png, client-mobile.png, synthetic-draft.png, synthetic-client.pdf, worker logs and isolated SQLite database. These are not public application routes and are ignored by Git.

Issues found and resolved during verification:

- An old frontend regression expected Client Viewers inside internal project navigation; it now verifies redirect to the dedicated portal.
- Locally generated invitations were discarded at the application's warning log threshold; dedicated private mail logging was added and covered by a real mail-transport test.
- A concurrency test intermittently raced while enabling SQLite WAL on two child connections; its disposable database now establishes WAL before launching the publishers. The assertion remains exactly one HTTP 201, one HTTP 409 and one immutable publication.
- Test proxy upload/storage and mail-log parsing problems were corrected without weakening validation or changing original data. Browser automation now respects existing API rate limits and refreshes after revocation.
- Existing React polling tests emit act-environment notices but pass. No unresolved application test failure remains.

## Modified/new files

Backend:

- app/Jobs/GenerateMonthlyReport.php
- app/Models/ReportGeneration.php
- app/Http/Controllers/MonthlyReportController.php
- app/Http/Controllers/ClientReportController.php
- app/Http/Controllers/TeamController.php
- routes/api.php
- config/mail.php and config/logging.php
- database/migrations/2026_10_19_120000_create_report_generations_table.php
- tests/Feature/MonthlyAutomationTest.php and tests/Feature/ReportDeliveryTest.php
- tests/fixtures/monthly/: twelve synthetic PDFs and README

Frontend:

- src/pages/monthly-reports.tsx
- src/pages/client-portal.tsx
- src/components/published-dashboard.tsx
- src/pages/client-report.tsx
- src/App.tsx
- src/pages/auth-pages.tsx
- src/components/layout.tsx
- src/pages/reports.tsx
- src/pages/report-delivery.tsx
- src/styles.css
- src/test/monthly-portal.test.tsx, portal-routing.test.tsx and app.test.tsx

Root .gitignore permits only the synthetic monthly fixture PDFs through the general PDF exclusion. Existing originals and runtime artifacts remain ignored. Operational verification helpers are private under .tools/monthly-portal/.

## Remaining limits

External invitation email needs SMTP configuration. Hann's existing report remains a private Draft with its original unresolved review requirements; it was not approved, published or assigned to a test client. No production deployment or database engine change was performed. Synthetic test accounts are not usable in the main application.
