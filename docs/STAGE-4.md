Current enhancement: [Bulk review, explicit source verification, warning resolution, API and browser instructions](STAGE-4-BULK-REVIEW.md). Existing approval decisions are preserved; the initial Pending counts below describe the original Stage 4 implementation.

# Stage 4 — source-backed normalization and data review

Stage 4 is implemented and verified against the five existing August uploads. Stage 5 has not been started. See [the normalization verification report](STAGE-4-VERIFICATION.md) for live counts, source values, warnings, preservation and test results.

## Open the new review page

Log in at http://127.0.0.1:5173/login with your existing account. Open Projects → Hann's Pharmacy → Reports → August 2026 → Open Report Data Review.

Direct internal route:

    http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/data

The existing five files have already been normalized. All observations start Pending; no approval, source correction, lead classification or publication was performed on your behalf.

The page has Overview Metrics, Website Analytics, Google Search Console, Keyword Rankings, SEO Activities, AI Visibility, Key Events, Unclassified Data, Validation Warnings and Structured Preview. Source-backed rows, filters, pagination and the preview use backend database APIs.

## Browser walkthrough

1. Open Overview Metrics. Confirm GA4 users 4,515, new users 4,383, views 6,257, session duration 83 seconds (raw source 00:01:23), Organic Search users 2,871; GSC clicks 2,950, impressions 432,736, CTR 0.68 percent. Definitions remain separate.
2. Inspect any record. Original machine observations, effective values, source quotes, page/worksheet/row locations and document links are available. Links select the referenced historical extraction attempt. AI records also provide authenticated screenshot downloads.
3. Inspect Keyword Rankings: 70 groups, comprising 20 primary and 50 additional groups, with four alternate/continuation labels retained. Page labels do not become exact numerical positions.
4. Inspect SEO Activities: 95 numbered source activities across six categories. Listed URLs are not marked as verified live links.
5. Inspect AI Visibility: nine screenshots and queries, with manual brand-appearance decisions. No visibility percentages, AI ranks or traffic figures were invented.
6. Inspect Key Events: original names/counts and reported total. Events are not automatically leads or conversions.
7. Open Validation Warnings. Review the August 1–30 source dates versus August 1–31 reporting period, partial extraction/OCR, multiline keywords, wrapped landing URL and displayed-versus-hyperlink URL disagreement.
8. Managers/Admins may Inspect record → edit value fields → choose Pending/Approved/Rejected → enter a reason → Save review. Correction history preserves before/after values and reviewer ID/time. Immutable definitions, units and source references cannot be reassigned.
9. Re-run normalization and confirm. Queued/Processing/Completed/Failed are actual queue states. Identical source observations retain record IDs, manual field overrides and review decisions. Changed observations keep manual overrides but reopen approval to Pending with a warning.
10. Open Structured Preview. It displays database-backed KPI observations, channels, devices, landing pages, keyword groups, activity counts, event totals and evidence counts. Toggle Approved records only; initially it is empty because no records were approved automatically. Conflicting KPI observations display a conflict instead of an arbitrary value or sum.
11. Client Viewers cannot open these internal data/evidence APIs. Executives with project access can inspect and normalize Draft reports, but approval/correction requires project management permission.
12. Report title/dates/status/revision/sharing remain unchanged. A new extraction attempt, document removal or report-date change marks normalization stale. Refresh normalization before further review.

Actual browser visual/PDF-viewer checks were not automated here. React interaction tests cover navigation, confirmation, evidence routes, editing, decisions, warnings and approved-only preview requests.

## Run the existing checkout

A replacement document worker is already running. Do not start duplicate local SQLite workers. For later startup, use separate PowerShell terminals:

Backend:

    Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
    & ..\.tools\php\php.exe artisan migrate
    & ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000

Frontend:

    Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
    npm.cmd run dev -- --host 127.0.0.1 --port 5173

One queue worker:

    Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
    & ..\.tools\php\php.exe artisan queue:work --queue=documents,default --tries=1 --timeout=960 --memory=512

Normalization shares the documents queue. Python normalization uses standard-library modules and the configured DOCUMENT_PYTHON runtime; Stage 3's parsing/OCR packages remain needed for extraction, not for re-normalizing already-extracted records.

After PHP/config/normalizer version changes, run artisan queue:restart, let the old worker exit, then start one fresh worker. Long-lived workers retain loaded PHP classes. The Python and PHP normalizer version constants must be bumped together when changing algorithm semantics.

Optional authorized CLI rerun:

    Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
    & ..\.tools\php\php.exe artisan reports:normalize 01m51pnrwse3samzav60f4p6cf --actor=1

The actor is an existing active user ID used for permission checks and audit, not a default account or credential. Other installations must supply their own authorized user ID and reporting-period ID. The CLI applies the same workspace/Draft checks as the API.

Diagnostics:

    & ..\.tools\php\php.exe artisan queue:failed
    Get-Content -Encoding UTF8 .\storage\logs\laravel.log -Tail 80

The current worker paths/PID are recorded privately in .tools/stage4-worker.json; stdout/stderr logs are under backend/storage/logs/documents-worker-stage4-*.log.

## Engine behavior and reuse

The classifier uses source content and semantic headers, never exact filenames, client names, domains or fixture values. It supports combined GA4/GSC documents, keyword reports, SEO work, AI visibility, event reports, backlink references, technical-audit recognition and other documents. Unrecognized or unsupported source representations remain accessible.

The deterministic normalizer consumes the current extraction attempts through a private JSON stdin/stdout protocol. It supports native PDF reading streams and structured CSV/Excel/Word tables, varying keyword column order, explicitly reported ranking dates/locations, original numeric strings and zero values. Counts remain counts; CTR remains percent; durations normalize to seconds while the raw duration survives.

Canonical representations avoid counting the same metric from page text, positioned text, OCR and table copies repeatedly. Genuine repeated source rows remain separate. Conflicting metric observations are retained with warnings. GA4 users/sessions and GSC clicks/impressions never share metric identities.

For wrapped URLs, original text is retained. A uniquely corresponding PDF hyperlink target can be used as the effective source URL; differences are warned and both representations are cited. No network/live backlink verification is performed.

AI screenshots are evidence only. Platform names are recorded only when detected in the screenshot OCR. Brand appearance starts unreviewed and is manually correctable. Page labels such as 1st Page stay page groups, with exact_position null. Missing ranking dates/locations remain null.

Backlink/technical-audit classification does not imply all audit layouts can be converted to metrics. Unsupported content is retained for future rules and manual inspection. No external LLM/model service is called; untrusted document text is never executed as instructions.

## Database structure

Two additive, forward-only migrations ran on the existing SQLite database:

- 2026_10_16_000005_create_normalization_engine
- 2026_10_16_000006_create_normalized_data_views

Canonical tables:

| Table | Purpose |
|---|---|
| normalization_runs | Actor, input snapshot/fingerprint, processor version, status, dates, counts/classifications/errors |
| normalized_records | Typed record identity, report/project IDs, immutable machine observation/raw value, sparse manual override, confidence, review state/revision, active flag |
| report_sections | Per-report section mapping and record counts |
| metric_source_references | Document, extraction attempt, content/asset IDs, source location, quote, raw observation |
| validation_warnings | Run-specific missing/conflicting/date/OCR/partial/source warnings |
| record_corrections | Reviewer, reason, timestamp and full before/after audit snapshots |

Read projections named report_metrics, traffic_channels, device_metrics, landing_page_metrics, keyword_rankings, seo_activities, ai_visibility_evidence and event_metrics provide the requested logical structures. They are SQL views over the typed canonical registry, sharing its relational integrity and unique identities. Writes go through normalized_records and the application, avoiding duplicated review/provenance state in separate tables.

Business uniqueness is reporting_period_id + kind + source_key. Source references have their own per-record unique reference key. Report/project association uses a compound FK; documents/content/assets are checked against the authorized run snapshot before any result is committed.

Reruns update the same current record identities and preserve decisions for unchanged observations. Old references and correction history remain available; obsolete records become inactive instead of being deleted. A source change updates the machine observation, retains only genuinely manually changed fields, and reopens approval. Changed/stale inputs cannot commit as the current dataset.

SQL projections include historical/inactive registry rows; always apply report/project/active filters. Effective corrected values come from the model data accessor or API, not source_data alone.

No pre-existing account, project, report, uploaded document, extraction content or asset row was modified. Only new normalization/review data and project audit entries were added. No destructive migration/reset was run. MySQL 8-compatible types/views are used, but this machine has no MySQL 8 integration environment.

## API contracts

All endpoints require Sanctum authentication, an active account and permitted internal workspace access:

| Method | Endpoint |
|---|---|
| GET | /api/projects/{project}/reports/{report}/data |
| POST | /api/projects/{project}/reports/{report}/data/normalize |
| GET | /api/projects/{project}/reports/{report}/data/records?kind=...&section=...&status=...&search=...&page=1 |
| GET | /api/projects/{project}/reports/{report}/data/records/{record} |
| PATCH | /api/projects/{project}/reports/{report}/data/records/{record} |
| GET | /api/projects/{project}/reports/{report}/data/warnings?page=1 |
| GET | /api/projects/{project}/reports/{report}/data/preview?approved_only=0 |

A review PATCH provides review_status, reason, current revision, and optionally editable data fields. Revision conflicts return 409; invalid numbers/URLs/fields return 422. Source IDs, metric keys/definitions/units and verification flags are immutable. Every successful review writes an audit record. Explicit exact keyword ranks and page groups are normalized independently.

A queued run blocks duplicate concurrent requests. A stale run can be explicitly superseded after ten minutes. Normalization uses a 120-second Python process timeout, 240-second job timeout and 32 MB input/output safety bounds. Failed runs preserve the prior dataset. The queue's 1200-second retry_after remains above both extraction and normalization job limits.

## Stage 5 consumption contract

Stage 5 should read the preview or record APIs using approved_only=1 / approved review status, active records and the correct project/report. It must reject stale snapshots, preserve provenance and present unresolved source warnings. Sparse manual overrides are already merged into API data.

Stage 4's default preview deliberately includes Pending observations for internal review. It is not a client-publication endpoint. Existing report metadata publication/sharing behavior is preserved; Stage 5 must enforce data readiness when adding client visualizations.

All current metrics have reported_period_total granularity. No daily time series was synthesized. Keyword page groups do not imply numerical SERP positions. Activity counts represent listed work, events represent reported events, and AI evidence represents screenshots plus review decisions.

## Tests and limits

From the repository root:

    Push-Location backend
    & ..\.tools\php\php.exe artisan test
    Pop-Location
    & .\.tools\python\python.exe document-processor\run_tests.py
    Push-Location frontend
    npm.cmd test
    npm.cmd run build
    Pop-Location

Tests use in-memory/disposable data. The local Python integration test additionally reads the current-upload manifest saved privately in .tools/; it skips if that local fixture is unavailable. Generic tests use other client labels, values, table orders and formats.

Current limitations: rules-based layout coverage, ambiguous wrapped/multiline source cells, existing OCR/partial extraction flags, unavailable exact dates/positions/platforms, no live backlink verification, no blanket source-warning approval, no final client dashboard/Stage 5 charts, and unverified MySQL/browser visual execution. Original evidence remains the review authority.

## Files added and modified

Backend additions: ReportNormalization service, NormalizeReport job, ReportDataController, NormalizeReportingPeriod Artisan command; NormalizationRun, NormalizedRecord, MetricSourceReference, RecordCorrection models; the two migrations and NormalizationTest. API routes were extended.

Python additions: normalization.py, normalize.py and tests/test_normalization.py. Frontend additions: lib/report-data.ts, pages/report-data.tsx and test/report-data.test.tsx. App/report routes and links, source-document historical-run selection, reusable Field numeric step support and existing design-token CSS were extended.

Documentation: this guide, STAGE-4-VERIFICATION.md, current README and verification index. No reference/design files or credentials were changed.
