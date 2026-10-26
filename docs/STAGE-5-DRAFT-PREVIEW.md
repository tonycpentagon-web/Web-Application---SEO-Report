Current presentation: [Stage 5 tabbed UI refinement, summary editing and browser instructions](STAGE-5-UI-REFINEMENT.md). The original implementation history below remains applicable to source/review/publication semantics.

# Stage 5 — internal Draft Dashboard Preview

Implemented as an internal preview on 16 October 2026. It reads the existing normalized records and does not create dashboard fixtures, approve records, alter report status or create public dashboard links. No new database migration or dependency is required.

## Open the preview

Sign in with your existing account, then Projects → Hann's Pharmacy → Reports → August 2026 → **Open Draft Dashboard Preview**.

Direct authenticated route:

    http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/dashboard

The project-level dashboard also links to reporting periods. This initial report dashboard is restricted to internal project members. Even explicitly shared Client Viewers cannot access the preview API or its source evidence.

Start the existing applications in separate PowerShell terminals if needed:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

Keep the existing single documents worker for extraction/normalization. Dashboard reads and refreshes do not require a new worker. Do not reset the database, replace .env or recreate your account.

## Current data and visible states

The dashboard opens with Include provisional records for internal review enabled. Approved decisions remain approved, Pending decisions remain pending, and Human Verified remains a separate evidence marker.

Fully reviewed observations are shown normally when approved, human verified against the current evidence/effective values, validation-clean and current. Pending observations are explicitly provisional even if already human verified. Approved observations with missing human verification, unresolved validation or stale sources retain their Approved decision badge **and** a Provisional label explaining the outstanding issue. No historic approval is downgraded in the database.

The provisional toggle can exclude all provisional observations. This stricter view can be sparse; it never fills missing values with placeholders that resemble real figures. Global warnings and publishing readiness remain visible regardless of the display filter. Turning off provisional inclusion cannot bypass publication checks.

Source records marked Rejected and inactive historical observations are excluded from dashboard values, while their review/extraction history remains intact. Unclassified context is available in Report Data Review; it is not converted into business metrics.

## Dashboard sections

- Report/client/date header, internal preview badge, actual report status and review coverage.
- Dynamic period KPI cards with original GA4/GSC/event metric definitions and units.
- Key event bars and searchable source table; event counts are not classified as genuine leads.
- GA4 traffic-channel bars, GSC device bars and landing-page observations.
- Nine current AI screenshot records, with authenticated private image loading/downloads and their headings/platform/appearance notes.
- Keyword ranking groups and searchable/paginated keyword observations, preserving primary/additional categories and page labels.
- SEO activity category counts and searchable/paginated listed URLs; no claim that submissions are verified live backlinks.
- All current normalization warnings with effective blocking/resolution status and source links.

Cyan bars represent fully reviewed observations; amber bars are explicitly provisional. Tables and decision badges provide the same values and evidence independent of chart color. Group/activity counts describe the current display filter. No fabricated daily trends, comparisons, growth percentages, visibility scores or exact keyword positions are generated from period totals.

Repeated identical KPI observations display a common value with each source observation available. Conflicting values display a conflict and no selected/summed KPI value. GA4 users, GSC clicks and events never become interchangeable metrics.

## Dynamic updates and provenance

Every API request projects the current database values, including sparse manual overrides and current approval/verification states. The dashboard refreshes every 10 seconds while visible, when the window regains focus, and on Refresh dashboard. It does not persist a stale dashboard snapshot.

Every observation links to its original source and the exact extraction attempt, plus Inspect or correct record. The latter opens the appropriate data-review tab with the selected record inspection panel. After correction/reverification/approval, return to the dashboard or refresh to see the new value and status. Changes to source extraction/report dates invalidate the source fingerprint; stale observations become provisional and publication is blocked until normalization/review is current.

The preview endpoint sends `Cache-Control: private, no-store`. Source screenshots use authenticated requests and temporary object URLs, revoked when no longer needed. Originals, raw extraction text/tables/images, warnings and processing logs remain on Document Details.

## API and publishing safety

    GET /api/projects/{project}/reports/{report}/dashboard?include_provisional=1

`include_provisional` accepts 0 or 1 and defaults to 1 for internal review. Sanctum authentication, active-account checks, project/report isolation and internal-only document authorization apply.

The response contains mode, report/project metadata, normalized records, KPI observation groups, review coverage, source references, warnings, normalization freshness, granularity and publication readiness. Metric values originate from uploaded sources through normalized records; no Hann's figures or client identifiers are hardcoded in production dashboard components or the projection service.

Existing **publish status transitions** and **positive client-sharing submissions** now recheck publication readiness server-side inside their transactions. The check locks the reporting period/current source documents and evaluates the complete dataset, independent of the preview display filter.

Publishing and new sharing are blocked when normalization is missing/incomplete/stale, no supported reporting data exists, any included non-rejected business record remains pending/unverified/invalid/conflicted, or a blocking warning is unresolved. Informational warnings alone do not block. Warnings attached to explicitly rejected records remain preserved and are excluded from the approved dataset. Unsupported/missing source data without a valid resolution remains a blocker rather than silently disappearing.

Blocked actions return HTTP 409 with reasons. The report page displays the reasons and removes Published from its available transition choices while blocked. Clearing all client viewers is still allowed so access can be revoked. Existing share records and report statuses are not changed automatically.

When checks become clear, existing workflow permissions/transitions still apply. A readiness result never publishes or shares automatically. This initial dashboard remains internal even if report metadata is later explicitly published using an authorized workflow; no public/client-facing dashboard endpoint was introduced.

## Live verification and preservation

At the read-only verification snapshot, the August report remained **Draft, revision 1**, August 1–31, 2026. It projected all **218 business observations**:

| Kind | Records |
| --- | ---: |
| Metrics | 10 |
| Traffic channels | 6 |
| Devices | 3 |
| Landing pages | 20 |
| Keyword groups | 70 |
| SEO activities | 95 |
| AI screenshots | 9 |
| Events | 5 |

The live dashboard showed **21 Approved**, **5 currently Human Verified**, and **213 Provisional** business observations. Approved and Human Verified are overlapping attributes, not additive categories. All **25 current normalization warnings** were retained; **20 blocking warning IDs remained unresolved** across the full dataset. Publication readiness was false.

KPI values were read back from persisted sources (including GA4 users 4,515, GSC clicks 2,950 and event total 97) without inserting defaults. These checks are verification references, not production constants.

A private database backup and row/file checksum baseline were captured before implementation. During development the existing review workflow recorded one additional bulk approval by the existing reviewer account, with a source-grounded review reason and no value correction. That legitimate change was retained, not reverted: only that record's decision/revision/reviewer/timestamps changed, and its audit row/project events were appended. This is why a strict whole-database baseline comparison reports a difference; it is not presented as an unchanged-database pass.

The detailed preservation audit confirmed:

- All baseline users, clients, projects, report periods, documents, extraction attempts, raw content, assets, normalization attempts, source references and warnings matched.
- All original normalized reporting values/raw values were unchanged; the only changed baseline normalized row contained the existing workflow's pending-to-approved decision and review metadata.
- All pre-existing correction/audit rows were preserved; new workflow events were appended without deletion or reset.
- Private original file and reference/design file checksums matched.
- The separately captured five verification markers and two idempotent bulk-request records remained unchanged during preview verification.
- Report ID/dates/Draft status/revision were unchanged; no dashboard read operation wrote approvals, corrections, records or publication status.

The private `.tools/stage5-live-verification.json` and `.tools/stage5-preservation-audit.json` contain the local diagnostic snapshot. They are ignored artifacts, not public resources. Counts may increase naturally as authorized review continues.

## Validation and browser checks

| Check | Result |
| --- | --- |
| Full Laravel suite | **87 passed, 728 assertions** |
| Targeted workspace tests after final transition-choice filtering | **16 passed, 136 assertions** |
| React suites | **51 passed** |
| TypeScript / Vite production build | **Passed** |
| Chart bundle | Lazy-loaded separately from the main application |
| Live projection | 218 observations, current warnings/readiness, existing Draft state |
| Source and history preservation | Audited as described above; concurrent review retained |
| MySQL 8 execution | Unavailable; not verified |
| Actual browser visual/PDF/image rendering checks | Not automated; use the walkthrough below |

Backend tests cover mixed approved/pending/human-verified states, read-only projection, dynamic corrected values and approvals, stale sources, conflicts, filter-independent warnings/publication guards, rejected/inaccessible data, permissions, sharing revocation and safe publishing after all requirements are met. Existing authentication/extraction/review tests also pass. React tests cover provisional labels, API values, source/correction links, strict filtering, manual/poll/focus refresh, stale/conflict states, empty data and forbidden APIs. Chart presentation is mocked in unit tests; they do not claim actual browser rendering verification.

Browser walkthrough:

1. Open the authenticated direct route above; confirm Internal Draft Preview and the report's existing Draft badge.
2. Inspect an approved card and its source details. An unverified legacy approval must retain Approved while explaining why it is provisional.
3. Inspect a Pending or Human Verified/Pending row; it must show Provisional until approval/validation are complete.
4. Turn off Include provisional records. Provisional observations disappear; validation warnings and publishing blockers remain.
5. Use Inspect or correct record to open the existing review panel. Any intentional correction or approval is your explicit review action, not a dashboard side effect.
6. Return to the dashboard, click Refresh dashboard or wait ten seconds; confirm the current saved value and decision appear.
7. Open AI screenshots and original evidence links; verify the private images and extraction-attempt references.
8. Search/paginate keyword and SEO tables. Check narrow/mobile widths and chart labels with the source tables.
9. Open the report workspace. Blocked publication choices are absent; attempting a direct publish/share request still returns 409. Do not change the live report status just to demonstrate the dashboard.

Repeat checks:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan test
Set-Location ..\frontend
npm.cmd test
npm.cmd run build
```

## Application files

Added backend projection/controller: `backend/app/Services/ReportDashboard.php`, `backend/app/Http/Controllers/ReportDashboardController.php`.

Updated backend policy/routes/tests: `RecordReview.php` (shared warning classification), `ReportingPeriodController.php` (readiness and transactional publishing/sharing guards), `routes/api.php`, `BulkRecordReviewTest.php`, `WorkspaceTest.php` (legacy empty-report publication expectations now guarded).

Added frontend: `frontend/src/pages/report-dashboard.tsx`, `frontend/src/lib/report-dashboard.ts`, `frontend/src/test/report-dashboard.test.tsx`.

Updated frontend: `App.tsx` (lazy authenticated route), `pages/reports.tsx`, `pages/project-dashboard.tsx`, `pages/report-data.tsx` (inspection deep link), `lib/report-data.ts` (shared display helper types), `types.ts`, `styles.css`. Documentation links are updated in README.md and docs/VERIFICATION.md.