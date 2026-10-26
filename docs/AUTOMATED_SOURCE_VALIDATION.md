# Evidence validation and report approval

This implementation runs locally only. No server deployment, Docker installation or original-report reprocessing was performed.

## Root cause

`RecordReview::eligibility` previously required an explicit human source-verification record for every business observation. `ReportDashboard` then required both that verification and a separate Approved record decision. Document-wide warnings such as partial extraction and the presence of any OCR were applied to every linked observation. Unclassified extraction representations appeared alongside business observations. This combination turned reliable native text/table evidence and decorative duplicates into repetitive review work.

The legacy path remains available for historical reports until an administrator explicitly opts that unpublished report into the new validator. Published snapshots are never rewritten or retroactively enrolled by a migration or GET request.

## What changed

New generation and normalization finish by appending an evidence-validation run. They do not approve, reject, correct, publish, or fabricate human verification. Existing reports use **Re-evaluate Validation**, restricted to an authorized administrator and an unpublished Draft/Review report, with explicit confirmation and report-revision checking. Existing extraction results are reused; source re-upload is not required.

Each assessment has one of five statuses:

| Status | Meaning |
| --- | --- |
| AUTO_VALIDATED | Supported native evidence, current provenance, sound types/units, coherent source scope and no unresolved material rule failures. |
| HUMAN_VERIFIED | A genuine, current, explicitly recorded source inspection also satisfies the material validation rules. |
| NEEDS_REVIEW | Ambiguous mapping, OCR, unsupported association/value, unconfirmed source scope or other unresolved evidence issue. |
| INVALID | Invalid values, source/checksum or period/client contradictions, conflicts or failed same-scope reconciliation. |
| NOT_APPLICABLE | Preserved context, explicit rejections or exact redundant representations excluded from reporting values. |

Machine results live in separate append-only assessment tables. Original records, raw values, human verification rows, corrections and old decisions remain intact. A corrected value requires current human verification; evaluation never replaces a manual override. A high confidence score alone cannot validate an observation.

## Evidence and rules

- Bind each observation to its project, report, current extraction, content/image identifier and cited location. Check that originals exist and their SHA-256 matches the stored source checksum.
- Retain raw values and source references; check normalized types and units. Match material numeric/text fields against cited native text or the referenced table row/line range. KPI label/value pairing is required; merely finding the same number somewhere on a page is insufficient. Preserve duration, percent and count meanings.
- OCR-dependent observations and inferred/ambiguous structures require explicit inspection. Native observations from a document that also contains OCR can still validate independently.
- Preserve original ranking labels, page groups, dates and locations. Never turn a page group into an exact position. Missing ranking locations/dates stay Not reported.
- Treat activity URLs as listed source observations, without asserting that links are live/indexed. AI brand-appearance claims require an evidence screenshot and actual source-verification action. Events are reported events, not inferred leads/conversions.
- Detect conflicting values and incompatible explicit metric scopes; preserve every observation without selecting a preferred value. Retain exact duplicate provenance and identify redundant representations without summing KPI duplicates or pretending repeated URLs are unique completed work.
- Compare GSC CTR with clicks/impressions only for a matching document, source location, period-total aggregation and explicit semantic scope. Permit 0.01 percentage-point source rounding. Different source locations/scopes are not reconciled as interchangeable.
- Check same-scope new users <= total users, engaged sessions <= sessions, and clicks <= impressions. Never sum GA4 channel users into unique total users. Reconcile listed event observations with a same-document/page reported event total only where that source grouping is available.
- Preserve exact source periods, including Sept spellings, spaced month titles and spaced two-digit years. Explicit range mismatches block even if the report title says the expected month.
- Partial extraction is a document-level notice, not automatic rejection of every readable row. Failed/truncated source units and missing mandatory metrics/sections remain material report blockers. Unclassified contextual representations are retained outside the task list.

A source with missing/unreadable printed dates can be assigned to the report once through **Confirm source scope**, with authorized reviewer identity, evidence notes and a checkbox attestation. This records document association, not human verification of every value or invented printed dates. It cannot waive an explicit client/date contradiction. The attestation is bound to the source, extraction, report dates and client identity; changing that evidence invalidates it.

Assessment fingerprints include current source files, extraction content/confidence, references, warning context, saved values/corrections, genuine verification history, document-scope attestations and client/report scope. Stale results cannot authorize publication. Bulk and individual approvals cannot waive unresolved material assessment exceptions.

## Staff workflow

1. Select the existing client/project and reporting month, upload source files, and choose Generate SEO Report.
2. Open Data Review. The default is **Requires Attention**. Four cards show Auto-Validated, Human-Verified, Requires Attention and Invalid / Missing. Use All Records or a section filter for optional detail.
3. Resolve material exceptions at their source. Confirm missing document scope once when supported; inspect uncertain OCR/screenshots and save any corrections with notes. Normal review/verification actions refresh the assessment automatically for enrolled Drafts.
4. Preview the complete report and current commentary. Data Review and Delivery show evidence coverage, extraction counts, source issues and missing mandatory data.
5. A permitted Manager/Admin chooses **Approve Entire Report** and confirms their review. One transaction records report-level identity, time, report revision, source/validation binding and commentary revision, then sets Approved. Machine-validated records can remain Pending individual decisions; no per-record human verification is fabricated.
6. Publish explicitly, then create the client link explicitly. Existing immutable publication, public-link consent, revocation, PDF permissions and automatic historical filing remain enforced. A new month reuses the same project with independent documents/content.

Missing commentary must be generated or saved before final approval. Missing data, explicit date/client conflicts, uncertain extraction/value associations and genuine image/brand decisions still need human action. Automation does not make those issues disappear.

## Schema and endpoints

Additive migrations:

- `2026_10_22_000001_add_evidence_validation_and_report_approvals.php`: `report_validation_runs`, `record_validation_results`, `report_manager_approvals`.
- `2026_10_22_000002_add_explicit_source_scope_verifications.php`: `report_source_scope_verifications`.

There is no backfill or automatic evaluation of existing reports. Foreign keys follow the existing explicit lifecycle deletion rules. Assessment summaries preserve before/after results. Manager and source-scope attestations retain actor identity, timestamp, revision/binding and notes.

Authenticated endpoints under `/api/projects/{project}/reports/{report}`:

- `POST /data/re-evaluate`: administrator-only retrospective assessment, confirmed current revision.
- `POST /data/source-scope/{document}`: permitted manager/admin source association attestation, confirmed current evidence binding.
- `POST /delivery/approve-report`: permitted manager/admin whole-report and commentary approval, confirmed current report/fingerprint.
- Existing data summary/records now return validation results and support validation-state/attention filters. Existing GET endpoints remain read-only.

## Real-data findings and verification

All retrospective exercises ran on disposable database/storage copies. The working database still has zero new validation runs and zero new manager approvals; existing clients/reports have not been enrolled without operator action.

Hann's August copy: 160 automatically validated business observations; 987 context representations retained outside the task list. Its analytics and OCR event sources explicitly cover 1-30 August, while the report covers 1-31 August, so publication remains blocked. Remaining inferred mappings, screenshots and uncertain values require genuine inspection.

The actual active Sky Hub record is labelled October 2026, while its documents are September sources. Its analytics covers 29 August-27 September. The mismatch is flagged rather than silently corrected. An additional, separate September-only test copy reused the existing extractions and re-normalized them without re-upload: 94 observations auto-validated; the cross-month analytics and unreadable event scope still block. Source hashes and existing correction/verification history were unchanged.

The six-PDF browser fixture generated 18 auto-validated observations and two exceptions. Only those synthetic exceptions exercised the explicit human-review API. The manager approved the report while its reliable observations retained Pending individual decisions. Browser checks covered charts, actual PDF downloading, authenticated Draft protection, anonymous published access, historical filing, an independent next month and immediate revocation. No actual client was published or approved during these tests.

Artifacts (private local QA evidence):

- [Hann's exceptions](../.tools/automatic-validation/hanns-exceptions.png)
- [Sky Hub exceptions](../.tools/automatic-validation/skyhub-exceptions.png)
- [Exception-first review](../.tools/automatic-validation/exception-review.png)
- [Mobile review](../.tools/automatic-validation/exception-review-mobile.png)
- [Final manager approval](../.tools/automatic-validation/manager-approved.png)
- [Browser results](../.tools/automatic-validation/browser-results.json)
- [September test results](../.tools/automatic-validation/sky-september-result.json)

Final test counts and preservation verification are recorded in `docs/AUTOMATED_VALIDATION_TEST_RESULTS.md`. The source report itself remains unchanged until its operator initiates re-evaluation. No demo/production server or public tunnel was used.
