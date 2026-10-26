# Hann's Pharmacy SEO Test: source completeness verification

Verified locally on 19 October 2026. This report remains a private August 2026 Draft. No approval, publication or client share was created.

## Why 212 became 218

Before this change, the SEO Test report had four PDFs and 212 business observations. The earlier report had those PDFs plus the original Key Events PNG. The PNG supplies five event-name/count observations and one source-reported total: exactly six additional business observations. After authenticated PNG upload and regeneration, the SEO Test report contains 218 business observations. The common PDF files retain their prior counts.

The 987 unclassified source representations are retained in both datasets. They are not 987 missing business KPIs: extracted text blocks, URL annotations, tables, OCR text and visual context can overlap. Total active normalized representations therefore changed from 1,199 to 1,205. Current source references number 1,313. No source unit was assumed to equal one business record.

## Per-document audit

| File | Pages/images inspected | Extracted content | Content mapped to business records | Normalized representations | Business observations | Unclassified | Extraction warnings | Normalization/review warnings | Incomplete processing units |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Hann_s Pharmacy_Keyword_Ranking_Report_August_26.pdf | 5 | 118 | 5 | 183 | 70 | 113 | 2 | 12 | 0 |
| Hann-39_s Pharmacy - SEO Work Report-August‘26.pdf | 6 | 617 | 98 | 614 | 95 | 519 | 2 | 4 | 0 |
| Hann's_-_Pharmacy_-_Website_Analytics_Report-_August _2026.pdf | 5 | 323 | 3 | 358 | 38 | 320 | 84 | 5 | 0 |
| Hann's_Keyword_Ai_Visibility_2026 - Aug- (1).pdf | 6 | 44 | 9 | 44 | 9 | 35 | 7 | 2 | 0 |
| key events-August'26.png | 1 | 1 | 1 | 6 | 6 | 0 | 0 | 2 | 0 |

All 22 PDF pages and the one PNG image have processing entries. Zero incomplete top-level units does **not** establish extraction accuracy: the PDFs remain partially extracted, with OCR and retained image warnings. The audit exposes each unit, extraction attempt, classification, asset count, source period and warning for inspection. Warning counts describe different layers and must not be added as unique business failures.

## Observations retained

| Observation kind | Count |
|---|---:|
| ai_visibility_evidence | 9 |
| device_metrics | 3 |
| event_metrics | 5 |
| keyword_rankings | 70 |
| landing_page_metrics | 20 |
| report_metrics | 10 |
| seo_activities | 95 |
| traffic_channels | 6 |
| unclassified | 987 |

Keyword source labels: 1st Page: 67, 2nd Page: 1, 3rd Page: 2. These are page groups; none became exact positions.

## Key Events evidence check

Uploaded the original `reference-files/key events-August'26.png` through the authenticated, CSRF-protected upload API. Extraction used the existing local OCR pipeline. The source image was also visually inspected without recording an approval or verification decision.

| Source event name | Extracted count |
|---|---:|
| contact_us | 37 |
| form_submission | 30 |
| Phone_Click | 17 |
| schedule_compression_clicks | 9 |
| Email_Click | 4 |

The source itself reports a grand total of **97**. The five counts also sum to 97. Neither the extractor nor commentary hardcodes these values. The screenshot explicitly covers **1-30 August**, while the reporting period is **1-31 August**; the mismatch remains visible and unresolved.

A repeated upload of the identical PNG returned HTTP 422. A second real regeneration retained 218 business observations and created no duplicate active source records. Original normalized IDs, source values, review decisions and manual overrides survived. Generated commentary has two retained revisions, both unapproved.

## Draft commentary and PDF

Seven deterministic sections describe website traffic, GSC, keyword label counts, activity categories, AI evidence, Key Events and data limitations. They use normalized values and explicit missing-data messages; no external AI API, growth inference or unsupported explanation is involved. Latest commentary status: pending approval. Manager edits are preserved by default; explicit replacement creates another revision.

New private PDF: `D:\xampp\htdocs\pentagon-insight-flow\backend\storage\app/private\exports/01m59yfdnpremwv8xz8ykkr0zr/report.pdf`.

Export ID: `01m59yfdnpremwv8xz8ykkr0zr`. 218 business records captured; PDF inspection found 28 pages, nine images, a Draft watermark on every page, all keyword labels and activity URLs, explicit pending-approval commentary and Data Limitations, retained source dates, no detected overflow and no sparse pages. Cover, commentary and evidence pages were visually inspected. Earlier exports remain retained.

## Security and preservation

11,698 pre-existing database rows were checked against the saved baseline. 1,035 original files were verified by SHA-256. The original report, accounts, documents, extraction history, review decisions, verifications, audit logs and earlier exports were retained. Only the SEO Test report's current normalization context/timestamps and its Key Events section count changed as expected during regeneration. Section creation times are preserved. SQLite integrity check: ok; foreign-key violations: zero; duplicate active source identities: zero.

No commentary approvals, publications or public share links exist. Desktop/mobile delivery checks confirmed Publish and Share are disabled. Publication-related and destructive tests used isolated synthetic data.

## Local review links

- [Draft dashboard](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/preview)
- [Internal keyword dashboard](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/dashboard?tab=keywords)
- [Source completeness audit](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/source-audit)
- [Source review](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/data)
- [Commentary editor](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/dashboard?tab=summary)
- [Exports and publication readiness](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/delivery)
- [Monthly comparison](http://127.0.0.1:5173/projects/01m59w68nafbw6q0zpvc5f616m/reports/01m59w94ynyf4xmnj2j7e5e8hy/compare)

## Remaining human review

Resolve the 25 normalization warnings (20 unresolved blocking warnings), inspect partial-extraction/OCR evidence, check missing keyword dates and locations and inspect suspected duplicate activity observations. The 218 current business observations remain provisional. Source-backed generated text must be reviewed and explicitly approved; it never grants source verification.

There is no September source report in this project, so no actual Hann's month-on-month change is shown. Upload genuine next-month sources first. Comparisons require compatible definitions/units and explicit complete calendar-month source coverage. Keyword comparisons require matching keyword/location/category, valid month-associated observation dates, unambiguous records and matching page-group or exact-position kinds. Current August 1-30 coverage cannot be treated as a complete August total for comparison.

Do not interpret listed submission URLs as live/indexed backlinks or unreviewed screenshots as verified AI recommendations. Zero failed processing pages and preserved records do not imply 100% extraction accuracy.

SMTP remains unconfigured; local invitations use the existing private mail log. No real email was sent. Production deployment, Docker, WSL and GitHub work were not performed.

Detailed machine-readable evidence is retained privately under `.tools/report-enhancement/`: before/after audits, dashboard/snapshot capture, PNG upload response, generation/export metadata, browser checks, PDF checks and preservation results.
