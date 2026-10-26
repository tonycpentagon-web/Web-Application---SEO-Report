# Stage 8 hosting-neutral readiness

**Overall production decision: NO-GO.** Windows local staging checks passed within the documented scope; actual MySQL 8, Linux containers, selected-host TLS/mail/supervision and encrypted offsite restoration remain BLOCKED or NOT TESTED. No public deployment, provider selection, Git push or Stage 9 work occurred.

| Gate | Status | Evidence / limit |
| --- | --- | --- |
| Development preservation | PASS | 7,823 baseline rows retained, 821 files unchanged; one intervening sign-in advanced two account audit timestamps; Hann's Draft revision 1, 21 approved/1,184 pending, 20 blockers; no publication/share |
| Existing regression suites | PASS | Laravel 123 passed/1 skipped, 1,416 assertions; React 70; Python 28; TypeScript/build passed |
| Synthetic end-to-end and tenant privacy | PASS | Real localhost HTTP/CSRF/login/upload/extraction/normalization/verification/approval/Draft/publication/revocation/history; role/foreign-ID denials |
| Local document/PDF/load/recovery | PASS | Scope and limits in dedicated reports; high concurrency latency is not a production capacity pass |
| Compose architecture/static isolation | PASS | Prepared separate images/network/volumes, localhost bindings, health dependencies and no SQL host port; YAML/shell checked |
| Actual Docker/Linux/MySQL 8 execution | BLOCKED | No Docker executable/Desktop; WSL unusable; MariaDB client is not MySQL 8 |
| Public HTTPS/DNS/secure-cookie topology | BLOCKED | No host/domain chosen. Local fallback uses HTTP; local self-signed Compose TLS was not run |
| SMTP/Mailpit target delivery | BLOCKED | Local private log reset/invitation capture passed; no real relay or running Mailpit |
| Persistent Linux supervisor/scheduler | BLOCKED | Windows worker restarts tested and scheduled heartbeat executed; Linux daemon behavior not run |
| Mid-job crash / DB outage / MySQL concurrency | NOT TESTED | Idle worker crash and queued-job persistence tested; targeted unsafe host failures were not invented |
| Encrypted/offsite MySQL recovery | BLOCKED | Synthetic SQLite/files restore passed; no external backup infrastructure selected |
| Full Git history secrets | NOT TESTED | Checkout has no Git repository; source package scan only |

## Hosting models

| Requirement | Shared cPanel | cPanel/WHM with server administration | Linux VPS | Cloud VM/server |
| --- | --- | --- | --- | --- |
| Current recommendation | NO-GO | Conditional, currently NO-GO pending checks | Conditional, currently NO-GO pending checks | Conditional, currently NO-GO pending checks |
| PHP | 8.2 minimum per Composer; 8.4 recommended, all extensions verified on target | Same | Same | Same |
| SQL | Must be real MySQL 8; MariaDB substitution needs separate authorization/testing | MySQL 8 private schema/accounts | MySQL 8 private schema/accounts | MySQL 8 private endpoint/accounts; managed DB must be tested |
| Python/OCR | Commonly unavailable/limited; provider must prove venv/subprocess/Tesseract support | Full dependency install and timeout control required | Dedicated venv, pinned packages, Tesseract, local subprocesses | Same; service/container isolation must preserve local process contract |
| Node/Chromium | Often restricted; failure means unsuitable | Node 22-compatible runtime, matching Playwright browser/OS libraries/fonts | Same | Same; serverless short-timeout hosting not presumed compatible |
| Worker/scheduler | Short cron limits frequently incompatible with 900-second jobs | Persistent Supervisor/systemd or proven full-timeout cron | Persistent workers and one scheduler | Persistent workers/scheduler plus broker/DB monitoring; no ephemeral-job loss |
| Storage/backups | Private directories outside public_html and tested encrypted backups essential | Persistent private storage, least privilege, offsite recovery | Same | Private durable volume/object design must match application disk contract and be tested |
| Resources | Cannot infer sufficient resources from plan name | Measure PHP/Python/browser aggregate peaks on chosen host | Same | Same; include DB/broker/volume costs and measured sustained load |

Required PHP extensions: ctype, curl, DOM/XML, fileinfo, filter, hash, mbstring, OpenSSL, PCRE, PDO/pdo_mysql, session, tokenizer, ZIP; Linux CLI pcntl/posix for reliable worker timeouts. Development/test SQLite additionally needs pdo_sqlite. Exact pinned Python packages and optional OCR engines are in SERVER_REQUIREMENTS.md and document-processor requirements files. Node is needed at runtime for PDF, not just frontend build. Chromium needs OS libraries/fonts and service-account execution.

Measured local Node RSS alone is about 102?109 MB, excluding browser subprocesses; this cannot justify a minimal hosting plan. Windows machine RAM was approximately 16 GB with 3.7?4.0 GB available during HTTP load. A planning baseline of 2 CPU/4 GB for initial staging is only an estimate inherited from Stage 7, not a capacity guarantee. Test real aggregate peaks and realistic reports before committing money or concurrency settings.

Manual next actions: operator installs/approves Docker/WSL or supplies a Linux test host; builds/runs the prepared stack; verifies actual MySQL migrations/constraints/strictness/concurrency/recovery; checks Linux OCR/Chromium/process isolation; runs HTTPS/secure-cookie/CSRF/proxy and Mailpit tests; rehearses supervisor faults and encrypted offsite restoration; reviews release images/digests/secrets; chooses provider; obtains explicit deployment authorization. The application keeps its production fail-closed preflight and publication safeguards. A staging PASS does not override either gate.
