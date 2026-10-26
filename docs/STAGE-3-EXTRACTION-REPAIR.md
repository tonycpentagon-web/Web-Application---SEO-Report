# Stage 3 extraction repair — live-file verification

Verified 16 October 2026. All five existing uploads completed through real Laravel queue/Python execution. None is currently Failed. Three PDFs conservatively remain partially_extracted; AI Visibility and Key Events remain needs_review. Stage 4 has not been started.

## Exact root cause

Original Keyword Ranking run: 01m51sk4912z21ycw5gyrc9j2b. Failure time: 07:33:32 UTC.

    PDOException: SQLSTATE[HY000]: General error: 5 database is locked

The failure occurred in ProcessReportDocument::handle while updating document_extraction_runs to Processing. Python had not started; started_at was null. The claim transaction was outside try/catch, so failed() replaced the useful exception with a generic failure/timeout message.

Three workspace queue workers were running against SQLite, configured with DEFERRED transactions and DELETE journaling. Concurrent read-to-write upgrades caused contention. The original failed_jobs row and exception remain retained. Its historical extraction attempt now displays a specific safe diagnosis and preserves the original generic message in event history.

The other original documents completed in 5–64 seconds. There is no evidence that the failed ranking job exceeded its Python timeout or memory limit.

## Repairs

- SQLite defaults: WAL journaling, IMMEDIATE transactions and 5000 ms busy timeout. Write reservations are acquired before transaction reads. Claim/result/failure transactions use up to five bounded concurrency attempts. An independent-process writer regression test passes.
- Claim failures now enter the same error handling as processing/storage failures. User-facing errors distinguish database contention, other database failures, queue timeouts, exhausted deliveries and malformed JSON, without exposing SQL or bindings.
- Database errors from progress callbacks retain their identity instead of being relabeled as unavailable Python.
- PDF tables retain original parser_rows, cell bounding boxes and native-coordinate cell text. Native text preserves underscores/glyph order that table reconstruction distorted. Empty cells remain empty; source rows are not removed or filled from adjacent text.
- Native text pages are reused for cell clipping. Identical-image OCR is cached within each attempt while preserving every evidence occurrence and source record.
- Table-parser failures use a pdfplumber fallback and cannot skip page previews, OCR fallback or later pages.
- Image evidence includes nearby source-heading text/coordinates and full page context. Spatial heading associations are explicitly marked for review.
- Document Details retains original previews, Text/Tables/Images/Processing Log, Retry extraction and raw JSON downloads. Warnings now have an accessible summary on the details page, and image cards display source headings.
- The three old workers were gracefully stopped. One replacement local worker is running with the repaired configuration. Use one worker locally with SQLite.

No extraction timeout was increased: Python 900 seconds, job 960 seconds, queue retry_after 1200 seconds. PHP memory_limit remains 512M. Python has no configured Windows OS hard memory cap; document/page/cell/pixel/output bounds still apply. During processing, an observed Python peak working set was approximately 759 MiB; observed PHP process peaks were below 56 MiB. These observations are not a guarantee for every workload.

## Current file results

All current attempts use pentagon-extractor/1.3. Native PDF text, embedded-image occurrence/hash preservation, original hyperlink annotations and private asset checksums passed verification.

| File | Pages / units | Text records | Tables | Images: embedded + previews | URL records | Duration | State |
|---|---:|---:|---:|---:|---:|---:|---|
| Keyword Ranking PDF | 5 | 13 | 6 | 12 + 5 | 2 | 8 s | partially_extracted |
| SEO Work PDF | 6 | 21 | 6 | 67 + 6 | 425 | 10 s | partially_extracted |
| Website Analytics PDF | 5 | 132 | 3 | 96 + 5 | 72 | 48 s | partially_extracted |
| AI Visibility PDF | 6 | 23 | 0 | 11 + 6 | 0 | 56 s | needs_review |
| Key Events PNG | 1 image | 1 | 0 | 1 + 0 | 0 | 4 s | needs_review |

Counts preserve repeated evidence and multiple source representations. URL records are occurrences, not unique activities. Image counts include decorative/cover graphics. Text-record counts exclude additional positioned native text_block records. Table counts include inferred layout/cover grids.

### Keyword Ranking

All 5 pages, 20 numbered primary rows and 50 numbered additional rows retained. Every one of the 74 native keyword-name lines is present in tables, including four alternate/continuation labels. The original 1st Page, 2nd Page and 3rd Page labels are retained.

Six detected table groups, 12 embedded-image occurrences, five previews and both original hyperlink annotations are preserved.

Manual review: one cover-image OCR region has no readable text; one inferred table needs geometry review. Four multiline keyword rows retain their source continuation structure and nullable cells; no ranking was fabricated or assigned to an ambiguous fragment.

### SEO Work

All 6 pages and all 95 numbered activity entries are retained, including repeated-looking rows. Article Submission, Profile Creation, Guest Post, Social Bookmarking, PPT Submission and Image Submission categories are available as source headings/text.

All 140 hyperlink annotations are preserved, covering 95 distinct original targets. The 425 total URL records include different representations; they are not 425 unique activities. Six table groups, 67 embedded images and six previews are retained. Complete annotation targets supplement wrapped native URL text when supplied by the source.

Manual review: one cover-image OCR region has no readable text and one inferred table requires geometry review.

### Website Analytics

All 5 pages and exact native text retained. Source figures include users 4,515; new users 4,383; average session duration 00:01:23; views 6,257; Search Console clicks 2,950; impressions 432,736; CTR 0.68%.

Traffic channel records, MOBILE/DESKTOP/TABLET data and all 20 landing-page entries are present. URL underscores and query parameters survive native cell extraction. Three inferred grids, 96 embedded-image occurrences, five previews and all 12 original hyperlink annotations are retained.

Manual review: 80 image regions yielded no OCR text, one OCR region has low confidence, and three inferred grids need column-boundary review. Some grids include adjacent narrative text. Many no-text regions are graphic/chart fragments, but they are not assumed irrelevant; original images and page previews remain available.

The source date range is August 1–30. The existing August 1–31 reporting period remains unchanged.

### AI Visibility

All 6 pages and native keyword headings retained. Eleven embedded images are preserved: nine search-evidence screenshots and two cover images, plus six page previews. All nine screenshots have the corresponding nearby keyword heading, page and coordinates, as well as full source-page context.

No native structured tables or explicit HTTP hyperlink annotations exist in this source. No table/URL records were invented. Visible search snippets/domains remain in screenshots and OCR text where readable.

Manual review: seven OCR regions contain low-confidence text. Compare numeric/string details with the preserved screenshots.

### Key Events PNG

One image unit processed and the original image retained. Actual OCR matches all supplied validation references:

| Event | OCR count |
|---|---:|
| contact_us | 37 |
| form_submission | 30 |
| Phone_Click | 17 |
| schedule_compression_clicks | 9 |
| Email_Click | 4 |
| Source grand total | 97 |

These values are validation observations, not hardcoded application data. There is no native structured table in the PNG; OCR text, line boxes and original image are retained. OCR still requires review even without a low-confidence warning.

## Preservation and duplicate verification

Exactly five existing document records remain with unchanged IDs, original filenames, source paths, uploader metadata, report/project associations and SHA-256 values. Users, clients, projects, reporting periods, memberships and shares match their pre-repair sorted raw-row hash baseline.

The August period remains Draft, revision 1, August 1–31, with its original ID. No reset, deletion, re-upload or schema migration was performed. Reference/design files were not modified. A private pre-repair SQLite backup and evidence artifacts are under ignored .tools/.

Earlier runs/raw JSON/assets remain retained. Intentional retries created separate historical attempts as fixes were verified; these are not duplicate uploads. Current content ordinals and asset indexes have no duplicates. Replaying every completed current job leaves content and asset counts unchanged.

The original failed_jobs row remains as forensic history. It does not represent the current healthy ranking attempt. All five current attempts are terminal and the document queue is drained.

## Verification results

| Check | Result |
|---|---|
| Laravel | 63 passed, 515 assertions |
| Python | 14 passed, including actual OCR, native URL-cell preservation, blank-cell preservation, duplicate-image source preservation and table-failure continuation |
| React | 28 passed |
| TypeScript / production build | Passed |
| Existing uploaded originals | All five reprocessed through real Laravel/Python execution |
| Independent SQLite writer contention | Passed |
| Exact native text, source images, hyperlinks, assets | Passed |
| Live row preservation and completed-job replay | Passed |

Actual browser visual checks were not automated. MySQL driver testing remains outside this SQLite repair. Use the live interface to review remaining warnings.

## Inspect the results

1. Open http://127.0.0.1:5173/login and use your own account.
2. Open the existing Hann’s Pharmacy project → Reports → August 2026 → Report documents.
3. Open Keyword Ranking: the current attempt is Partially Extracted, 5/5, with text/tables/images available. It is no longer Failed.
4. Review the warnings summary, each content tab, Processing Log and raw JSON. The original failed attempt remains selectable with its specific SQLite diagnosis.
5. Compare the three partial PDFs with their source previews. Completed source-unit counts do not certify that every image region is readable.

One replacement worker is already running. Avoid launching another local queue worker while it is active. For a later manual startup:

    Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
    & ..\.tools\php\php.exe artisan documents:doctor
    & ..\.tools\php\php.exe artisan queue:work --queue=documents,default --tries=1 --timeout=960 --memory=512

Worker output/error logs are under backend/storage/logs/documents-worker-*.log; process/log paths are recorded in .tools/repair-worker.json. General Laravel logs remain in storage/logs/laravel.log. See STAGE-3.md for other startup and installation commands.

## Files changed

- Backend: config/database.php; app/Jobs/ProcessReportDocument.php; new app/Services/DocumentFailure.php; app/Services/PythonDocumentProcessor.php; app/Console/Commands/DocumentsDoctor.php; .env.example.
- Backend tests: Feature/DocumentTest.php; new Feature/ExtractionRepairTest.php and fixtures/hold-sqlite.php.
- Python: engine.py, formats_pdf.py, tests/test_extraction.py.
- Frontend: pages/document-details.tsx.
- Documentation: README.md, Stage 3 guide and verification cross-references, this report.

No Stage 4 screens or metrics transformations were added.
