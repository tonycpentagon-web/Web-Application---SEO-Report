# Local client test-data cleanup

Status: COMPLETED after explicit administrator confirmation. The local environment, signed backup and dry-run fingerprint were rechecked successfully before execution. 34,753 client-related database rows and 1,732 managed files were permanently deleted; no failures or pending files. The verified backups were preserved.

## Verified installation

- Application: `D:\xampp\htdocs\pentagon-insight-flow`
- Environment: `local`; on-disk APP_ENV checked independently.
- Actual database: `backend/database/database.sqlite` (SQLite, not MySQL).
- Backend: `http://127.0.0.1:8000`; frontend: `http://127.0.0.1:5173`.
- One active Super Admin account is preserved. Authentication, sessions, migrations, application settings, global logs, source code, branding and dependencies remain outside deletion scope.
- No remote environment, Docker, reset, truncate or schema deletion is used.

## Verified backup

Backup set: `D:\pentagon-insight-flow-backups\20261022-102130-01m5hj2mkk47gp56mmxc7g9rdg`

Includes a consistent complete SQLite database, `client-files.zip`, signed `manifest.json`, `verification.json`, and an independently restored `restore-check` directory. Database integrity passes; foreign-key violations: 0. All 1,732 managed files (102,476,413 bytes) were restored and checked by SHA-256. A second Python verification independently checked the database and ZIP CRC/checksums. The original uploads are included alongside extracted evidence, raw extraction results and generated PDFs. Backups are outside the web root. Preserve the existing `.env`/APP_KEY; encrypted values in the database need that key after restoration.

The earlier backup set ending `01m5hj03cdwzm96em35z770039` is also retained; the final set above uses normalized Windows archive keys and is the one bound to the reviewed confirmation.

## Reviewed deletion scope

3 clients; 5 projects, including 2 in Trash; 5 monthly reports; 6 publication snapshots; 8 share links; 25 original upload records; 1,732 managed files. No queued jobs are waiting; the 3 failed client report jobs are selected. All dependent source, metric, ranking, SEO activity, AI evidence, event, validation, revision, report-specific audit and delivery rows below are selected, including rows hidden by soft-delete scopes.

| Table | Selected rows |
|---|---:|
| report_share_links | 8 |
| report_shares | 0 |
| report_pdf_exports | 23 |
| report_delivery_events | 118 |
| report_manager_approvals | 0 |
| report_summary_approvals | 1 |
| record_validation_results | 11,792 |
| record_verifications | 6 |
| record_corrections | 42 |
| bulk_review_requests | 2 |
| validation_warnings | 362 |
| metric_source_references | 6,214 |
| report_source_scope_verifications | 6 |
| report_generations | 10 |
| normalized_records | 5,822 |
| report_sections | 40 |
| report_summary_revisions | 9 |
| report_validation_runs | 11 |
| normalization_runs | 14 |
| document_assets | 1,648 |
| document_extracted_content | 8,395 |
| document_extraction_runs | 38 |
| report_documents | 25 |
| project_activity_logs | 135 |
| report_publications | 6 |
| reporting_periods | 5 |
| project_memberships | 0 |
| projects | 5 |
| client_memberships | 0 |
| clients | 3 |
| retained_lifecycle_events | 10 |
| jobs | 0 |
| failed_jobs | 3 |
| private_file_removals | 0 |

All selected client/report namespaces are backed up: `report-documents/<ULID>/`, `exports/<ULID>/`, and `client-logos/`. Orphaned files within those managed namespaces are included. Shared health probes, `.gitignore`, default assets, engineering/design references and development review artifacts outside managed storage are preserved.

## Command

Run from `backend` using the existing bundled PHP executable:

```powershell
..\.tools\php\php.exe artisan client-data:cleanup --admin=1 --dry-run
..\.tools\php\php.exe artisan client-data:cleanup --admin=1 --prepare-backup --backup-root=D:\pentagon-insight-flow-backups
```

The command is dry-run by default. Global cleanup requires an active Super Admin, a verified local environment, loopback URL, expected SQLite database/private disk, a signed and restorable backup, an unchanged dataset fingerprint, idle processing and a separate exact confirmation. It refuses execution if the reviewed data or files change. Do not run backups concurrently with HTTP feature tests: backup briefly enters maintenance mode, as live execution also will.

**Executed after the user's explicit confirmation and successful rechecks:**

```powershell
..\.tools\php\php.exe artisan client-data:cleanup --admin=1 --execute --backup="D:\pentagon-insight-flow-backups\20261022-102130-01m5hj2mkk47gp56mmxc7g9rdg" --confirm="DELETE ALL CLIENT TEST DATA a6dbc9a2006d"
```

All four report job classes use a shared dataset lock. Backup/cleanup require an exclusive lock. The live idle worker was restarted to load this guard. Database deletion runs in a transaction with foreign keys enforced and checked; the document/current-run cycle is detached first. Managed files are removed individually only after database commit, using the signed exact file manifest, canonical path checks and current-reference/checksum checks. No recursive wildcard deletion is used.

`execution.json` records deleted rows, exact file removals and any pending failures. A retry of a committed execution only retries its pending original files, and does not delete newly created data. Changed or newly referenced files are retained with errors. If interrupted between database commit and durable journal confirmation, stop for inspection; a mismatched dataset is refused rather than guessed. Never edit the signed manifest or force a stale backup through validation.

## Verification before final confirmation

- Dedicated cleanup tests cover dry-run, privilege/environment refusal, corrupt/stale backup refusal, path safety, empty backup, account/global file preservation, idempotence with fresh data, shared/exclusive worker locks, full generated report dependencies and public-token 404 after isolated cleanup.
- React: 122 passed, 0 failed (`local/review/cleanup-react-results.json`).
- Full Laravel suite: 189 passed, 1 skipped, 3,310 assertions, 287.24 seconds (`local/review/cleanup-laravel-results.xml`). Final rerun completed without maintenance overlap.
- Focused cleanup suite: 9 passed, 136 assertions (`local/review/cleanup-focused-results.xml`), including known-job scoping, unrelated administrative audit preservation, reserved-job blocking, changed-file refusal and partial file-failure retry.
- TypeScript: `npx tsc --noEmit` passed.
- Final live dry-run fingerprint still matches the verified backup: `a6dbc9a2006d6995aea6e5ae5dc71758ccbb66aa046a7f516159c18e832ef8b1`. No live deletions occurred.
- Independent backup verification: `local/review/cleanup-preflight-verification.json`.

## Completed live verification

- All 34 selected client-related table counts are zero, including clients, projects, reports, original documents, extracted data, publications, share links, Trash and failed client jobs.
- All 1,732 signed-manifest files were removed; zero managed client/report files remain. No replacement test records were created, as instructed in the final authorization.
- Five visible dashboard counters are zero. Clients, projects, active reports, Generated / Shared, report Trash and administrative Trash pages show empty states. All four Trash types were also checked through the authenticated API.
- All eight previous share tokens return HTTP 404 for both interactive report and PDF endpoints. All eight public browser pages display an unavailable message and no report sections.
- Existing administrator authenticated successfully; the exact temporary verification session was removed after checks. Existing accounts, authentication data and schema hashes are unchanged. Anonymous private API access returns 401.
- Database integrity: ok; foreign-key violations: 0. Backend health: HTTP 200. Maintenance mode is off.
- Verified database/archive backup hashes are unchanged. Backups and restore-check copies are retained.
- Execution journal: `execution.json` beside the verified backup. Full row/file detail: `local/review/cleanup-command-output.json`. Completion evidence: `local/review/cleanup-completion-results.json`; live UI/API results: `local/review/cleanup-live-verification-results.json`.
- Dashboard screenshot: `local/review/cleanup-zero-dashboard.png`. Other empty-state screenshots use `cleanup-zero-*.png`; unavailable public report: `local/review/cleanup-old-public-link-unavailable.png`.

The earlier prepared-only descriptions and preflight test results above document the checks performed before authorization; this completion section records the final live state. No workflow fixtures or replacement clients were created after cleanup.
