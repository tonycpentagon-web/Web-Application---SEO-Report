Latest: [Stage 3 extraction repair and live-file results](STAGE-3-EXTRACTION-REPAIR.md). Earlier verification below is historical.

# Stage 3 verification — 16 October 2026

The implementation and automated verification are complete for Stage 3. Human review of extraction warnings is still required; no Stage 4/5 dashboard conversion was added. Full setup, file inventory, schema, endpoints and browser steps: [STAGE-3.md](STAGE-3.md).

## Executed checks

| Check | Result |
|---|---|
| Laravel suite | 60 passed, 501 assertions |
| React/Vitest suite | 28 passed across 2 files |
| Python extraction suite | 11 passed, including actual CPU OCR and legacy XLS |
| TypeScript + Vite production build | Passed via npm run build |
| Additive migration | Batch 5 applied; all migrations Ran |
| Python doctor | PDF, DOCX, XLSX, XLS, CSV, PNG, JPEG, WEBP available; RapidOCR installed, Tesseract absent |
| HTTP integration | Actual multipart five-file batch, real database queue worker, Python/OCR, CSRF/session auth, original/raw downloads passed |
| Failure/recovery | Missing interpreter failed a real queued attempt; explicit retry completed. Invalid/corrupt/duplicate inputs, stale attempts and removal tested |
| Preservation | All original live rows matched pre-change SHA-256 snapshots; all five source files unchanged |

The HTTP run used a separate random SQLite database and private temporary storage, not the live August report. All reference-file extraction below went through actual Laravel queued jobs and the Python process. Internal parser tests also generate real multipage PDFs with a ruled table, Word documents with media, workbooks with formulas/charts/multiple sheets, legacy XLS, UTF-16 CSV and an OCR image. They are not universally mocked extraction responses.

## Real reference results

Every page of all four PDFs was processed (22 pages total), plus the screenshot. No document failed entirely. PDF table counts include inferred text-position tables; they are evidence requiring review. Images count every occurrence, including repeated logos, plus one rendered preview per PDF page. URLs include repeated occurrences and may be found in multiple representations; no silent deduplication occurs. Text blocks in this summary means page text/heading/paragraph/OCR/worksheet-text records; positioned native PDF text_block records are additionally retained in raw JSON/database and are not part of that summary count.

| Source | Pages / units | Text records | Tables | Images (embedded + previews) | URLs | OCR | Status |
|---|---:|---:|---:|---:|---:|---|---|
| Website analytics PDF | 5 | 119 | 3 | 101 (96 + 5) | 72 | RapidOCR | partially_extracted |
| AI visibility PDF | 6 | 21 | 0 | 17 (11 + 6) | 0 | RapidOCR | needs_review |
| SEO work PDF | 6 | 19 | 6 | 73 (67 + 6) | 425 | RapidOCR | partially_extracted |
| Keyword ranking PDF | 5 | 10 | 6 | 17 (12 + 5) | 2 | RapidOCR | partially_extracted |
| Key Events PNG | 1 image | 1 | 0 | 1 (1 + 0) | 0 | RapidOCR | needs_review |

### Warnings and failed content

No parser-level page errors were recorded in this run. OCR no-text regions are unresolved image content, so their documents conservatively remain partially_extracted. Some regions may be decorative graphics; no assumption that they are irrelevant is made. Low-confidence output is retained for comparison with its source image. These reports are not certified as completely read.

- **Website analytics PDF:** ocr_no_text: 80, table_geometry: 3, ocr_low_confidence: 1 Parser errors: 0.
- **AI visibility PDF:** ocr_low_confidence: 7 Parser errors: 0.
- **SEO work PDF:** ocr_no_text: 1, table_geometry: 1 Parser errors: 0.
- **Keyword ranking PDF:** ocr_no_text: 1, table_geometry: 1 Parser errors: 0.
- **Key Events PNG:** No warnings recorded; OCR output still requires review. Parser errors: 0.

The analytics file retains users, clicks, impressions, CTR and landing-page text/tables. The ranking report retains keyword/rank labels and source tables. The work report retains activity headings and submission URLs. AI visibility has no reliable structured table; its screenshot evidence is retained with page numbers and source-page context rather than invented metrics.

Key Events OCR retained contact_us 37, form_submission 30, Phone_Click 17, schedule_compression_clicks 9, Email_Click 4, and the source grand total 97. These are unverified OCR observations, not normalized dashboard data. The screenshot itself labels August 1–30; the existing reporting period remains August 1–31. Source text was not rewritten to force agreement.

## Live data preservation

Before changes, a SQLite backup and sorted raw-row SHA-256 baseline were saved privately under ignored .tools/. After applying the additive migration, the same original row IDs and all original field values were checked again:

- Users: 1 unchanged.
- Clients: 1 unchanged.
- Projects: 1 unchanged.
- Reporting periods: 1 unchanged.
- Original project activity: 1 unchanged.
- Memberships/shares: no original rows existed; none were reset.
- Reference reports: all five SHA-256 checksums unchanged.

The existing August period ID is 01m51pnrwse3samzav60f4p6cf, dates 2026-08-01 through 2026-08-31, status draft, revision 1. No live document was automatically imported. The project/client names and IDs were preserved exactly, including their existing differences. design-reference/ was not changed.

Private evidence files from the initial direct extraction and the machine-readable HTTP result remain under .tools/ for local diagnosis; the disposable HTTP database/storage were removed after testing. The source reference files remain in their original folder.

## Operational limits and remaining checks

- MySQL 8 was not available, so integration verification used SQLite. The migration is written with MySQL-compatible schema types/constraints, but actual MySQL execution remains unverified.
- Real browser visual/PDF viewer/mobile interaction was not automated. React tests cover user actions and HTTP tests cover sessions/uploads/downloads. Follow the browser walkthrough in STAGE-3.md.
- RapidOCR is verified locally; Tesseract is optional and not installed. On a clean checkout Python/requirements must be installed and DOCUMENT_PYTHON set.
- The bundled XAMPP PHP is too old; portable PHP 8.4 is required here. Windows lacks Unix pcntl job alarms; the Symfony-managed Python subprocess timeout is enforced, and current-run fencing protects retry results. Production worker supervision/resource isolation is still needed.
- Word page pagination, native Office chart rendering/formula recalculation, complete legacy XLS embedded-object interpretation, malware scanning, an OS parser sandbox, hard deletion/retention scheduling and resumable uploads are not implemented.
- Removal revokes access but retains originals and attempts for audit. Configure a separate retention/erasure policy before treating removal as deletion of sensitive evidence.
- Metric normalization, correction/approval editing, generated client charts and dashboard conversion remain for later requested stages.

## Reproduce

Use the test/startup commands in STAGE-3.md. The real integration script is docs/document-smoke.mjs. It uses a free loopback port and removes only its generated temporary directory after verifying the cleanup target is inside the OS temporary root. Never run destructive database-reset commands against the application database.
