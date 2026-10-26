# Stage 4 enhancement — secure bulk review

Implemented on 16 October 2026 in the existing application. No Stage 5 work was started. Existing design, routes, report tabs, pagination, individual inspection and Save review remain available.

## Features

- Per-record checkboxes, a Select All Verified header checkbox, Select All on Current Page, Clear Selection and exact selection counts.
- Approve Selected and Reject Selected for authorized project managers/administrators on active Draft reports.
- Approval eligible/excluded counts with specific reasons; final confirmation uses a fresh server eligibility preview.
- Confirmation dialog showing reporting period, selection/eligible/excluded counts, categories, review reason and a required confirmation checkbox.
- Separate explicit source inspection records and per-warning resolution notes. Merely opening a record never verifies it.
- Eligibility filters alongside existing decision/search filters; selections retain exact IDs and revisions across pages in the same section, capped at 250 records.
- Transactional changes, optimistic revision checks, per-record results, retry-safe request keys and full before/after audit history.
- Status updates and success/error results without a full browser-page reload. A section change clears its selection.

## What qualifies as Verified and eligible

Human verified means an authorized reviewer explicitly attested that they inspected the original evidence and checked the **saved effective values**, supplying source inspection notes and a specific resolution for every blocking warning affecting that record. A verification is bound to the source evidence, source hash, effective values, current normalization attempt, report/source fingerprint and warning context.

Select All Verified selects only records that also pass all current approval checks:

1. Internal project access and management permission; active client/project and Draft report.
2. Completed current normalization and an active record belonging to that exact project/report.
3. Current source references, accessible private originals and available referenced content/image assets.
4. Acceptable terminal extraction status: extracted, needs_review or partially_extracted. The latter two require the same explicit inspection and resolution of applicable warnings.
5. Valid normalized values, no effective conflicting KPI observations and no unresolved blocking warnings.
6. A current explicit human verification marker.
7. A Pending decision. Already Approved records are preserved and excluded from redundant approval. Rejected records must be reopened individually as Pending before approval.

Source, OCR, Inferred, machine confidence and historic approval labels never create a verification marker. Existing approvals are preserved without manufacturing retrospective inspection evidence. In particular, the 19 existing approvals remain approved but are not automatically considered newly human verified.

A changed effective value/evidence/warning context invalidates the marker. A fresh normalization attempt conservatively requires renewed verification, even if an old approval survives unchanged observations. Verification history is retained. Repeated identical verification requests reuse the existing marker rather than creating another audit entry.

Unclassified content cannot be approved through this workflow. AI evidence requires a clear saved appearance decision (appears or absent), and keyword records require a valid reported ranking label. Missing evidence, invalid fields, stale data and effective KPI conflicts cannot be waived through a warning acknowledgement. Correct invalid data first; for conflicting observations, correct or reject the conflicting record before verifying the remaining record.

## Warning severity and resolution

Eligibility returns each applicable warning with an effective `info` or `blocking` severity, blocking flag and per-record resolved flag. Original extraction/normalization warning rows are never overwritten or deleted.

Informational warnings include identical duplicate observations, retained unclassified context, unreported optional ranking dates/location and document-level missing metrics that were not fabricated. Explicit error/blocking severity takes precedence. A missing value tied to a specific record remains blocking. Unknown warning codes default to blocking unless explicitly informational.

OCR uncertainty, partial extraction, wrapped/ambiguous URLs, displayed-versus-target URL disagreements, source-value changes and material date-range differences require source inspection and a specific resolution note for each applicable warning. Resolution means the reviewer has checked the actual issue and documented the appropriate interpretation; it is not permission to invent missing values. Leave the record pending or reject it if the issue remains ambiguous.

Example date note: explain that the original covers August 1–30 and why it can be retained as a source observation in the August 1–31 report, or leave it excluded if that difference makes the value unsuitable. Do not claim full-month coverage or change the raw dates to hide the discrepancy.

Inspection notes, review reasons and resolution notes support up to 2,000 characters. Bulk/inspection notes and each blocking resolution require at least 10 characters. Required confirmations are enforced on the server.

## Exact browser walkthrough

1. Open http://127.0.0.1:5173/login and sign in with your existing account.
2. Go to Projects → Hann's Pharmacy → Reports → August 2026 → Open Report Data Review. Direct route:

       http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/data

3. Select the relevant section and, optionally, Pending under Review status. Existing Approved records must remain approved.
4. Click Inspect record. Open the source document or download the screenshot. Compare the actual original evidence to the saved values and source/page references.
5. If a value needs correction, use the existing correction form, choose Pending, enter a reason and Save review. Reopen the saved record before verifying it.
6. Under Human source verification, enter a specific resolution for each blocking issue and source inspection notes. Tick the explicit inspection checkbox and click Record source verification. This records inspection only; it does not approve or change the decision.
7. Close the record and refresh the review section if necessary. Choose Eligible for approval or Human verified under Verification eligibility. New human verification badges and exclusion details appear beside records.
8. Use Select All Verified in the header to select **eligible rows on the current page only**. Select All on Current Page deliberately includes ineligible rows, so you can see why they will be excluded. Individual checkboxes also permit exact selections. No control silently selects the entire database.
9. Use Next/Previous to retain exact selections across pages in this section, up to 250 records. The toolbar shows selection count, eligible count and exclusions. Clear Selection removes every selected ID.
10. Click Approve Selected. A server preview rechecks all exact IDs/revisions. Inspect the report dates, eligible/excluded counts, categories and exclusion reasons. Enter a review reason and tick the approval confirmation. Click Confirm bulk review.
11. Only records still eligible when the transaction executes are approved. Others remain unchanged and return specific exclusion reasons. The page reloads its record data without a browser refresh and clears the selection after a successful request.
12. To reject records, select exact IDs and click Reject Selected. Rejection does not require source verification, but still requires management permission, a current Draft report, matching revisions, reason and confirmation. Already rejected records are excluded from redundant rejection.
13. Open an updated record and inspect Correction and decision history. New entries show Bulk review or Individual review, reviewer/time, reason and before/after values. Existing audit entries remain available.
14. To keep reviewing one record, Save review still supports Pending/Approved/Rejected. Approval now uses the same verification and validation policy as bulk approval. If its values change, save as Pending, verify the saved values, then approve.

Opening/closing Inspect record alone must never turn the Human verified badge on. Client Viewers and read-only executives must not see selection/approval actions. Do not approve live records merely to test the implementation; automated tests exercise those mutations in disposable databases.

## API

All endpoints are under the existing Sanctum/active-account API middleware with project/report authorization and browser CSRF protection.

Base path:

    /api/projects/{project}/reports/{report}/data

| Method | Suffix | Purpose |
| --- | --- | --- |
| GET | /records | Existing paginated list, now with per-record eligibility |
| GET | /records/{record} | Existing inspection, sources/history and eligibility |
| POST | /records/{record}/verification | Explicit saved-value source inspection and warning resolution |
| POST | /records/bulk-preview | Read-only transactional eligibility preview for exact selection |
| POST | /records/bulk-review | Confirmed approval/rejection of eligible exact selection |
| PATCH | /records/{record} | Existing correction/individual decision using the shared policy |

List filters: existing `kind`, `section`, `status`, `search`, `page`; new `eligibility=eligible|ineligible|verified|unverified`. Status remains pending/approved/rejected; no new Needs Review decision was added to the existing model. Source extraction Needs Review remains a separate status.

Verification body (use real ULIDs and the current revision):

```json
{
  "revision": 1,
  "confirmed": true,
  "reason": "Compared the saved values with the original source page.",
  "resolutions": [
    {"warning_id": "<actual warning ULID>", "reason": "Specific source-grounded resolution of this warning."}
  ]
}
```

Use an empty resolutions array when no blocking warnings apply. Warning IDs must exactly match the blocking warnings on this record; unrelated resolutions are rejected. No decision changes during verification.

Bulk approval body:

```json
{
  "record_ids": ["<actual record ULID>"],
  "revisions": {"<actual record ULID>": 1},
  "decision": "approved",
  "reason": "Source evidence and saved values verified by reviewer.",
  "confirmed": true,
  "request_key": "<new UUID for this exact submission>"
}
```

For rejection use `decision=rejected`. `record_ids` must be distinct valid ULIDs, 1–250 entries. Revisions must contain exactly one positive revision for every submitted ID. The bulk preview needs record_ids/revisions/decision only and writes no review decisions.

Responses include report title/period, selected_count, eligible_count, excluded_count, eligible categories and per-record results: ID, kind, status (`eligible` in preview, `updated` or `excluded` in commit), review decision, revision and exclusion reasons. Mixed eligibility returns HTTP 200 with partial results, never silent approval of excluded records.

Malformed requests return 422; unauthorized reviewers 403; unavailable/foreign record IDs 404 with no batch mutation; stale normalization/report conflicts and reused mismatched request keys 409. Cross-client/foreign IDs reject the entire request before any permitted record is changed.

Bulk decisions hold the reporting-period lock, lock selected rows and current source-document rows in stable ID order, and recompute the source fingerprint after locking; each row revision is checked again. Unexpected persistence/audit failure rolls back the entire batch. A client retry must reuse the same request_key and identical payload: the saved response is returned with no duplicated review/audit. A new request against already-approved/rejected decisions also avoids redundant decision audits. Completed requests remain auditable in bulk_review_requests.

Individual API integrations can explicitly attest in the same correction transaction using `verify_source=true` and the matching warning resolutions; the UI uses the separate, clearer saved-value verification step. These fields never bypass source/validation checks.

## Audit and storage

The migration adds record_verifications and bulk_review_requests, and nullable approval_method/review_metadata columns to record_corrections. Legacy correction fields and decisions are unchanged.

Each bulk update records record/report IDs, prior/new decision, reviewer/time/reason, method bulk, original normalized value, any manual override, source reference IDs, revision, request key and full before/after snapshots. Verification notes/resolutions are retained separately, bound to source/value/context hashes. The existing project activity log also records source verification and review actions. Individual decisions use the same audit method with method individual. No plaintext credentials, authentication changes, source rewrites or normalized-value fabrication were introduced.

## Installation / execution in this checkout

The additive migration is **already applied locally**. On another checkout or deployment, run migrations with the existing compatible PHP and environment; do not reset the database or replace .env.

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

Bulk review is synchronous and does not need a new queue worker. Keep the existing single documents worker for extraction/normalization. Never start a second local SQLite worker just to review records. No new dependencies or environment secrets are required. Deployment frontend build remains `npm.cmd run build` from frontend/.

## Preservation verification

A private database backup and row/file checksum baseline were captured before implementation. After the additive migration, every original row's existing fields matched, including all normalized values, approvals, corrections, evidence references, warnings and project audit history. New nullable correction columns are excluded from comparison only when null; legacy values are fully compared.

| Existing data | Unchanged count |
| --- | ---: |
| Users / clients / projects / reporting periods | 1 each |
| Uploaded originals | 5 |
| Extraction attempts / extracted content / assets | 18 / 4,273 / 801 |
| Normalization attempts / active normalized records | 4 / 1,205 |
| Existing source references | 1,313 |
| Existing manual correction/decision history | 19 |
| Existing warnings across normalization history | 75 |
| Project audit events | 58 |
| **Approved / Pending decisions** | **19 / 1,186** |

Original private files and reference/design files matched their checksums. No live source verification markers or bulk review submissions were created during implementation. No remaining live records were automatically approved. Read-only eligibility was exercised against the real ten overview metrics; existing approvals were correctly preserved/excluded, and absent inspection markers did not qualify as verified.

## Tests and limitations

| Check | Result |
| --- | --- |
| Full Laravel suite | **82 passed, 672 assertions** |
| Final targeted Laravel review/normalization tests after source-lock hardening | **19 passed, 157 assertions** |
| React suites | **44 passed** |
| TypeScript check | **Passed** as part of production build |
| Vite production build | **Passed** |
| Additive migration | **Applied locally** |
| Live preservation | **Passed** |
| MySQL 8 runtime | Unavailable; unverified on this machine |
| Actual browser visual checks | Not automated; walkthrough above |

Eleven bulk-review backend tests cover explicit verification, OCR/inferred records, blocking/informational warning treatment, source/asset availability, value validation, effective conflicts, permission checks, real cross-client IDs, malformed requests, preview/partial outcomes, duplicate/replayed requests, full audit data, existing approvals, pagination/filters, stale revisions/source snapshots and rollback after the second audit write fails. Existing normalization tests continue to exercise individual corrected approvals and rerun preservation using the explicit attestation requirement.

Ten frontend bulk tests cover exact single/multiple selection, current-page-only verified selection, cross-page IDs, excluded rows, required confirmation/reason, partial results, cancellation, rejection, eligibility/status filters, read-only permissions, source verification/resolution payloads and HTTP conflict feedback. Existing individual Save review tests still pass.

Repeat tests:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan test
Set-Location ..\frontend
npm.cmd test
npm.cmd run build
```

The Python extraction/normalization engine was not modified by this enhancement; backend regression tests still invoke the real Python processor. Human attestations are auditable reviewer statements, not an automated guarantee of document truth. No database-engine load/concurrency benchmark or client-facing Stage 5 dashboard is claimed.

## Modified and added application files

- backend/database/migrations/2026_10_16_000007_create_bulk_review_verification.php — additive inspection/idempotency/audit schema.
- backend/app/Services/RecordReview.php — shared validation, verification, eligibility and audit policy.
- backend/app/Http/Controllers/BulkRecordReviewController.php — explicit verification, preview and transactional bulk actions.
- backend/app/Http/Controllers/ReportDataController.php — list/inspection eligibility, filters and shared individual review policy; value validation moved into the shared service.
- backend/app/Models/RecordCorrection.php — new audit metadata cast.
- backend/routes/api.php — authenticated scoped endpoints.
- backend/tests/Feature/BulkRecordReviewTest.php — bulk workflow/security/audit/concurrency regression tests.
- backend/tests/Feature/NormalizationTest.php — explicit source fixture and attestation in individual approval regressions.
- frontend/src/components/record-review.tsx — source verification, selection toolbar, confirmation and result handling.
- frontend/src/pages/report-data.tsx — table selection, filters, inspection integration and audit-method display.
- frontend/src/lib/report-data.ts — eligibility/bulk response types.
- frontend/src/styles.css — responsive additions using existing styling.
- frontend/src/test/bulk-review.test.tsx — selection, confirmation, exclusion and verification interaction tests.
- docs/STAGE-4-BULK-REVIEW.md, docs/STAGE-4.md, docs/VERIFICATION.md and README.md — handover and current documentation links.

Private .tools/ backup, preservation and read-only diagnostic scripts are local ignored artifacts, not application endpoints or production fixtures. No reference document was changed.