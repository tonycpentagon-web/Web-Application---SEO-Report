# Stage 3 — document ingestion and extraction

Implemented on 16 October 2026. See [the extraction repair report](STAGE-3-EXTRACTION-REPAIR.md) for the subsequent SQLite concurrency fix and verified live-file retries. Stages 4 and 5 have not been started. Existing authentication, clients, projects, team permissions, report statuses, revision history and sharing remain in place.

## Start the existing checkout

Use three PowerShell terminals. The workspace already has portable PHP 8.4, Python 3.12, installed Python extraction/OCR packages, backend vendor packages and frontend node_modules. Do not replace the existing .env or run migrate:fresh.

Backend:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan documents:doctor
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

Document queue worker (run one worker locally with SQLite; avoid opening duplicate queue-worker terminals):

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:work --queue=documents --tries=1 --timeout=960 --memory=512
```

Open http://127.0.0.1:5173/login and use your own existing account. For a new installation, create an account with the interactive command below; it hides password input and does not overwrite an existing email:

```powershell
& ..\.tools\php\php.exe artisan admin:create
```

No default credentials are seeded or displayed. Existing account-management instructions remain in AUTHENTICATION.md.

## Python installation on another machine

Python 3.11+ is required; the tested runtime is Python 3.12.10 x64. Core parsing works without OCR; image/scanned content is then flagged as partial. Use a virtual environment for a normal Python installation:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow
py -3.12 -m venv document-processor\.venv
& .\document-processor\.venv\Scripts\python.exe -m pip install -r document-processor\requirements.txt
# Optional local CPU OCR, with bundled RapidOCR ONNX models:
& .\document-processor\.venv\Scripts\python.exe -m pip install -r document-processor\requirements-ocr.txt
# Development fixtures and OCR tests, including legacy XLS:
& .\document-processor\.venv\Scripts\python.exe -m pip install -r document-processor\requirements-dev.txt
& .\document-processor\.venv\Scripts\python.exe document-processor\processor.py --doctor
```

Set DOCUMENT_PYTHON=../document-processor/.venv/Scripts/python.exe in backend/.env, then run artisan config:clear and restart the queue worker. The current checkout instead uses DOCUMENT_PYTHON=../.tools/python/python.exe. To reinstall its packages:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow
& .\.tools\python\python.exe -m pip install -r document-processor\requirements-dev.txt
```

requirements-windows.lock.txt records the tested core/OCR transitive environment. xlwt is only a test fixture writer in requirements-dev.txt; xlrd performs real XLS extraction.

Tesseract is an optional alternative. Install a trusted Windows Tesseract distribution and the required language data, set DOCUMENT_OCR_ENGINE=tesseract and DOCUMENT_TESSERACT to its executable path in .env (quote a path containing spaces). Check documents:doctor after config:clear. No Tesseract binary is installed in this checkout; RapidOCR is installed and verified. auto prefers Tesseract when available, then RapidOCR. none disables OCR. OCR is local and makes no third-party document requests.

## Configuration and limits

backend/config/documents.php reads:

| Setting | Default |
|---|---|
| DOCUMENT_PROCESSOR_SCRIPT | ../document-processor/processor.py |
| DOCUMENT_QUEUE | documents |
| DOCUMENT_MAX_FILE_MB | 25 |
| DOCUMENT_MAX_BATCH | 10 files |
| DOCUMENT_MAX_TOTAL_MB | 100 per API batch and UI selection |
| DOCUMENT_TIMEOUT | 900 seconds for Python |
| DOCUMENT_MAX_PAGES | 500 |
| DOCUMENT_MAX_CELLS | 250000 |
| DOCUMENT_MAX_EXPANDED_MB | 100 for Office archives/generated assets |
| DOCUMENT_MAX_OUTPUT_MB | 32 for raw JSON |
| DOCUMENT_OCR_ENGINE | auto |
| DOCUMENT_TESSERACT | tesseract |

SQLite now defaults to WAL journaling, DB_BUSY_TIMEOUT=5000 milliseconds and DB_TRANSACTION_MODE=IMMEDIATE (Laravel/PHP 8.4 supports this transaction mode). Claim/result/failure writes have bounded concurrency retries. Use one local document worker; MySQL is the intended production database.

The job timeout is DOCUMENT_TIMEOUT + 60 seconds. DB_QUEUE_RETRY_AFTER=1200 must exceed the job timeout; adjust both when raising limits. Use QUEUE_CONNECTION=database, not sync, for real background processing. Redis needs an equally long REDIS_QUEUE_RETRY_AFTER if you choose that driver.

The workspace PHP ini was updated locally: upload_max_filesize=25M, post_max_size=128M, memory_limit=512M. max_file_uploads defaults to 20. Match these limits in a fresh PHP/Apache/FastCGI installation and restart the server. Laravel returns validation errors for rejected files; requests beyond PHP's post_max_size are rejected before the controller.

Private files use the local disk under backend/storage/app/private. LOCAL_PRIVATE_ROOT can override it for isolated tests. Never expose this disk through storage:link or a public document root. Database backups alone are insufficient: back up private originals and run assets with the database.

## Browser walkthrough

1. Start the backend, frontend and worker above; log in with your account.
2. Open Projects → the existing Hann's Pharmacy project → Reports → August 2026. The report remains Draft, revision 1, August 1–31. Its existing ID is 01m51pnrwse3samzav60f4p6cf.
3. In Report documents, use Browse files or drop all five files from reference-files/. One Upload documents action uploads the selection with per-file network progress. The UI sends individual multipart requests sharing a batch UUID so each file has independent progress/failure; the API also accepts all five in one multipart request.
4. The upload state becomes Uploaded; extraction starts separately as Queued. Do not interpret upload completion as successful extraction. Status polls every four seconds while work is active.
5. Open each document. Preview serves the authenticated original PDF/image; Office/CSV files use their text, tables and image evidence plus original download. Text is searchable and labeled by page, worksheet, section or package part. Tables retain row order and page every 100 rows. Images includes screenshot context, package/XML/chart evidence, page previews and downloads. Processing Log shows real activities, attempts, warnings, errors and source counts.
6. Download original and raw JSON. Select an older attempt to inspect its preserved extraction results.
7. Upload an identical file again: it must be rejected for this report. A different month's report can contain the same source.
8. To verify stopped-worker behavior, stop only your document worker, upload a new file and observe Queued. Start the worker to resume. Queued attempts become visibly stale after five minutes; an explicit retry supersedes the old attempt. Processing attempts become stale only after their last heartbeat exceeds DOCUMENT_TIMEOUT + 120 seconds.
9. Retry failed/partial/reviewed attempts. Active attempts cannot be duplicated unless stale. The current attempt changes, and previous evidence remains visible in the attempt selector. Retry does not duplicate content within an attempt.
10. Remove a disposable upload, read the confirmation and confirm. It disappears and its API/download URLs return 404. This is logical removal with private audit retention, not secure erasure; removed originals and old results remain on disk/database.
11. Test a foreign project ID with an unassigned account: source APIs must return 404/403. Client Viewers cannot access source documents, OCR, logs, original downloads or raw JSON, even for shared Published reports.
12. Verify report status, dates, revision, sharing controls, sidebar and header are intact.

The supplied reference files were tested in a disposable database, not auto-imported into your live report. Your live report has no uploads until you select and upload them.

## Workflow and preservation

Laravel checks Sanctum session/CSRF, active account and workspace access. Uploads require a Draft report in an active project/client. A manager can manage the project's documents; an assigned Executive can upload and retry/remove their own documents. Internal users with project access may inspect source documents. Client Viewers see only the separate published reporting surface.

Uploads are validated by extension, content/magic, MIME where meaningful, image geometry, Office package structure/expanded size and unsafe entries/XML declarations. Generated private filenames never use the supplied path. SHA-256 detects exact duplicates within the same active report and verifies the file before extraction. Report and project IDs come from the authorized route, with a compound database FK preventing mismatched associations.

After the upload transaction commits, Laravel dispatches ProcessReportDocument onto the documents queue. Each job launches the separate Python process with a JSON manifest on stdin. It emits progress-only NDJSON on stdout and writes raw.json plus generated evidence into that attempt's private directory. No public Python HTTP server is needed.

Run the processor directly for diagnostics using a manifest you create locally:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow
& .\.tools\python\python.exe document-processor\processor.py --doctor
# For a JSON manifest with input, output, extension and options:
Get-Content -Raw -Encoding UTF8 .\manifest.json | & .\.tools\python\python.exe document-processor\processor.py
```

Use absolute input/output paths in the manifest and point output at a new private directory. The Laravel worker normally creates this manifest; users never supply processor paths through the upload API. Documents and embedded text are untrusted evidence, never instructions.

The repaired processor preserves original parser rows alongside native-coordinate cell text, keeps empty cells intact, caches identical-image OCR without removing occurrences, records nearby source headings for screenshots, and isolates table-parser failures with a pdfplumber fallback.

PDF processing visits every page and stores native page text, positioned blocks, inferred headings, tables, links, each embedded-image occurrence, rendered page previews and OCR fallback. Image context includes its page text, keeping search screenshots associated with source keywords. Unmatched content is retained. DOCX preserves ordered paragraphs/tables, headings, hyperlink relationships, media, source XML, supplementary text and embedded objects. XLSX preserves all worksheets, cell coordinates, original values, dates, formula text and available caches, table rows, media, chart XML/series references/cache values and package evidence. CSV retains original text, encoding, delimiter, all rows including duplicate-looking rows and physical line locations. XLS preserves all worksheets' cached cell/date values and explicitly warns about unsupported legacy formula/chart/image decoding.

Potential SEO metrics are unverified candidates with source labels and raw numeric strings. Nothing is merged, normalized into a dashboard or silently corrected. Human review is required for OCR, inferred columns, missing formula caches and unsupported evidence.

Run states: queued → processing → extracted / partially_extracted / needs_review / failed. Upload states are separate (pending/uploading/uploaded/upload_failed in the UI; successfully persisted documents are uploaded in the API). A missing interpreter, invalid PDF or timeout produces a failed attempt with a safe error. Missing OCR produces partial evidence with retained originals. Failure and retry never automatically approve, publish or revise a reporting period. Each run is claimed once; stale/superseded/removed runs are fenced from committing results.

## APIs

All routes are under authenticated active-user Sanctum middleware. Existing CORS permits DELETE and the configured frontend origin.

| Method | Route |
|---|---|
| GET/POST | /api/projects/{project}/reports/{report}/documents |
| GET | /api/projects/{project}/documents |
| GET | /api/document-processor/health |
| GET | /api/documents/{document} |
| GET | /api/documents/{document}/status |
| GET | /api/documents/{document}/extraction?run_id=...&type=...&search=...&page=1 |
| GET | /api/documents/{document}/assets?run_id=...&type=...&page=1 |
| GET | /api/documents/{document}/assets/{asset} |
| GET | /api/documents/{document}/preview |
| GET | /api/documents/{document}/download |
| GET | /api/documents/{document}/raw?run_id=... |
| POST | /api/documents/{document}/retry |
| DELETE | /api/documents/{document} |

Multipart upload fields: files[] (1–10 actual files), optional batch_id UUID. Clients cannot set owner, storage path, current run or report association. Downloads and evidence are fetched with the authenticated Axios client before creating temporary browser object URLs. Unsupported active formats such as SVG/HTML/XML are never rendered inline; source XML is a download.

## Database changes

Migration 2026_10_16_000004_create_document_ingestion ran as batch 5 on local SQLite. It adds:

- report_documents: report/project association, private source metadata, checksum, upload/extraction states, uploader, current attempt, batch ID and soft removal.
- document_extraction_runs: processor version, status, actual activity/units/times, warnings/errors, statistics and full raw JSON/path.
- document_extracted_content: ordered content, source page/sheet/section/location, original text, structured JSON and confidence.
- document_assets: per-run private evidence, source locations, MIME/size/checksum and metadata.
- Composite report ID/project index and FK, active per-report checksum uniqueness and per-run content/asset uniqueness.

No existing user/client/project/report columns were rewritten. Migration rollback intentionally refuses destructive evidence removal. Use corrective migrations or a verified restore process. MySQL 8-compatible schema uses portable types; this environment has no running MySQL 8, so that driver has not been integration-tested.

## Tests and logs

From the repository root:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow
Push-Location backend
& ..\.tools\php\php.exe artisan test
& ..\.tools\php\php.exe artisan migrate:status
& ..\.tools\php\php.exe artisan documents:doctor
Pop-Location
Push-Location frontend
npm.cmd test
npm.cmd run build
Pop-Location
& .\.tools\python\python.exe document-processor\run_tests.py
node docs/document-smoke.mjs
# Optional standalone read-only extraction of reference files:
& .\.tools\python\python.exe document-processor\validate_references.py --output .tools\reference-validation --ocr-engine auto
```

The HTTP test creates a random account, disposable SQLite database and private storage, starts its own loopback PHP server, submits the actual five reference documents in a single multipart batch, runs a real Laravel queue worker/Python/OCR pipeline, checks missing-Python failure/retry, duplicate/invalid upload, downloads, removal and unchanged report metadata, and cleans up its temporary environment. Do not run with Laravel configuration cached; use artisan config:clear first. PHP_BINARY can override the portable executable. Extraction tests need requirements-dev.txt; OCR tests explicitly skip if no real engine is installed.

Inspect processing activity in each document's Processing Log. Terminal diagnostics:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:failed
Get-Content -Encoding UTF8 .\storage\logs\laravel.log -Tail 80
```

Use the document Retry action rather than queue:retry to create an auditable new attempt. Keep log/private storage access restricted. Restart workers after config/code changes. A stopped queue is not a successful extraction.

## Files created and modified

Backend additions: config/documents.php; the Stage 3 migration; ReportDocument, DocumentExtractionRun, DocumentExtractedContent and DocumentAsset models; UploadDocumentsRequest; DocumentAccess, DocumentFileValidator and PythonDocumentProcessor services; DocumentController; ProcessReportDocument job; DocumentsDoctor command; tests/Feature/DocumentTest.php.

Backend updates: ReportingPeriod documents relation, ReportingPeriodController document permissions/counts, routes/api.php, CORS DELETE, database queue retry_after default, optional private-storage root, .env.example and local untracked .env. Portable PHP ini changed only inside ignored .tools/.

Frontend additions: lib/documents.ts, components/documents.tsx, pages/document-details.tsx, test/documents.test.tsx. Updates: App.tsx routes, report/project Files screens and overview/dashboard notices, types.ts, workspace confirmation support for DELETE, polling preservation in lib/use-api.ts, styles.css and prior tests' obsolete Stage 3 placeholders.

Python additions: engine.py, formats_pdf.py, formats_office.py, formats_data.py, processor.py, requirements*.txt, run_tests.py, tests/test_extraction.py, validate_references.py. Documentation: this guide, STAGE-3-VERIFICATION.md, document-smoke.mjs, processor README, root README, Windows and architecture updates. Runtime downloads, database backup, source hashes and test artifacts remain ignored under .tools/. reference-files/ and design-reference/ were not modified.

## Limits and deferred features

No normalized metrics, review approval editor, dashboard charts, generated client dashboard or Stage 4/5 transformations exist. The interface exposes source evidence for later work.

OCR is probabilistic; low-confidence/no-text regions are flagged. Decorative images may also trigger conservative partial status. Rendered PDF/image originals remain the review source. Tables inferred from PDF positioning may need column corrections. All repeated evidence and original numeric strings are retained.

DOCX has no accurate page pagination without an Office layout renderer. Native Office chart rendering and formula recalculation are absent; XML/caches/original evidence are retained. Legacy XLS cannot fully decode formulas, charts or embedded images through xlrd. No password-protected Office/PDF extraction is attempted. No antivirus integration, OS-level parser sandbox, secure-erasure/retention scheduler, resumable network upload or external HTTP processor deployment is included. Python is a separate local process under the worker account; apply process/container resource isolation before accepting hostile public uploads at production scale.

Actual browser visual interaction was not automated in this environment. React interaction tests and real HTTP tests passed; follow the browser walkthrough for PDF viewer/mobile checks. MySQL 8 validation remains an environment blocker. XAMPP's bundled PHP 8.0 cannot run Laravel 12; use the portable PHP 8.4 commands above or configure a compatible PHP handler. Serve only backend/public and frontend/dist, never the repository/private reference directories.
