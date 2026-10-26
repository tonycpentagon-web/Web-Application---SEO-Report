# Stage 6 PDF refinement — design and verification report

Completed 19 October 2026 in the existing Pentagon Insight Flow checkout. This is a Stage 6 improvement, not Stage 7 deployment work.

## Result

The primary Hann's Pharmacy August 2026 **Client-style Draft Preview is now 27 pages**, compared with the original 46-page Draft: 19 fewer pages, approximately 41% shorter. All 218 current business observations remain represented, including 70 keyword records, 95 SEO activity observations, nine evidence screenshots, the KPI values and reported source dates.

A separate **444-page Internal Audit Export** preserves the full 1,205-record normalized registry, 1,313 retained source references, 21 correction/review entries, five human-verification entries, 75 historical validation warnings and 65 report activity entries. Its size reflects the complete underlying extraction/normalization and review history rather than just the 218 dashboard observations. It is deliberately separate from the concise preview.

Existing clients, accounts, reports, uploaded originals, review decisions, prior PDF artifacts, publication/share records and audit history were preserved. Hann's report remains **Draft, revision 1** and publication/client sharing remain blocked. No record or commentary was approved automatically.

## Inspection before changes

Reviewed the existing Blade PDF template, Node/Playwright renderer, `ReportDelivery` projection/readiness service, source-bound summary fingerprint and commentary approval controller, normalized-record/source/correction models, internal access and review permissions, export queue job, client download selection, migrations, cleanup behavior, Laravel tests and React delivery screens/tests.

The former export repeated review requirements and document metadata beneath each table row and forced a page break before every section. This created avoidable length. Saved commentary did not carry an explicit approval-state label into its PDF snapshot. SEO category counts and suspected duplicate observations lacked a concise presentation.

## Layout and content changes

- Consistent navy/teal Pentagon styling, print typography, section numbering, KPI cards, subtle table striping, source keys and chart containers.
- A redesigned cover identifies the client, website, report title and period, Draft/Published status, export profile and generation timestamp with its timezone. Client logos remain supported; Hann's currently has no configured logo, so its name is shown without an invented logo.
- Executive commentary now carries a frozen `summary_state` and revision. Current approved source-backed commentary is identified as approved. Missing commentary is explicitly explained. Saved pending/outdated commentary is labeled provisional or requiring renewed source review. Legacy Draft payloads with unknown approval state are treated conservatively. No narrative, insight, comparison or performance claim is generated to fill a gap.
- The Executive Summary and KPI overview can share a page. Ordinary sections flow naturally, avoiding forced mostly empty pages. A separate source appendix replaces repeated evidence paragraphs below each record.
- KPI cards use the normalized values and units. Missing values remain unavailable/not reported; conflicting values are not guessed. Formatting retains the actual normalized precision rather than rounding all decimals to two places.
- Traffic and device charts preserve reported observations. The 4,523 listed channel-user observations are not substituted for the separately reported 4,515 GA4 total users.
- Keyword tables retain keyword, reported ranking label, category, date/location where supplied and evidence keys. “1st Page” remains a page-group label, never an invented exact position. Missing dates/locations are marked Not reported. All 70 records are retained.
- SEO activity charts count all 95 source observations across six reported categories: article submission 10, profile creation 30, guest post 10, social bookmarking 30, PPT submission 10, image submission 5. Four observations share two identical reported URLs. Those observations remain present and are labeled suspected duplicates. No claim is made that a listed URL is an independently verified live backlink.
- Activity URLs have escaped break opportunities after natural delimiters; no source URL characters are removed. Tables use dedicated column widths, repeated headers and row-level break protection.
- AI evidence has a full-width screenshot per evidence page, with query/platform, recorded appearance decision and attribution. Unreviewed evidence explicitly says it is not a verified visibility claim. All nine raster images retain their embedded pixel dimensions and displayed aspect ratios. Screenshots are not replaced, edited or inferred.
- Key Events includes a source-count chart and factual summary: contact_us 37, form_submission 30, Phone_Click 17, schedule_compression_clicks 9, Email_Click 4; sum 97. These are not automatically described as verified leads/conversions. The separately reported event total remains its own metric.
- Chromium explicitly waits for font readiness and every embedded image to decode before printing. Renderer failure produces an export failure and retry action instead of silently treating a broken embedded image as successful rendering.

## Export profiles

In the reporting period's **Report Delivery → PDF export**, choose:

| Profile | Contents and behavior |
| --- | --- |
| Client-style Draft Preview (`client_preview`) | Executive-first layout, readable tables/charts, provisional labels, concise warning overview, complete business-observation/source-key mapping and source catalogue. Private and visibly watermarked. Selected sections remain available. |
| Internal Audit Export (`internal_audit`) | All report sections plus full registry IDs/effective data/source keys/hashes, retained source references including quotes and extraction/normalization IDs, correction history, human verification, complete warning history, commentary revision/approval history, document manifest and report activity history. Always includes every section. Private and visibly watermarked. |

The default for new Draft export requests is `client_preview`. The new nullable `report_pdf_exports.profile` field identifies new artifacts without rewriting historical rows. Existing exports display **Legacy layout** in history. Completed historical PDFs are never regenerated or overwritten. Legacy failed exports retried without captured audit history explicitly state that the old payload lacks that history; it is not fabricated or retroactively filled.

The API accepts the optional profile on the existing internal export endpoint:

```json
{"type":"draft","profile":"client_preview"}
```

```json
{"type":"draft","profile":"internal_audit"}
```

Internal Audit Export cannot be requested as a published export. Publication-created PDF artifacts are always client-profile artifacts backed by the immutable publication snapshot. Public/account client download selection additionally excludes audit-profile artifacts; legacy published artifacts remain supported.

The report delivery screen shows profile labels in export history. Its table now has readable column widths, unbroken action labels and local-format generation timestamps. On narrow screens the table scrolls within its panel rather than widening the page.

## New Hann's Pharmacy artifacts

| Artifact | Export ID | Pages | Size | Result |
| --- | --- | ---: | ---: | --- |
| Client-style Draft Preview | `01m59arnjgfhqy6gr59jyj8cqv` | 27 | 735,834 bytes | Completed |
| Internal Audit Export | `01m59arp8vhdqgjc7hpewhn4ke` | 444 | 2,278,945 bytes | Completed |

Preview SHA-256: `5ae68278436144a104b54e6246827c22e86288455f70c56df317b0ed6c147be1`

Audit SHA-256: `3cfd47f7617dcb545a3033dc6f592b6994b1afac7f34f7c38600c9eb525a5d1c`

Download through authenticated [Hann's August Report Delivery](http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/delivery) → PDF Export History. The files reside in private storage:

- `backend/storage/app/private/exports/01m59arnjgfhqy6gr59jyj8cqv/report.pdf`
- `backend/storage/app/private/exports/01m59arp8vhdqgjc7hpewhn4ke/report.pdf`

Earlier layout iterations remain in history; no export or audit entry was removed. No public share URL was created.

## PDF verification

Both new profiles were generated by the actual Laravel `exports` queue with the local Playwright/Chromium renderer using installed Edge. Source data was captured through the existing authorized internal export controller with the existing Super Admin as the actor; its credentials were not changed.

| Inspection | Client Preview | Internal Audit |
| --- | --- | --- |
| All nine report sections present | Passed | Passed |
| All expected KPI values retained | Passed | Passed |
| All 70 keyword strings retained | Passed | Passed |
| All 95 activity URLs retained, including duplicate observations | Passed | Passed |
| Nine source screenshots embedded | Passed | Passed |
| Image pixel dimensions retained | Passed | Passed |
| Image aspect ratios retained | Passed | Passed |
| DRAFT text on every page | Passed | Passed |
| Source dates including August 30 coverage preserved | Passed | Passed |
| Missing commentary explicitly explained | Passed | Passed |
| Unreviewed AI evidence not represented as verified visibility | Passed | Passed |
| Keyword table headers repeated on continuation pages | Passed | Passed |
| Text outside page margins | None detected | None detected |
| Near-empty text pages under the inspection threshold | None detected | None detected |
| Missing audit record/reference/review-history IDs | Not applicable | None detected |

PDF extraction normalizes whitespace when comparing wrapped keywords/URLs; this checks completeness across natural line breaks without demanding that a long URL fit on one line. The margin test checks extracted text bounding boxes. The near-empty-page check excludes the designed cover and image-bearing evidence pages and flags text-only pages under 240 useful characters. These automated checks are supplemented by visual inspection; they are not a universal mathematical proof against every possible layout issue in future datasets.

Rendered and visually inspected representative cover, summary/KPI, keyword, SEO activity, AI evidence and event-chart pages. Screenshots are retained privately under ignored `.tools/pdf-refinement-*.png`. Representative desktop/mobile delivery screenshots are under `.tools/pdf-refinement-browser/`.

## Automated and browser tests

| Check | Result |
| --- | --- |
| Full Laravel suite | **109 passed, 1,126 assertions** |
| Full React suite | **66 passed, six files** |
| TypeScript checks | Passed |
| Frontend production build | Passed; 2,271 modules |
| Actual Draft and Published renderer tests | Passed |
| Actual renderer failure → retry with frozen audit profile/payload | Passed |
| Simultaneous publication, snapshot history and security regression tests | Passed |
| Desktop 1440px and mobile 390px browser checks | Passed; no runtime exceptions or page overflow |
| Existing database rows, originals, old PDF artifacts and reference files | Preserved |

New test cases cover profile selection, audit completeness including inactive/rejected fixture records, retained duplicate URLs, commentary approval-state distinctions, no audit content in published snapshots, invalid/published audit profile rejection, client denial, escaped malicious URL text and retrying a real failed renderer while retaining the original profile/data despite later fixture edits.

The actual renderer-failure test intentionally points the renderer at a nonexistent binary in a disposable fixture, checks failure state, restores Node, retries the same export and confirms successful generation from its frozen audit payload. Existing retry, duplicate-job, cleanup, account/share permission, token expiry/revocation, rate-limit, snapshot and publication safeguards continue to pass.

Browser verification uses the real read-only delivery response and an isolated Example Pharmacy client-view fixture with API mutations blocked. At both viewport sizes the new profile selector works, Draft export remains enabled and Publish/Create Share remain disabled. The client view has no admin navigation. These checks do not claim a real client publication or a new end-user login session.

The existing dashboard polling test emits React act-environment warnings but passes; no new runtime/browser errors were observed.

## Integrity and security

A fresh baseline/SQLite backup was taken before the changes. Verification compared all original field values for **7,809 existing rows**, plus SHA-256 checksums for **14 existing original/reference/PDF files**. Every comparison passed. The baseline includes existing users, clients, projects, reports, extraction and normalization records, source references, review decisions, verification markers, commentary/publication/sharing structures, exports and audit history. Framework caches/jobs/sessions and the migration ledger are excluded from the row-preservation comparison. Only additive migration/export/audit/queue activity was intentionally created.

Current live state is unchanged: Draft revision 1, 21 approved and 1,184 pending normalized records, five current business-record human verifications, 213 provisional business observations and 20 unresolved blocking warning IDs. Commentary remains missing. There are no new publications or share links.

Existing Sanctum/internal role checks, project/report ownership, immutable publication snapshots, source verification, commentary approval, readiness checks, token hashing/expiry/revocation, download permissions and private storage are retained. Draft profile selection cannot change report status or approve anything. Audit payloads are frozen only for private Draft exports and excluded from normal client-facing snapshots. The renderer remains offline, with page JavaScript and service workers disabled and external/local-file fetches blocked. HTML source text/URLs are escaped before rendering. No paid service is used.

## Files and migration

Added:

- `backend/database/migrations/2026_10_19_000002_add_pdf_export_profile.php`: additive nullable indexed profile field; no destructive rollback.
- `backend/app/Services/ReportPdfPresentation.php`: deterministic export presentation, source-key catalogue, observation counts, duplicate labels and frozen internal audit capture.
- `backend/resources/views/reports/partials/{style,chart,table,sources,audit}.blade.php`.

Updated:

- `backend/resources/views/reports/pdf.blade.php`: redesigned report.
- `backend/app/Services/ReportDelivery.php`: frozen commentary approval-state metadata; existing readiness decisions unchanged.
- `backend/app/Http/Controllers/ReportDeliveryController.php`: validated profiles, private audit capture and profile audit metadata.
- `backend/app/Http/Controllers/ClientReportController.php`: excludes audit profiles from client PDF selection.
- `backend/app/Jobs/GenerateReportPdf.php`: passes frozen export profile with a conservative legacy fallback.
- `backend/pdf-renderer/render.mjs`: waits for fonts and embedded image decoding.
- `backend/tests/Feature/ReportDeliveryTest.php`: profile, commentary, duplicate, privacy and real retry tests.
- `frontend/src/pages/report-delivery.tsx`, `frontend/src/styles.css`, `frontend/src/test/report-delivery.test.tsx`: selector, profile labels, readable export history and regressions.
- `README.md` and this verification report.

## Local operation and retesting

The application URLs remain unchanged. A combined local SQLite worker should process both documents and exports; do not run duplicate SQLite workers. After changing PHP job code, gracefully restart the previous worker before launching its replacement:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan queue:restart
& ..\.tools\php\php.exe artisan queue:work --queue=documents,exports --tries=1 --timeout=960 --memory=512
```

Keep the existing API server on `127.0.0.1:8000` and Vite on `127.0.0.1:5173`. PDF job timeout remains 240 seconds, renderer timeout 180 seconds; the 444-page audit rendered in approximately five seconds locally. Actual timings depend on data volume and hardware.

From backend, run `& ..\.tools\php\php.exe artisan test` or `--filter=ReportDeliveryTest`. From frontend, run `npm.cmd test`, `npx.cmd tsc --noEmit` and `npm.cmd run build`. If the local sandbox cannot write Vite's config cache, the same suite can run with `node node_modules/vitest/vitest.mjs run --configLoader runner --no-cache`.

For manual acceptance, sign in with the existing account, open Report Delivery, choose Client-style Draft Preview, export and download it. Then choose Internal Audit Export and repeat. Confirm all records/evidence remain, source periods differ where originally reported, commentary remains missing/pending as appropriate and the Publish/Share controls remain disabled. Do not approve Hann's records to make the tests pass.

Linux deployment, MySQL 8 execution and production load testing were not performed. No Stage 7 deployment work was started. Existing Stage 6 deployment dependencies are documented in [STAGE-6.md](STAGE-6.md).
