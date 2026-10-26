# Monthly report management ? implementation and QA

Implemented in the existing Laravel/React application on 20 October 2026. No remote deployment, Docker installation or GitHub push was performed.

## Staff workflow

Monthly Reports defaults to Active Reports: Draft, Processing, Ready for Review, Approved, failed/incomplete work and Published reports still awaiting public sharing. Pending reviews and current-month work take priority in Overview. Recently published reports appear in their own small section, and the project list prioritizes active projects.

Create the next month for the same project using Create Monthly Report or Create next month. Every reporting period retains its own documents, generation runs, observations, commentary, approvals and publications. A new project is not required for a new month.

After an eligible report is Published and a public share link is created, it automatically moves to Published / Archived Reports. This uses a separate workspace_archived_at timestamp: it does not change Published status, increment the content revision, edit the immutable snapshot, remove source-backed records, expire links or alter PDF permissions. Existing published public shares are classified on upgrade without rotating their URLs or modifying publication records. Expired or revoked links do not send historical reports back into active work.

History displays month, client, project, publication date, public-link state and actions to Copy Report Link, Preview Published Report, Download PDF, Revoke Link, Restore to Active List, Move to Trash and create the next month. Restore to Active List changes only workspace placement; public links and snapshot bytes remain unchanged. Filing the report again also preserves them. Publication preview/PDF/link-copy access remains restricted to permitted staff; only managers can revoke/share or change report management state.

The Trash tab follows the existing Admin-only permission and configurable retention policy. Moving a report to Trash revokes its public links and account grants immediately. Restore does not reactivate revoked access. Parent clients/projects and other reporting months are not deleted. Use Administration Trash for deleted parent recovery and permanent deletion after retention.

## Sharing and retention

The sharing form now starts with public access selected, with explicit consent still required before creation. Public links display only an eligible immutable Published report without login, honor expiration/revocation and the link's PDF permission, and never expose Drafts, source-management pages, other projects or portal history. The API's omitted-mode behavior remains account-based for compatibility with existing callers; the UI sends its explicit public selection. Existing account-based URLs retain their account requirements and are not silently converted into public access.

New links retain an encrypted token alongside the existing hash so permitted staff can copy the same URL again. Lists/audit events never include the raw token or ciphertext. Copy responses are private/no-store. The installation APP_KEY must be retained in backups. Older links stored only as hashes cannot be reconstructed: their existing URL still works, and Sharing can create an additional copyable public link without revoking the old one. The UI explains this restriction.

Workspace filing is distinct from the older advanced workflow status Archived, which suspends a report pending revision. Existing workflow-archived records appear in History but are not silently republished or made public by this update. Newly filed completed reports remain Published. Source-readiness and immutable-snapshot integrity checks remain enforced.

Published snapshots, sources and published PDF exports are not automatically removed. No extraction-file optimization or new deletion schedule was introduced. Existing published-export retention protections and explicit Admin/Super Admin Trash/permanent-removal policies remain in effect. This is a local application: localhost URLs cannot reach external clients without an explicitly approved accessible host.

## Executed QA

- Full Laravel suite: **151 passed, 1 skipped, 2,856 assertions**. The skipped test requires an opt-in real MySQL environment. After correcting the current-month date boundary, the affected overview and complete monthly workflow tests also passed: **2 tests / 299 assertions**.
- Full React suite: **108 passed in 14 files**. TypeScript checking and production build passed. The build retains Vite's advisory for an approximately 522 KB entry chunk (159 KB gzip).
- Full monthly integration test executes six real synthetic PDFs for August and September, extraction, automatic dashboard generation, explicit source/summary review, approval, publication and public sharing. It confirms automatic filing, authorized repeat URL copying, anonymous online/PDF access, August/September history with October active, independent sources, unchanged August snapshot/version, restore/refile without broken links, revocation, September Trash without deleting other months, tenant isolation and Admin-only Trash.
- A separate regression verifies legacy hash-only links remain functional, cannot be recovered through copy, do not leak into audit, deny disallowed PDF access and expire normally.
- Browser checks ran against a disposable clone of the earlier synthetic evaluation database/storage, not the real client database. They verified the three tabs; automatic filing after public sharing; copying the same saved URL; anonymous viewing/PDF after filing, restore and refiling; a new October report in the same project with an uploaded CSV and generated Draft; unchanged August sources/publication; immediate Revoke Link and Trash denial; and October remaining active. The test runtime's processor path was corrected and its failed source explicitly retried; the final browser result passed with zero JavaScript errors. These are functional checks, not a load-capacity or remote-host certification.
- Mobile check: document width equals the 390 px viewport; tables scroll within their container.
- Preservation: **11,788 baseline rows and 1,030 tracked original/source/asset/export/reference files unchanged**. A fresh private baseline and backup were created before edits. Additive migrations ran on the existing local database and isolated test databases. No real report was published, trashed or deleted for QA.

Screenshots: [Published / Archived](../.tools/monthly-management/published-history.png), [Active Reports](../.tools/monthly-management/active-reports.png), [mobile](../.tools/monthly-management/active-mobile.png), [Trash](../.tools/monthly-management/trash-tab.png). Machine-readable browser output: .tools/monthly-management/browser-results.json. These previews contain synthetic data only; no share tokens or passwords are included in this document.

## Source changes

- backend/database/migrations/2026_10_21_000001_separate_report_workspace_filing.php: additive filing timestamp, encrypted-token column and existing public-share classification.
- backend/app/Services/ReportWorkspace.php: active/history/trash filters and publication-preserving filing.
- backend/app/Models/ReportingPeriod.php: filing timestamp cast.
- backend/app/Http/Controllers/MonthlyReportController.php: scoped tabs, publication dates, safe share metadata and complete published PDF metadata.
- backend/app/Http/Controllers/ReportDeliveryController.php and backend/routes/api.php: atomic automatic filing on public-link creation, encrypted token storage and staff-authorized copy endpoint.
- backend/app/Services/ReportDelivery.php: clear prior workspace placement on explicit republication; availability/security checks unchanged.
- backend/app/Services/WorkspaceLifecycle.php and backend/app/Http/Controllers/LifecycleController.php: separate published filing/Restore to Active List from content/workflow status changes.
- backend/app/Http/Controllers/ProjectController.php: active work/current-month priorities, recent publications and correct first-of-month date comparisons.
- frontend/src/pages/monthly-report-list.tsx: three tabs, history metadata, repeat copy, preview/download, revoke, lifecycle and next-month actions.
- frontend/src/pages/monthly-reports.tsx, frontend/src/pages/reports.tsx and frontend/src/components/lifecycle-actions.tsx: integrate the new list and filing actions.
- frontend/src/pages/overview.tsx, frontend/src/types.ts and frontend/src/styles.css: simplified employee overview and list/action presentation.
- frontend/src/components/report-sharing.tsx: explicit public-link flow by default while retaining account-based access.
- backend/tests/Feature/MonthlyAutomationTest.php, ReportDeliveryTest.php and ProductWorkspaceTest.php; frontend/src/test/monthly-management.test.tsx and report-delivery.test.tsx: complete monthly lifecycle, link compatibility, authorization, tab/action and current-month regressions.
