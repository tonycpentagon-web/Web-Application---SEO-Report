# Stage 5 final review — approval, human verification and provisional display

Reviewed on 16 October 2026. The dashboard remains an **internal Draft Preview**. No Stage 6 work was started and no records were approved, verified, corrected or published during this review.

## Finding

The Provisional labels were intentional. All **ten persisted KPI records are still Approved**, but **none has an explicit human source-verification record**. The nine analytics KPIs also have three applicable unresolved blocking warnings; the reported event total has two. A historical Approved decision is not automatically evidence that the separate human inspection/validation requirements were completed.

The previous card presentation showed only Provisional prominently and put Approved inside Review & sources, which made the approval appear to have been lost. The finalized cards now show the decision, human verification and readiness states together, with an expandable **Why provisional?** section containing the exact server-calculated requirements and a link to the selected record's existing review panel.

## State distinctions

| State | Display and meaning |
| --- | --- |
| Approved + current Human Verified + clear validation/current sources | Approved and Human Verified; no Provisional overlay |
| Approved without a matching human verification | Approved, Not Human Verified and Provisional; approval is preserved |
| Human Verified but Pending approval | Pending, Human Verified and Provisional; verification does not approve |
| Pending without human verification | Pending, Not Human Verified and Provisional; both steps remain outstanding |
| Unresolved blocking warning | Specific unresolved warning count and exact warning requirements; Provisional remains |
| Previous verification does not match current evidence/values/warning context | Human Verification Outdated, with an explicit renewed-inspection requirement; historical verification is retained |

The implementation does not change eligibility, warning severity, approval policy, report status or publication checks. New response metadata explains those checks rather than bypassing them. Current verification is bound to the actual source evidence, effective saved values and normalization/warning context.

## Exact live KPI findings

| KPI | Value | Decision | Human verification | Outstanding blocking warning types |
| --- | ---: | --- | --- | --- |
| GA4 total users | 4,515 | Approved | Not recorded | Date range, partial extraction, OCR |
| GA4 new users | 4,383 | Approved | Not recorded | Date range, partial extraction, OCR |
| GA4 views | 6,257 | Approved | Not recorded | Date range, partial extraction, OCR |
| Average session duration | 83 seconds / 1:23 | Approved | Not recorded | Date range, partial extraction, OCR |
| GA4 Organic Search users | 2,871 | Approved | Not recorded | Date range, partial extraction, OCR |
| GA4 Direct users | 1,599 | Approved | Not recorded | Date range, partial extraction, OCR |
| GSC clicks | 2,950 | Approved | Not recorded | Date range, partial extraction, OCR |
| GSC impressions | 432,736 | Approved | Not recorded | Date range, partial extraction, OCR |
| GSC CTR | 0.68 percent | Approved | Not recorded | Date range, partial extraction, OCR |
| Reported event total | 97 | Approved | Not recorded | Date range, OCR |

There are no conflicting KPI observations in the live dataset. Values and units were read from current normalized records; none was inserted as a dashboard default.

For the analytics KPIs, Why provisional? lists four requirements: explicit human verification plus the three blocking warning resolutions. For the event total it lists three: human verification plus two warning resolutions. Retained unclassified context is informational and is not incorrectly counted as a blocker.

### What the reviewer must do

1. Open the [dashboard](http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/dashboard?tab=overview).
2. On the relevant KPI, expand Why provisional? and use **Complete verification in Data Review**. This opens the exact existing record and review section.
3. Inspect the original source at the linked page/extraction attempt and compare the saved KPI value.
4. Document a specific resolution for each applicable blocker:
   - **date_range_mismatch:** the source covers **August 1–30**, while the report covers **August 1–31**. Explain the appropriate source-period interpretation only if justified; do not silently claim full-month coverage or change source dates to hide the difference.
   - **partial_extraction:** inspect the preserved source pages/images and extraction warnings to establish whether the specific KPI is supported despite the document's partial status.
   - **uncertain_ocr:** compare the reported figures/labels with the original image. Plausible machine output alone is not human verification.
5. Enter Source inspection notes, tick the explicit inspection confirmation and click **Record source verification**. This existing action records inspection/resolutions and **preserves the existing Approved decision**. Do not approve again merely to clear the display label.
6. If evidence remains ambiguous, leave the record provisional and the blocker unresolved. If a correction is actually necessary, use the established correction/review workflow intentionally; this finalization did not make corrections on your behalf.
7. Return to the dashboard or Refresh dashboard. Once current verification and applicable validation requirements are genuinely complete, its Provisional overlay clears automatically without changing the saved KPI value or approval decision.

Recording a reason is an auditable reviewer attestation, not proof manufactured by the application. This review did not fill resolutions or add verification markers for you.

## Keyword, chart and attribution verification

- **70 unique normalized keyword records**: **20 primary + 50 additional**.
- Distribution: **67 Page 1 + 1 Page 2 + 2 Page 3 = 70**. Page labels remain groups, not exact positions. Alternative/continuation labels remain source context and are not counted again as additional normalized keyword IDs.
- Keyword observations link to source pages **2, 3, 4 and 5**; the cover is not falsely counted as a keyword page.
- Five event counts sum to **97**, matching the reported total.
- Device click observations sum to **2,950**, matching the reported GSC click total.
- Traffic chart source observations total **4,523**, while the separately reported GA4 total users remains **4,515**. The chart presents the listed channel observations and explicitly avoids claiming a deduplicated unique-user total. It does not force-match those figures or replace the reported KPI with their sum.
- **326 current business-record source references** were checked for report/project ownership, current extraction attempt, original-file availability and matching content/asset ownership. Referenced original image assets are available. No source-reference errors were found.

Two attribution details were finalized:

1. Chart attribution now includes **all referenced pages/locations** for each document/extraction attempt. It previously retained only the first displayed location when grouping sources by document; the original row evidence was intact, but the footer could be misleading.
2. Dashboard observations now return sources associated with their own normalization attempt, avoiding old historical references being displayed as evidence for a newer observation. The historical references remain in the database and on the existing data-review/history interface; none was removed.

Charts continue to use the normalized record values and existing display filter. No trends, comparisons, rankings or source values were fabricated.

## Dataset and publication state

The live report remains **Draft, revision 1**, August 1–31, 2026. There are 218 business observations, 21 approved decisions and five current human-verification markers across those observations. These are overlapping attributes, not additive groups. All ten KPI approvals are among the approved decisions, while their own verification-history counts are zero.

The full normalized registry still has **1,205 records: 21 Approved and 1,184 Pending**. The dashboard still classifies 213 business observations as provisional. All 25 current normalization warnings remain available, with **20 unresolved blocking warning IDs** across the full report. Publishing/client sharing remains blocked, regardless of whether provisional observations are hidden from the current dashboard view. Existing sharing/status behavior was not bypassed.

## Tests and preservation

| Check | Result |
| --- | --- |
| Full Laravel suite | **91 passed, 781 assertions** |
| Full React suite | **60 passed** |
| TypeScript / Vite production build | **Passed** |
| Existing read-only Edge layout/integration harness | **23 passed, zero runtime exceptions** |
| Live KPI, keyword and source-reference audit | **Passed** |
| Existing row/original/reference checksum baseline | **Passed** |

Regression cases exercise Approved without human verification, verified Pending, fully approved/verified, unverified Pending, invalidated verification after new warnings, visible exact requirements, read-only projection, current-versus-historical source attribution and multi-page attribution. Existing publishing/authorization/approval/correction regression tests continue to pass.

Edge tests render the actual production frontend using intercepted responses exported read-only from the live report; application writes are blocked in that harness. Backend HTTP tests use disposable databases. MySQL 8 remains unavailable/unverified in this environment; no claim of a new real-user browser login test is made.

The preservation baseline covers all existing users, clients, projects, periods/shares, originals, extraction runs/content/assets, normalization records/runs, corrections, source references, warnings, project history, verification markers, bulk requests and summary revisions. Every existing row and original/reference file matched after the work. No new summary, approval, verification or audit event was created in the live application by this task. No migration or extraction retry was required.

Changed application files:

- `backend/app/Services/RecordReview.php`: read-only current/outdated/not-recorded verification metadata.
- `backend/app/Services/ReportDashboard.php`: exact outstanding requirements/warnings and precise current-observation source projection.
- `backend/tests/Feature/BulkRecordReviewTest.php`: state distinctions, unchanged decisions and retained historical evidence tests.
- `frontend/src/lib/report-dashboard.ts`: response metadata types.
- `frontend/src/components/dashboard/shared.tsx`: visible independent state badges, Why provisional? requirements and complete source-page attribution.
- `frontend/src/styles.css`: scoped state/requirements styling.
- `frontend/src/test/report-dashboard.test.tsx`: visible-state, exact requirement and multi-page attribution regressions.

Private audit outputs are retained in ignored `.tools/stage5-final-live-audit.json` and `.tools/stage5-final-source-check.json`. [Updated dashboard screenshot](../.tools/dashboard-ui-browser/desktop-overview.png).