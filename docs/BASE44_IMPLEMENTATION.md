# Seven-section report implementation

## Architecture and pre-change investigation

Read README, ARCHITECTURE, CLIENT_REPORT_EXPERIENCE, REPORT_AUTOMATION_ENHANCEMENT, current simplified-sharing and public-QA documentation, generation/controller/services, extraction/normalization and PDF implementations and existing tests. The system is Laravel 12 + React/TypeScript, private source storage, Python extraction, deterministic normalization, additive evidence validation, immutable publication snapshots and queued PDF exports.

Upload -> ProcessReportDocument -> Python formats_pdf (native stream, table rows/cell bounding boxes, image assets) -> ReportNormalization manifest -> Python Normalizer -> normalized_records + source references -> ReportDashboard / ReportDelivery snapshot -> React report rendering -> immutable ReportPublication -> ClientReportController -> frozen PDF job. Filename text does not control reporting facts.

Before this change, Draft Preview and PublishedDashboard used shared charts/tables but duplicated the page composition. Both silently hid empty keyword sections. ReportSummary and Sources were standalone navigation sections. The new implementation uses one presentation schema and shared layout for private preview and public/published reports; backend audits remain available internally. Client-style PDFs use the seven-section order and contextual source appendices; internal audit export retains its full audit information.

## Confirmed Advanced Properties failure

The existing September report and five originals are present. Keyword PDF classification correctly says keyword_ranking. Native text and four extracted tables exist, but active normalized keyword count is zero. The keyword PDF p2 has a nine-column physical table grid: semantic header values are at columns 1, 4, 7 while merged data cells hold the corresponding values at columns 0, 3, 6. The prior normalizer copied numeric header indexes, found blank cells in data rows, then carried those invalid indexes onto three-column continuation pages. The standalone EXTRA KEYWORDS marker also lacked a category transition. Zero rows reached the API and frozen snapshot; React hid the section. This is not an upload or missing-original problem.

The correction uses the saved PDF header-cell x coordinates to locate each row's actual merged cell, including continuation pages. Values come from existing extracted cells, never from static fixtures. Original 1st/2nd/3rd Page labels remain; exact positions remain null. Multiline/alternative labels remain linked to their source rows and provisional where association is ambiguous. A classified-but-empty keyword source now yields a clear employee processing issue instead of an invisible section.

## Reference comparison

The supplied screenshots and the repository design-reference image verify the cyan top rules, navy serif section headings, white KPI cards, pale page background, cyan table headers, alternating rows, thin borders, cyan/navy charts, two-column screenshot gallery, sticky section navigation and cyan thank-you footer. The latest screenshot requests are implemented with the user's explicitly specified seven-section order, which supersedes the older source screenshot's event-first arrangement. The live Base44 page loaded successfully in Microsoft Edge. Its inner content width is 1,112 px, H1 is 60 px and section headings are 36 px, using Lora/Inter. The existing local Georgia/Arial fallbacks remain; this is a close visual adaptation, not a pixel-identical font match. Live reference values and screenshots are saved in local/review/base44-reference-style.json, base44-reference-top.png and base44-live-reference.png. The supplied screenshot and the live page contain five original content groups; the explicitly requested seven-section structure takes precedence.


## Additional confirmed source defects and the user's coverage decision

The Analytics PDF's native selector is **Aug 29, 2026 - Sep 27, 2026**, including the page-3 selector with an `arrow_drop_down` UI token. The previous period parser recognized day-first and ISO dates but not month-first comma dates. Its uncertain classification therefore missed a real cross-month range. The event image prints the same dates; its OCR stream is `Aug 29,2026-Sep 27,2026`, with no spaces after commas. The parser now supports both layouts and preserves OCR uncertainty. Filenames do not establish periods.

The user explicitly instructed: **Allow the existing cross-month analytics only with an explicit, audited 29 Aug-27 Sep coverage disclosure.** The report remains filed as September, with actual analytics/event coverage shown prominently on the web and PDF. These are source-period totals, not September calendar-month totals. September 28-30 is not covered. The Analytics PDF and Key Events image have separate fingerprint-bound scope decisions, recorded through the employee UI. OCR values remain provisional. The source mismatch is preserved in the evidence assessment; the explicit actual-coverage policy resolves how it is presented rather than relabelling dates.

`POST .../sharing/source-coverage` requires an authorized assigned employee, current revision and assessment fingerprint, explicit confirmation and the specific document IDs. It binds the original checksum, extraction evidence, client/project/report dates and printed ranges. A changed source invalidates the decision. A wholly different month cannot be assigned this way, and wrong-client/checksum/conflict checks remain blocking. A general uncertainty acknowledgment cannot resolve a mismatch. No record or manager approvals are required by the normal workflow.

The same Analytics PDF lists **Impressions before Url Clicks** in its landing-page header, unlike the earlier Hann's layout. The old native-stream normalizer assumed the opposite order. All 20 landing-page observations were corrected from their printed headers, with source-change audit records. Example: home page **216 clicks, 7,296 impressions, 2.96% CTR**, rather than the swapped 7,296 clicks / 216 impressions. Native uploads, extracted text, raw rows and URLs were preserved.

## Final report and section order

The final Advanced Properties report is **immutable publication version 4**, created through the supported Share Report workflow after regeneration and explicit actual-coverage selection. Intermediate QA links created during implementation were revoked; the user's original version-1 link is still active and unchanged. Historical publication records and previously completed PDFs were not rewritten.

Every private preview and published/public report uses these seven main sections and navigation items:

1. Executive Overview
2. Website Traffic
3. Organic Search
4. Keyword Rankings
5. SEO Work
6. AI Visibility
7. Key Events

Standalone **Report Summary** and **Sources & Reporting Notes** were removed. Factual commentary now appears alongside its section; material coverage/limitations and safe source citations remain in contextual expandable areas. Backend commentary revisions, audit views, private originals and internal audit exports remain available. Client-style PDF exports follow the seven-section order with contextual evidence. Existing immutable old PDF files keep their original layout.

The private `/presentation` endpoint projects the same allowlisted report schema used by `PublishedDashboard`. Draft Preview supplies a private toolbar and export/share controls around this shared layout. It does not maintain a conflicting second composition. All newly generated monthly reports automatically use this renderer. A published report can explicitly prepare updated normalization without changing its Published status or interrupting earlier schema-2 links; new content is published only in a new immutable version.

### Advanced Properties results

- **40 keyword records:** 10 primary, 30 additional; 34 first-page, 5 second-page, 1 third-page observations. Original `1st Page` / `1st page` spelling is preserved in rows; both share the first-page distribution. Exact positions remain null.
- Source alternatives for the two multiline keyword rows remain visible on expansion and in the PDF, with uncertainty labels. Missing exact ranking dates/locations are not invented.
- Website source totals: **702 users**, **646 new users**, **1,608 views**, average session duration **2:12**. Sessions are not reported.
- Organic Search users: **453**. Search Console: **469 clicks**, **23,490 impressions**, reported **2.00% CTR**. Printed analytics coverage is 29 August-27 September.
- **61 distinct listed SEO activities**, **8 original AI screenshots**, and **5 event types**. Events: file_download 44, Whatsapp_Click 29, Email_Click 8, Phone_Number_Click 4, ContactForm_Submit 2; total **87**.
- The public report is fully populated from the existing five original uploads. No placeholders or substitute images were added.

## Actual verification results

- Laravel full suite: **182 passed, 1 skipped, 3,192 assertions**, 297.45 seconds. After the final compact-OCR range regression and coverage change: **53 focused tests passed, 780 assertions**. The skipped test requires an optional MySQL environment.
- React: **122 passed**. Includes the shared seven-section preview, missing-keyword error, token-preserving navigation, public evidence, chart calculations and explicit source-coverage assignment.
- Python: **46 passed**, including merged-cell headers/continuations, alternate labels, empty keyword errors, reversed landing headers and month-first/compact OCR dates.
- TypeScript no-emit check and Vite production build passed. The existing bundle-size advisory remains nonblocking.
- Actual Microsoft Edge browser checks at **1440, 768 and 390 pixels**: Advanced Properties and Hann's public reports; Advanced Properties and SkyHub private previews. All have seven sections in the required order, no document overflow and no uncaught page errors. Anonymous public pages have no console errors. The public route no longer sends an unnecessary staff `/api/user` probe; a data favicon removes the missing-favicon error. Staff authentication/session tests remain passing.
- Keyword search, sorting, ranking filtering, pagination and rendering all 40 Advanced / 69 distinct Hann's observations passed. All 8 Advanced and 9 Hann's original image dialogs loaded at original resolution, without cropping.
- Public links remain anonymous. Private source links are absent from the report. Scoped private presentation/source endpoints reject anonymous or unrelated users. Original version-1 Advanced access is preserved, with its historical zero-keyword payload unchanged. Intermediate QA links return 404 after revocation. The active final link returns the corrected version 4.
- Final Advanced PDF: **20 pages**, all 40 keywords and all eight screenshots included, actual coverage dates printed, no text outside page bounds, no standalone Summary/Sources sections. Cover, keyword, landing-table and AI-image pages were rendered for inspection. A sparse second cover page found during QA was removed by tightening the cover spacing.
- Preservation comparison against `local/review/base44-baseline.sqlite`: all **25 originals/checksums**, clients, projects, extracted content/assets, human verifications, manual overrides, other reports and their normalized records remain unchanged. Every pre-existing publication row/hash is unchanged. Only the documented 20 landing-page field mappings changed among existing Advanced business values; 40 supported keyword records were added. Raw originals were not modified.

### Other reports and limitations

Hann's August still shows its previously authorized partial 1-30 August coverage and missing 31 August. Its existing snapshots and links were preserved. SkyHub's September source files are attached to an existing report labelled **October 2026**. Its current private preview was regression-tested without changing its dates or publishing mismatched September sources as October. No separate September SkyHub report or duplicate uploads were created.

Original source ambiguities remain: two multiline keyword associations, missing ranking dates/locations, some activity-link mapping, and OCR/AI brand-appearance interpretation. No AI score, verified backlink status, monthly growth or missing-day values were invented. Local fallback fonts differ from the Base44 Lora/Inter fonts; exact pixel parity is not claimed.

## Open and copy the report

Application: http://127.0.0.1:5173/login

Private Advanced Preview (requires an authorized employee session):
http://127.0.0.1:5173/projects/01m5h5nnn19g0vwm1xyvr9t304/reports/01m5h5pm1xacszarrdcgxwbtnp/preview

Share / Copy Link:
http://127.0.0.1:5173/projects/01m5h5nnn19g0vwm1xyvr9t304/reports/01m5h5pm1xacszarrdcgxwbtnp/delivery

Choose **Copy Link** for the newest active link. The exact current anonymous URL is saved privately in `local/review/advanced-corrected-link.json` and provided in the final response. It expires 21 November 2026 by default. Additional links can be generated from the same latest snapshot; preparing an updated publication is optional. URLs are local to this Windows machine; nothing was deployed.

## Screenshots and reproducible artifacts

- [Advanced before, desktop](../local/review/advanced-before-1440.png)
- [Advanced after, desktop cover](../local/review/advanced-after-1440-cover.png)
- [Advanced after, populated Section 04](../local/review/advanced-after-1440-client-keywords.png)
- [Advanced after, tablet Section 04](../local/review/advanced-after-768-client-keywords.png)
- [Advanced after, mobile cover](../local/review/advanced-after-390-cover.png)
- [Advanced after, mobile Section 04](../local/review/advanced-after-390-client-keywords.png)
- [Advanced PDF](../local/review/advanced-final.pdf)
- [PDF cover](../local/review/advanced-pdf-cover.png)
- [Original merged keyword source, page 2](../local/review/advanced-keywords-source-page2.png)
- [Live Base44 reference](../local/review/base44-reference-top.png)

Every section has captures named `advanced-after-<width>-client-<section>.png`; private preview captures use `advanced-preview-<width>.png`. Element-only screenshots suppress the floating navigation during capture to avoid screenshot stitching artifacts; separate viewport captures and actual navigation checks retain it. Machine-readable results: `base44-browser-results.json`, `advanced-pdf-qa.json`, `base44-preservation.json`, `base44-source-coverage-audit.json`, `base44-frontend-tests.json`, `base44-backend-final.txt`, `base44-backend-post-ocr.txt`, and `base44-python-tests.txt` in `local/review`.

## Modified files

Backend application:
- `app/Services/DocumentAccess.php`: scoped permission to prepare a new publication while retaining old links.
- `app/Http/Controllers/MonthlyReportController.php`, `app/Jobs/GenerateMonthlyReport.php`: explicit published-report regeneration using existing originals, without changing old snapshots.
- `app/Services/SourceReportingPeriod.php`: month-first dates, compact OCR dates, known selector icons and missing dates.
- `app/Services/EvidenceValidation.php`: new rules version, guarded new-publication checks and fingerprint-bound actual-coverage policy; all other material checks retained.
- `app/Services/DraftCommentary.php`: guarded generation for a new publication; manual commentary preserved.
- `app/Services/ReportDashboard.php`: meaningful classified-but-empty keyword processing issues.
- `app/Services/ReportDelivery.php`: preserves alternative source keyword wording in the allowlisted schema.
- `app/Services/SimplifiedReportSharing.php`: common private presentation schema, publication-aware deduplication and coverage disclosures.
- `app/Http/Controllers/SimplifiedSharingController.php`, `routes/api.php`: private presentation and explicit source-coverage actions.
- `resources/views/reports/pdf.blade.php`, `partials/table.blade.php`: seven-section client PDF, contextual evidence, alternate labels and cover spacing.
- Tests: `tests/Feature/SimplifiedWorkflowTest.php`, `tests/Feature/ReportDeliveryTest.php`, `tests/Unit/SourceReportingPeriodTest.php`.

Python:
- `document-processor/normalization.py`: geometry-aware keyword cells, category transitions, processing errors, native landing-header order and date layouts.
- `document-processor/tests/test_normalization.py`: corresponding regressions.

Frontend:
- `src/components/published-dashboard.tsx`, `src/pages/report-draft-preview.tsx`: shared seven-section composition.
- `src/components/report-source-coverage.tsx`, `src/pages/report-share.tsx`: visible actual coverage and explicit scoped assignment.
- `src/components/dashboard/keyword-summary.tsx`, `src/components/dashboard/table.tsx`: primary/additional counts and alternate source wording.
- `src/styles/report-preview.css`: responsive cards and keyword summaries.
- `src/auth.tsx`, `src/App.tsx`, `index.html`: anonymous public route behavior, safe skip navigation and favicon.
- Tests: `src/test/report-draft-preview.test.tsx`, `public-evidence.test.tsx`, `monthly-portal.test.tsx`, `auth-session-race.test.tsx`, `simplified-sharing.test.tsx`.
- Rebuilt `frontend/dist`; documentation and local review scripts/artifacts.

No database reset, destructive migration, source deletion, Docker installation or remote deployment occurred. The existing additive source-scope audit table was reused; no schema change was required.
