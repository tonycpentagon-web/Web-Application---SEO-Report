# Server requirements

Production is currently **NO-GO** until the production checklist is completed on the intended host. Local Windows success does not establish Linux or hosting compatibility.

| Component | Requirement / observed local version |
| --- | --- |
| PHP | 8.4 recommended (local 8.4.26); Composer lock must resolve on target. Laravel extensions: ctype, curl, DOM/XML, fileinfo, filter, hash, mbstring, OpenSSL, PCRE, PDO, session, tokenizer; pdo_mysql and ZIP. CLI pcntl/posix for reliable worker timeouts. |
| SQL | Actual MySQL 8, strict mode, utf8mb4, InnoDB; dedicated least-privilege account. Local SQLite verified; installed MariaDB 10.4 is not the MySQL gate. |
| Node | Local 22.14; use supported maintained Node matching package engines. Install renderer lock and matching Playwright Chromium plus OS libraries. |
| Python | Python 3.11+ compatible with pinned document-processor requirements; local embedded runtime verified. Tesseract executable/languages required if OCR enabled. Run doctor and real extraction tests on host. |
| Processes | PHP-FPM, web server, durable queue, separate document/PDF workers, scheduler, Python subprocesses, Chromium subprocesses. proc_open must be available. |
| Storage | Private writable storage outside every public web root; persistent across releases. Include originals, assets, manifests, snapshots and PDFs. Never map /storage to private files. |
| Resources | Starting planning allowance: 2 CPU, 4 GB RAM plus swap, SSD sized for original/evidence/PDF growth and backups. This is an estimate, not a measured capacity guarantee. Limit concurrency until host load tests pass. |
| Network | Valid HTTPS, outbound SMTP and encrypted backup destination; restrict extraction/rendering service egress. PDF renderer itself blocks remote requests. |

Document jobs can take 900 seconds; supervisor stop wait must exceed maximum job time. Worker memory is 512 MB in supplied examples; budget Python and Chromium memory separately. PHP-FPM and CLI configuration are distinct. Install fonts and verify screenshots/Unicode on the actual Linux renderer. Never run production workers as root.

Install document-processor/requirements.txt: PyMuPDF 1.28.2, pdfplumber 0.11.10, python-docx 1.2.0, openpyxl 3.1.5, Pillow 12.3.0, xlrd 2.0.2, charset-normalizer 3.5.2, defusedxml 0.7.1. Optional OCR file adds pytesseract 0.3.13 and rapidocr-onnxruntime 1.4.4 plus dependencies. A Tesseract-only installation may install pytesseract and the OS Tesseract engine, select DOCUMENT_OCR_ENGINE=tesseract and omit RapidOCR; verify doctor/extraction on that exact environment. Do not install optional OCR engines unless needed. Lock and audit all transitive packages on the target.
