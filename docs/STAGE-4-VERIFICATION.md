# Stage 4 verification — 16 October 2026

Stage 4 is complete. The five existing August 2026 uploads were normalized through the real Laravel documents queue and Python process. Stage 5 has not started. [Setup, APIs, design decisions, review workflow and exact commands](STAGE-4.md).

## Live report and preservation

Existing report `01m51pnrwse3samzav60f4p6cf`, project `01m51mtexy2cda3etrd7ge03t0`, remains Draft, revision 1, August 1–31, 2026. Latest normalization `01m52378ttd8pve22em82c23sw` completed using `pentagon-normalizer/1.0`; its source fingerprint is current, and preview `stale` is false.

Checksums of every pre-existing row in users, clients, projects, memberships, reporting periods/shares, documents, extraction attempts, extracted content and assets match the Stage 4 baseline. All five original file checksums and reference/design file checksums match. Existing account passwords and report status were not changed. No database reset, source replacement or deletion occurred.

The baseline contains 5 uploaded documents, 18 extraction attempts, 4,273 extracted content rows and 801 assets. Normalization is additive. Re-running preserved every normalized record ID/source key/review decision and all 1,313 source references, without duplication. Replaying a completed queue job was a no-op. The private verification artifact reports zero errors.

## Normalized records

| Kind | Active records |
| --- | ---: |
| Report metrics | 10 |
| Traffic channels | 6 |
| Device metrics | 3 |
| Landing pages | 20 |
| Keyword groups | 70 |
| SEO activities | 95 |
| AI screenshot evidence | 9 |
| Event metrics | 5 |
| Unclassified source representations | 987 |
| **Total** | **1,205** |

There are 218 structured business records and 987 retained unclassified source representations. Unclassified content includes alternative text/table/OCR representations and supporting source material; this count does not mean 987 missing metrics. All 1,205 records remain Pending. No production records were automatically approved or edited merely to demonstrate the workflow. Human correction, approval, rejection and retained decisions were tested in disposable databases.

## File-by-file results

| Existing original | Source units | Normalization result | Remaining review |
| --- | ---: | --- | --- |
| Keyword Ranking PDF | 5 pages | 70 keyword groups: 20 primary, 50 additional; page labels preserved and four continuation/alternate labels retained | Multiline rows and alternate labels need comparison to the source. Page 1 is a page group, not an exact position. Unreported ranking dates/location stay empty. Existing extraction remains partial. |
| SEO Work Report PDF | 6 pages | 95 numbered activities: article 10, profile 30, guest post 10, social bookmarking 30, PPT 10, image 5; original source URLs retained | One displayed URL differs from its PDF hyperlink target; both are retained with a warning. Activities are listed submissions, not independently verified live backlinks. Existing extraction remains partial. |
| Website Analytics PDF | 5 pages | 9 GA4/GSC metrics, 6 traffic channels, 3 device rows, 20 landing pages; source dates/metadata preserved | One wrapped landing URL, OCR/partial extraction and August 1–30 versus report August 1–31. GA4 sessions and GSC average position were not reported and are not fabricated. |
| AI Visibility PDF | 6 pages | 9 screenshot evidence records with source assets and associated query headings | Brand appearance and ambiguous platform/context require human review; no invented rankings, traffic or visibility score. Cover imagery is not counted as a search result. |
| Key Events PNG | 1 image | Five original event names/counts and reported total 97, extracted from source OCR | OCR and date-range warning remain visible. Events are not automatically classified as leads. |

Original extraction text, tables, images, URLs, warnings and processing history remain available on Document Details. Normalization does not falsely upgrade extraction statuses or replace raw evidence.

## Verified source values

| Metric | Effective source value | Unit |
| --- | ---: | --- |
| GA4 total users | 4,515 | users |
| GA4 new users | 4,383 | users |
| GA4 views | 6,257 | views |
| Average session duration | 83 | seconds; raw `00:01:23` |
| Organic Search users | 2,871 | users |
| Direct users | 1,599 | users |
| GSC clicks | 2,950 | clicks |
| GSC impressions | 432,736 | impressions |
| GSC CTR | 0.68 | percent, not a fraction of 0.68 |
| Reported key event total | 97 | events |

| Event | Count |
| --- | ---: |
| contact_us | 37 |
| form_submission | 30 |
| Phone_Click | 17 |
| schedule_compression_clicks | 9 |
| Email_Click | 4 |
| **Sum** | **97** |

These values were checked against the actual persisted output, not inserted as production defaults. The classifier uses content/header rules, not client names, expected fixture values or filenames. Separate tests exercise different names, values, spreadsheet layouts and column orders.

## Warnings and limits

There are **25 current warnings**:

| Code | Count |
| --- | ---: |
| date_range_mismatch | 2 |
| missing_value | 4 |
| multiline_keyword_rank | 4 |
| partial_extraction | 3 |
| ranking_context_missing | 1 |
| uncertain_ocr | 5 |
| unclassified_content | 4 |
| url_source_disagreement | 1 |
| wrapped_url | 1 |

Missing-value warnings identify source lines with no same-line ranking; continuation labels are retained separately for review. OCR ambiguity, partial raw extraction and date mismatch are intentionally not suppressed. No daily trend is synthesized from period totals. No exact ranking, backlink verification, brand-appearance decision or lead classification is inferred without supporting source information.

One historical normalization attempt safely failed after a worker retained an older fingerprint implementation. Its audit record remains. The worker was gracefully restarted with finalized code and the subsequent current run completed; no source data was mutated by that failure. Restart long-running workers after code updates as documented.

## Validation

| Check | Result |
| --- | --- |
| Full Laravel suite | **71 tests passed, 574 assertions** |
| Laravel normalization suite after final sparse-override adjustment | **8 passed, 59 assertions** |
| Python extraction and normalization suites | **28 passed** |
| Frontend interaction suites | **34 passed** |
| TypeScript and Vite production build | **Passed** |
| Live queued five-document normalization | **Completed** |
| Rerun/source-reference/idempotent replay checks | **Passed** |
| Existing rows/originals/reference preservation | **Passed; zero verification errors** |
| New additive migrations | **Applied** |
| MySQL 8 execution | **Unavailable; not verified on this machine** |
| Actual browser visual/PDF rendering checks | **Not automated; walkthrough supplied** |

Backend tests cover real Python invocation, source provenance, authorization/project isolation, validation, atomic rollback, correction audit, stale revisions, reruns, source changes, retained overrides/review decisions, conflicting metrics and safe process failure. Frontend tests cover the review tabs, source evidence, correction payload/history, normalization confirmation, warnings, preview and read-only permissions.

## Open and review

Start the existing backend, frontend and exactly one documents queue worker using [the run commands](STAGE-4.md). Log in with your existing account, then Projects → Hann's Pharmacy → Reports → August 2026 → Open Report Data Review.

Direct authenticated route:

    http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/data

Inspect source evidence, correct ambiguous values with a reason, and approve or reject records. Structured Preview reads the database and supports Approved records only; that filter is initially empty by design. Final client-facing dashboard generation remains Stage 5 work.