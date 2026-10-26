# Public report QA - 22 October 2026

The application runs locally at http://127.0.0.1:5173 with the Laravel API at http://127.0.0.1:8000. No deployment or Docker was used.

## Confirmed defects and corrections

1. **Public navigation invalidated the access token.** Public links carry their secret in the URL fragment. Section anchors replaced that fragment; React then requested the report using a section name as the token. Section navigation and the source-details shortcut now scroll without changing the fragment. The regression is covered by a React test and actual mobile browser navigation.
2. **Summary and tables disagreed.** Generated commentary counted the pre-publication 70 keyword and 95 activity rows. Publication deduplication correctly retained 69 distinct keyword observations and 93 distinct activities, merging their citations. Generated publication commentary now uses the exact final publication rows. Manually written commentary is preserved.
3. **Public expanded rows lost citations.** The adapter emptied private source references without providing a public replacement. Public rows now expose frozen document names, readable page/line locations and declared months/printed periods. They do not link to private documents or internal review routes.
4. **Chart units were generic.** Traffic users, search clicks and events were all labelled observations in tooltips. Units now match the chart metric. Donut legends calculate percentages from their displayed counts; bars start at zero and chart height adapts to category count.
5. **Extraction warnings overwhelmed the executive overview and PDF cover.** The overview now has a short limitation notice and a shortcut to expandable full extraction notes under Sources. The PDF retains the full notes in the source appendix. Partial source coverage remains prominent on the cover, including the missing 31 August.

## Presentation improvements, separate from data defects

The executive overview has eight aligned KPI cards, source-backed device/ranking charts and concise highlights. Missing sessions are explicitly labelled Not reported. Activity categories appear before the activity table; full descriptions can be expanded. Thumbnails retain native aspect ratios and enlarge to original image resolution. Source details use readable labels instead of JSON. Existing cyan/navy styling, sticky navigation, search, sorting, category/ranking filters and pagination remain.

All nine thumbnails already loaded correctly before the changes; missing images were **not** reproduced. The audit confirmed no initial document overflow or JavaScript page errors at the three requested widths. These are verified working features, not claimed fixes.

## Original-source reconciliation: Hann's Pharmacy, August 2026

| Statistic | Original evidence | Publication result |
| --- | --- | --- |
| Total users | Analytics PDF p2: 4,515 | 4,515 |
| New users | Analytics PDF p2: 4,383 | 4,383 |
| Views | Analytics PDF p2: 6,257 | 6,257 |
| Average session duration | Analytics PDF p2: 00:01:23 | 1:23, labelled minutes:seconds |
| Sessions | No session-count metric supplied | Not reported; not substituted with users or duration |
| Organic Search users | Analytics PDF p2 channel table: 2,871 | 2,871 |
| Search clicks | Analytics PDF p3: 2,950 | 2,950 |
| Search impressions | Analytics PDF p3: 432,736 | 432,736 |
| CTR | Analytics PDF p3: 0.68% | Preserved; 2,950 / 432,736 x 100 rounds to 0.68% |
| Device clicks | Analytics PDF p3: Mobile 2,396; Desktop 525; Tablet 29 | Same counts, total 2,950; shares 81.22%, 17.80%, 0.98% |
| Keyword observations | Ranking PDF: 70 rows, including repeated additional keyword Fertility Pharmacies Virginia | 69 distinct observations; both repeated source locations retained |
| Ranking labels | Distinct published rows | 66 First Page, 1 Second Page, 2 Third Page; exact source labels remain 1st Page, 2nd Page, 3rd Page |
| Keyword categories | Ranking PDF primary/additional headings | 20 primary, 49 distinct additional |
| SEO activity observations | Work PDF: 95 rows; two repeated social-bookmarking URLs | 93 distinct: Article submission 10; Profile creation 30; Guest post 10; Social bookmarking 28; PPT submission 10; Image submission 5 |
| AI evidence | Original AI PDF pp2-6 | All nine images match extracted original asset SHA-256 checksums; dimensions range 895-1021 x 362-530 pixels |
| Key events | Original event image | contact_us 37; form_submission 30; Phone_Click 17; schedule_compression_clicks 9; Email_Click 4; total 97 |

The original PDF's device donut labels (47.3%, 29.9%, 22.8%) do not match its clicks table. The interactive report uses the explicit table counts and calculated shares, rather than copying those inconsistent artwork percentages. Channel users sum to 4,523, not 4,515 unique users; channel charts disclose overlap and never replace the source-reported unique-user KPI with a channel sum. Landing-page rows remain source observations, not a substitute site-total calculation.

### Coverage and remaining uncertainty

The selected month is 1-31 August. The Analytics PDF explicitly prints 1-30 August on pp1-4. The event image also shows 1-30 August, with OCR dates retained as uncertain evidence. The employee's previously authorized partial-month assignment remains recorded. 31 August is absent and never estimated. Keyword, work and AI covers declare August; a month title does not establish a separate exact ranking day or search location.

Some multiline keyword/ranking associations, displayed versus target submission URLs, wrapped landing URLs and OCR-derived observations remain provisional. AI screenshots remain unverified brand-appearance observations. No approvals, human verification, invented recommendations or month-over-month growth were added. Native source values and extraction records were not edited.

## Immutable publication and links

The corrected report is **publication version 2**, created through the authorized employee Share Report interface using its new explicit Publish an updated version option and current uncertainty acknowledgment. This runs the same source fingerprint, revision, period-assignment and material-validation checks as first publication. Version 1 and its existing active link remain unchanged and accessible; their historical summary retains its historical counts. A separate test link was created and revoked without revoking either active report link.

To copy the corrected URL: open **Monthly Reports > Generated / Shared > Hann's Pharmacy / August 2026 > Share Report**, then **Copy Link** on the newest active link. Another normal share creates a new secure link to the latest publication. Publish an updated version is optional and only needed when intentionally capturing corrected content. Existing links retain their original publication. Current local URLs are stored in `local/review/hanns-public-link.json` (v1) and `local/review/hanns-public-link-v2.json` (v2). No tokens are included in this document.

## Actual verification

- Laravel: **179 passed, 1 skipped, 3,142 assertions**, full suite, 304.97 seconds. Includes new version-preservation/stale-fingerprint/material-check tests and generated-summary tests.
- React: **120 passed**, including public citations, calculated chart shares, explicit updated-publication acknowledgment and token-preserving navigation.
- Python: **40 passed**.
- TypeScript no-emit check and production Vite build passed. Existing bundle-size advisory remains; it did not block the build.
- Actual headless Microsoft Edge, fresh anonymous contexts: every Hann section captured at **1440, 768 and 390 pixels**, no document overflow and no uncaught page errors. All nine original images load and each opens/closes at full resolution. Keyword search, reverse sort, ranking filtering and displaying all 69 distinct rows passed. Mobile section navigation passed after the token fix.
- Both active publication links return HTTP 200 without login. Private source access returns 401; a random token returns 404; the disposable revoked link returns 404. Existing publication rows/hashes compare identically with the pre-work SQLite backup.
- Corrected client PDF downloaded anonymously: **26 pages**, 791,397 bytes. Text-bound checks found no text outside page bounds. Cover, split landing table and original AI-image pages were rendered and visually inspected without clipping. The PDF preserves its frozen values and source appendix.
- SkyHub remains an **unpublished October draft**, so there is no existing public SkyHub URL to test. Its actual staff preview has current normalization, 203 business records and 10 KPIs; it was inspected at all three widths. Anonymous access to its private dashboard returns 401. SkyHub and every other monthly report were unchanged.
- Preservation comparison: clients, projects, all 20 original uploads and checksums, extraction content/assets, normalized records, source references, corrections, record verification, saved commentary, source-period confirmations and unrelated reports unchanged. The new publication, PDF, link and audit events are additive.

Machine-readable results are in `local/review/public-data-audit.json`, `public-interaction-results.json`, `public-after-browser.json`, `public-pdf-audit.json`, `public-preservation.json`, `public-frontend-tests.json`, `public-backend-tests.txt`, `public-python-tests.txt` and `skyhub-regression.json`. The pre-work database backup is `local/review/public-audit-baseline.sqlite`; it was not restored over the live database.

## Before/after screenshots

| View | Before | After |
| --- | --- | --- |
| Desktop overview | [Before](../local/review/public-before-1440-client-overview.png) | [After](../local/review/public-after-1440-client-overview.png) |
| Tablet overview | [Before](../local/review/public-before-768-client-overview.png) | [After](../local/review/public-after-768-client-overview.png) |
| Mobile overview | [Before](../local/review/public-before-390-client-overview.png) | [After](../local/review/public-after-390-client-overview.png) |
| Mobile cover | [Before](../local/review/public-before-390-cover.png) | [After](../local/review/public-after-390-cover.png) |
| Source evidence | [Before](../local/review/public-before-1440-client-sources.png) | [After](../local/review/public-after-1440-client-sources.png) |
| AI images | [Before](../local/review/public-before-1440-client-ai.png) | [After](../local/review/public-after-1440-client-ai.png) |

All sections and all widths have similarly named screenshots. PDF samples: `public-pdf-cover.png`, `public-pdf-landing.png`, `public-pdf-ai.png`. SkyHub screenshots use `skyhub-<width>-report-<section>.png`.

## Modified implementation files

Backend:
- `app/Services/PublicationCommentary.php` (new): factual narrative from final publication rows.
- `app/Services/SimplifiedReportSharing.php`: explicit additive publication versions; generated commentary after deduplication.
- `app/Http/Controllers/SimplifiedSharingController.php`: validated new-publication request flag.
- `resources/views/reports/pdf.blade.php` and `partials/sources.blade.php`: concise cover and full appendix notes.
- `tests/Feature/SimplifiedWorkflowTest.php`, `tests/Unit/PublicationCommentaryTest.php`: publication integrity and summary regressions.

Frontend:
- `components/published-dashboard.tsx`: client overview, preserved-token navigation, readable citations, activity summary and screenshot previews.
- `components/report-source-coverage.tsx`: concise visible partial coverage with expandable document detail.
- `components/dashboard/public-source.tsx` (new), `shared.tsx`, `table.tsx`, `charts.tsx`: public evidence, activity expansion, chart units/percentages/height.
- `lib/report-dashboard.ts`: optional typed public source attribution.
- `pages/report-share.tsx`: optional explicit updated-publication control.
- `styles/report-preview.css`: responsive cards, image/dialog layout and source readability.
- `test/public-evidence.test.tsx` (new), `test/simplified-sharing.test.tsx`: public evidence/navigation/chart and version-selection tests.

Python extraction code was not changed: the audit found no new extraction correction that could safely resolve the remaining uncertainties automatically. Verification scripts, screenshots, SQLite backup and build output are local review artifacts; frontend/dist contains the rebuilt application.
