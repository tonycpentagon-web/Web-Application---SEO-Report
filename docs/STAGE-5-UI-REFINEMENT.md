# Stage 5 UI refinement — tabbed report studio

Completed on 16 October 2026. Stage 6 has not been started. The application remains an internal Draft Preview with the existing publication/review safeguards.

## Open the refined dashboard

[Executive Overview](http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/dashboard?tab=overview)

Sign in with your existing account. Projects → Hann's Pharmacy → Reports → August 2026 → Open Draft Dashboard Preview also opens the same report. Existing report/document/data-review routes and IDs are retained. The selected tab is stored in the URL; browser Back/Forward works.

Eight sticky horizontal tabs display only their selected main content:

- Executive Overview
- Website Analytics
- Google Search Console
- Keyword Rankings
- SEO Activities
- AI Visibility
- Key Events
- Report Summary

Tab buttons support Left/Right, Home/End, focus indicators, aria-selected and a labelled single tab panel. The tab strip scrolls horizontally on small screens.

## Design and data behavior

The supplied local screenshot informed the cyan accents, light neutral background, compact bordered KPI cards, chart/table pairing and source-evidence presentation. The reference URL could not be loaded through the browsing tool; styling was based on the actual supplied screenshot rather than an assumed external design.

The dashboard uses a scoped design system, rounded white cards, restrained shadows, cyan/blue/teal/category colors, readable muted text and formatted figures. The content container is capped at 1,900px. Other workspace screens retain their existing layout.

The client name, reporting dates, website and report status come from the API. Existing client logos load through the authenticated logo endpoint. No client logo is currently stored for this report's client, so a name-derived monogram is shown; no replacement logo was fabricated or uploaded. Uploading a logo through the existing authorized client-branding workflow will make it appear automatically.

Executive Overview presents source-backed KPI cards, traffic mix, ranking groups and compact event/activity/AI highlights. Other tabs present focused data and source tables. Approved, Pending, Human Verified, Provisional and unresolved source issues retain their existing meanings. The live values, report status and review decisions are unchanged.

Charts use Recharts and current normalized observations:

- Channel-user doughnut and traffic-source bars.
- GSC click distribution by device and top-ten landing-page click observations.
- Keyword page-group/category distributions.
- SEO activity category counts.
- Original event-name/count breakdown.

Every chart has a title, source-period context, tooltips, numeric formatting, a textual legend with provisional labels and source attribution links. Colors identify categories; provisional state is also conveyed in text. Charts avoid animation during automatic refresh so source values render consistently. Channel user observations can overlap; their distribution is not presented as a deduplicated unique-user total. No trend lines, growth percentages, unsupported comparisons or exact keyword positions are invented.

## Compact, interactive tables

Keyword, landing-page and SEO activity tables (plus channel/device/event tables) support:

- Search across reported fields.
- Review decision filters; keyword/activity category filters and landing-page verification filters.
- Clickable column sorting and an explicit sort-order dropdown that remains available on mobile.
- Ten rows by default, with 10/25/50 rows per page, Previous/Next and accurate counts.
- Expandable row details with original values, verification/validation notes, source references and existing correction links.
- Compact desktop rows and labelled mobile record cards.

Only the current page's rows/details are rendered. The existing report API supplies the authorized report dataset; table search/filter/sort/pagination is performed over that dataset in the browser. No table action changes the stored metrics. Search/filter/page controls reset or clamp pagination safely when the visible dataset changes.

## AI evidence gallery

AI Visibility uses three columns on larger displays, two on tablet and one on narrow phones. Each card contains an original screenshot thumbnail, query, reported platform, review/provisional state, source metadata and expandable evidence links. Query search and platform filters are available.

Click a thumbnail for the full-screen dialog. Close with the close button or Escape. The modal uses the browser's native focus containment. Images are fetched through the existing authenticated private asset API; temporary object URLs are revoked on cleanup. No original screenshot is resized, overwritten or replaced in storage.

## Executive summary and manager editing

Report Summary contains six source-derived sections: traffic, organic search, keywords, SEO work, AI evidence and key events. Fixed facts use the current normalized values and display filter, with source attribution and provisional notices. Unsupported values are described as not reliably reported; no growth or success claim is generated.

Authorized project managers/administrators can edit separate commentary for each section while the report remains an active Draft. Commentary requires an evidence confirmation and an edit reason. It cannot overwrite source-derived facts, KPI values, review decisions or report status.

The new additive migration creates `report_summary_revisions`. Each explicit save appends a new revision with author/time/reason/source fingerprint and a project audit entry. Existing revisions are never updated or deleted. No summary is seeded and GET requests never create records. The live table is empty until you explicitly save commentary.

API, under the existing Sanctum/active-account/project/report authorization:

    GET  /api/projects/{project}/reports/{report}/summary
    POST /api/projects/{project}/reports/{report}/summary

GET returns revision (initially 0), the six commentary sections, source_fingerprint, stale, can_edit and revision history. POST requires:

```json
{
  "revision": 0,
  "source_fingerprint": "<current 64-character fingerprint from GET>",
  "sections": {
    "website_traffic": "",
    "organic_search": "",
    "keywords": "",
    "seo_work": "",
    "ai_visibility": "",
    "key_events": "Evidence-supported manager commentary."
  },
  "reason": "Explained the source-backed executive observations.",
  "confirmed": true
}
```

Each commentary field supports up to 3,000 characters. The reason requires 10–2,000 characters. Unknown keys and malformed submissions are rejected. Stale summary versions or reporting-data fingerprints return 409; unauthorized editors return 403, foreign project/report IDs 404. Saves lock the report/current sources and recheck permissions, revision and source fingerprint transactionally. If values, normalization, verification or decisions change later, saved commentary is flagged for renewed review while generated facts continue to refresh dynamically.

## Internal review and publishing safeguards

The provisional toggle, source evidence, individual/bulk review links, existing report status and backend publication checks are preserved. All current warnings remain available in a compact expandable review strip on every tab; the strip always shows unresolved blocking and total warning counts. Hiding details or excluding provisional values cannot bypass publication checks.

Automatic approval, report status transitions, sharing, source replacement and normalization were not performed during this refinement. Public/client dashboard access was not enabled. Existing server-side publishing/sharing guards remain in force.

## Validation and preservation

| Check | Result |
| --- | --- |
| Full Laravel suite | **89 passed, 752 assertions** |
| Full React suite | **57 passed** |
| TypeScript | **Passed** |
| Vite production build | **Passed**; dashboard/charts remain separately loaded |
| Headless Edge dashboard integration/layout checks | **23 checks passed; zero runtime exceptions** |
| Existing row/file preservation baseline | **Passed** |
| Additive summary migration | **Applied locally** |
| MySQL 8 runtime | Unavailable; not verified on this machine |

React checks cover all eight tabs, keyboard navigation, one-panel rendering, API-derived chart points, review/source links, live refresh, strict provisional filtering, table limits/filtering/sorting/pagination/expansion, private full-screen image preview and authorized/read-only summary behavior. Laravel checks cover summary permissions, malformed input, append-only revision history, concurrent edits, changed source fingerprints and unchanged underlying reporting values/status, plus all previous authentication, document, review and publication regressions.

The Edge test loads the actual production build and Recharts at 1600, 900, 390 and 2400px viewport widths. It verifies one active panel, bounded table rows, private screenshots/full-screen Escape handling, sticky navigation styling, no horizontal page overflow and the maximum content width. Browser API responses were intercepted from a read-only export of the actual report data; writes were blocked and no live user session or password was used. This is not a claim of a real-account login/session end-to-end test or a formal accessibility audit.

Visual screenshots were inspected, including the full executive overview, AI gallery, full-screen evidence and mobile views. Local private artifacts:

- [Desktop executive overview](../.tools/dashboard-ui-browser/desktop-overview-full.png)
- [AI visibility gallery](../.tools/dashboard-ui-browser/desktop-ai-gallery.png)
- [Full-screen evidence preview](../.tools/dashboard-ui-browser/desktop-image-preview.png)
- [Mobile overview](../.tools/dashboard-ui-browser/mobile-overview.png)
- [Browser results](../.tools/dashboard-ui-browser/results.json)

The checksum baseline confirmed all existing users, clients, projects, report periods, documents, raw extraction, assets, normalized records, corrections, references, warnings, activity history, verification markers and bulk-request records matched. Existing decisions remain **21 Approved and 1,184 Pending across 1,205 normalized records**. All five originals and design/reference file checksums matched. No existing record was rewritten or lost. KPI values remain unchanged; all 218 business observations and 25 current normalization warnings remain available through the dashboard/review APIs. The report remains Draft, revision 1.

## Browser testing instructions

1. Open the Executive Overview URL above with your existing account. Confirm client/report metadata, current KPI values and the Internal Draft Preview/Draft badges.
2. Switch through all eight tabs; confirm unrelated main content is absent and the URL tab parameter changes without changing the report ID. Try browser Back/Forward and keyboard Left/Right/Home/End.
3. Scroll within a report tab; the horizontal tab strip stays available. At mobile widths, swipe/scroll the tab strip horizontally.
4. Open Website Analytics/GSC and hover chart slices/bars. Check legends, units, source attribution and provisional notices against the linked records.
5. In Keyword Rankings, Landing pages or SEO Activities, search, change category/review filters, sort columns/dropdown, select 10/25/50 rows and paginate. Expand a row and follow its original evidence or existing review link.
6. Open AI Visibility. Search/filter the gallery, open a screenshot, close with Escape, and confirm the full image matches its thumbnail/source.
7. Open Report Summary. Generated facts should match the current data view. Authorized managers can use Edit summary, add supported commentary, enter a reason, confirm evidence and save. This is an intentional new commentary revision; do not save merely to demonstrate preservation of the initial live state.
8. Expand the review strip to see all warnings. Confirm unresolved issues still block publishing/sharing and that the report status remains unchanged.
9. Resize to desktop/tablet/phone widths. Check compact record cards, sorting controls, gallery columns, keyboard focus and source details. Refresh to confirm data updates without hardcoded metrics.

## Run/build commands

The new migration is already applied in this checkout. Other environments should apply the additive migration with their existing compatible PHP; do not reset/reseed the database or overwrite .env.

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Separate terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

Validation:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan test
Set-Location ..\frontend
npm.cmd test
npm.cmd run build
```

No new packages, external AI service or environment secrets are required. Keep the existing single documents worker for extraction/normalization; UI browsing and summary reads need no new worker.

## Main files

- `frontend/src/pages/report-dashboard.tsx` — eight-tab report shell, overview and focused report content.
- `frontend/src/components/dashboard/shared.tsx`, `charts.tsx`, `table.tsx`, `gallery.tsx`, `summary.tsx` — scoped reusable report components.
- `frontend/src/lib/report-dashboard.ts`, `frontend/src/styles.css`, `frontend/src/test/report-dashboard.test.tsx` — API metadata, responsive styling and interaction coverage.
- `backend/app/Http/Controllers/ReportSummaryController.php` — scoped read/append-only commentary API.
- `backend/database/migrations/2026_10_16_000008_create_report_summary_revisions.php` — new summary-history table only.
- `backend/app/Services/ReportDashboard.php`, `backend/routes/api.php`, `backend/tests/Feature/BulkRecordReviewTest.php` — optional authenticated logo metadata, summary routes and backend coverage.
- `README.md`, `docs/VERIFICATION.md`, `docs/STAGE-5-DRAFT-PREVIEW.md` and this document — current handover and verification links.