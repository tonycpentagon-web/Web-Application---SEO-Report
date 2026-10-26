# Hann's Pharmacy — Share Report period fix

The August 2026 report is now published as a frozen snapshot with an active local public link. The user explicitly authorized partial-August publication after reviewing the 1–30 August coverage and missing 31 August. No source value or original file was rewritten.

## Actual root cause

The selected report is **1–31 August 2026**. Both disputed sources genuinely print **1–30 August 2026** in report date selectors. This was not a filename inference or a misread upload timestamp. The old validator treated every unequal endpoint as a blocking mismatch, without distinguishing partial coverage within the selected month from another reporting month. Its OCR fallback promoted an extracted candidate range to a confirmed mismatch.

The old generation was completed on 20 October. Its normalization fingerprint and all five document IDs, extraction run IDs and checksums still matched. Only its evidence-validation fingerprint was stale: it had been assessed with rules 1.0, while the application required newer rules. A combined readiness condition incorrectly described stale checks as changed sources or incomplete generation. The 15 warning groups mixed stale assessment messages with source notes.

A further false positive was found during fresh validation: the client-label regex allowed newlines after an empty `PREPARED FOR:` label and compared the following date with the client name. Client declarations now stay on the same line, preventing that date from becoming a client identity.

## Disputed evidence

| Source filename | Selected month | Actual detected range | Evidence location and method | Meaning and previous blocking reason |
| --- | --- | --- | --- | --- |
| `Hann's_-_Pharmacy_-_Website_Analytics_Report-_August _2026.pdf` | August 2026, 1–31 | 1–30 August 2026 | Native PDF text, page 1 line 5; page 2 line 4; page 3 line 4; page 4 line 2. Visually checked against the date selector on PDF page 2. | Reporting-period header, not metadata. Old rule required exact equality with 31 August. Now retained as partial coverage, with 31 August explicitly missing and employee assignment required. |
| `key events-August'26.png` | August 2026, 1–31 | 1–30 August 2026 | OCR content on image/page 1, lines 1–2; original screenshot visually checked. The range appears in the top date selector and its tooltip. | Reporting-period selector, not screenshot capture time. Old fallback treated the OCR range as confirmed and required exact month-end equality. Now an uncertain extracted date range, preserving 1–30 and requiring explicit assignment; OCR values remain unverified. |

The keyword, SEO work and AI visibility PDFs declare August 2026 in native report titles. The UI identifies these as declared report months rather than pretending their titles print exact daily date ranges.

## Corrected rules and UI

- Read reporting-period headers, standalone header ranges and explicit report-month titles from preserved text/OCR with page/line/content references. Filenames, file properties and upload dates do not establish coverage.
- Ignore creation/upload/print/capture timestamps, chart-axis ranges, previous-period comparisons, activity dates and unrelated narrative dates. Unreliable or unlabelled dates remain uncertain.
- Explicit native report headers outside the selected period remain blocking. Conflicting values, wrong-client/website declarations, changed original checksums and missing source references remain protected. An acknowledgment cannot waive these findings.
- Native coverage contained within a month can be published only with explicit assignment and a partial-coverage disclosure. OCR ranges remain uncertain. OCR suggesting another month must be repaired before assignment.
- One checkbox records employee assignment for all listed uncertain/partial sources together with their disclosed uncertainty. It writes source-fingerprint-bound audit entries and a delivery event; it never writes per-record human verification or approval.
- Sharing shows report/client/month, Preview Report, source coverage, grouped notes affecting seven sections, optional expiration, Share Report and Copy Link. Warnings remain inspectable without repetitive review tasks.
- Unchanged generation with stale evidence checks offers **Refresh report checks**. Changed sources/report dates offer **Regenerate Report**, using existing uploads. Successful generation advances the report revision; validation, summary fingerprints and readiness use the completed sources consistently.
- The immutable public report and PDF prominently retain partial/uncertain coverage, printed dates and missing 31 August. Source citations use current authoritative period evidence rather than stale normalization guesses.

## Hann's result and actual live checks

A real browser session clicked Generate SEO Report using the existing five uploads. The completed Draft opened automatically at revision 7; sharing created the published state at revision 8. There were **zero invalid-value findings** before sharing. Native automatic assessments and provisional OCR/inferred observations retained their labels, with zero newly fabricated human verifications.

The actual Share Report button was clicked after the authorized coverage confirmation, and Copy Link was exercised. A separate anonymous browser context with no employee cookies opened the real local public URL. Charts, 69 distinct keyword observations, 93 distinct SEO activity observations, nine AI screenshots and five key events displayed. Identical display observations are deduplicated with their source citations retained; original rows are preserved.

The public token could not access client/project/employee APIs or private document downloads (401). A random token returned 404. The first live test link was revoked, after which anonymous access returned 404. A distinct active link was then created for the same frozen publication, without duplicating the snapshot. The published PDF downloaded anonymously with HTTP 200; its 25 pages include partial-coverage notices, 1–30 August, missing 31 August and provisional labels.

The public URL is stored privately for Copy Link; it is intentionally not committed into this document. This remains a local Windows application. No Docker or remote deployment was used.

## Test results

The full Laravel run passed **176 tests** with one MySQL-only test skipped (3,116 assertions). After the final source-citation regression was added, the focused delivery/validation run passed **38 tests** (565 assertions), and the final period-detector run passed all six tests. The complete frontend suite passed **116 tests**, the Python suite passed **40 tests**, and TypeScript checking plus the production Vite build passed. The existing large bundle warning remains.

These results include uncertain OCR, metadata/chart/activity date exclusion, partial coverage, confirmed wrong-month/cross-month rejection, stale checks versus changed sources, employee confirmation audit, source-fingerprint invalidation, wrong-client/value conflicts, another-client isolation and immutable publication access. The separate live browser test used the running application and actual Hann's sources, not mocked public responses.

## Preservation and evidence

`local/review/hanns-preservation-final.json` confirms all 20 existing original files across the checkout retain their checksums and document/extraction identities. Clients, projects, unrelated monthly reports, source values, manual overrides, existing review decisions and human-verification history are unchanged. Only the requested Hann's generation, validation, source-assignment audit, publication, PDF and link lifecycle were updated.

Actual live evidence: `local/review/hanns-live-share-results.json`, `hanns-pdf-verification.json`, `hanns-after-generation.json`, and the private before/after database evidence under `local/review`. Screenshots: `hanns-share-confirmed.png`, `hanns-link-created.png`, `hanns-public-desktop.png` and `hanns-public-mobile.png`.

## Generate and copy a link

1. Open Monthly Reports and Hann's Pharmacy → August 2026. For this published report, use **Generated / Shared**.
2. For a Draft, click Generate SEO Report (or the specific Refresh report checks / Regenerate Report action shown). Existing uploads are reused.
3. Preview. If a source has uncertain or incomplete dates, inspect the displayed coverage and confirm assignment once, only if the disclosed limitation is acceptable.
4. Click Share Report. Set a custom expiration if desired; blank uses 30 days.
5. Click Copy Link. For the current published report, use the active link's Copy Link button. Revoke Link invalidates that URL immediately.

The active URL uses 127.0.0.1 and works only from this computer until a separately approved reachable host is configured.
