# Pentagon Insight Flow — Simplified SEO Reporting

Current report design: [seven-section Base44 adaptation, Advanced Properties keyword repair and verified public report](docs/BASE44_IMPLEMENTATION.md).

Current workflow: **Create Client → Create Project → Select Month → Upload Files → Generate SEO Report → Preview / Optional Edits → Share Report → Copy Link**.

The normal employee workflow uses automatic evidence checks instead of individual record, commentary or manager approvals. Sharing creates an immutable public dashboard and PDF together; unresolved uncertainty requires one acknowledgment and stays disclosed. Wrong-client data, conflicting values and period mismatches remain blocked. Completed reports are filed in Generated / Shared. Historical audit decisions remain available in advanced tools.

Read the [employee guide](docs/SIMPLIFIED_SEO_EMPLOYEE_GUIDE.md), [local startup and later hosting requirements](docs/SIMPLIFIED_LOCAL_START_AND_HOSTING.md), and [implementation and verification report](docs/SIMPLIFIED_WORKFLOW_VERIFICATION.md). Screenshots are in [docs/screenshots/simplified](docs/screenshots/simplified). Older stage documents below describe the retained legacy review tools.

Monthly management: [Active Reports, automatic publication-preserving filing, History, Trash and verification](docs/MONTHLY_REPORT_MANAGEMENT.md).

Current priority: local SEO Team Lead evaluation. See the [completed features and QA evidence](docs/LOCAL_EVALUATION_COMPLETION.md). Remote deployment preparation is paused. See [README-FIRST](docs/local/README-FIRST.md), the [local installation guide](docs/local/LOCAL-INSTALLATION.md) and [evaluation checklist](docs/local/TEST-ACCOUNTS-SETUP.md). No remote deployment, Docker/WSL or GitHub push is part of this work.

Current product: employee workspace, scoped administration, monthly report generation, manager review and secure client report history. See the [user manuals](docs/PRODUCT_USER_MANUALS.md), [deployment guide](docs/DEMO_SERVER_DEPLOYMENT.md), [restructuring audit](docs/PRODUCT_RESTRUCTURING_AUDIT.md) and [completion evidence](docs/PRODUCT_RESTRUCTURING_COMPLETION.md). Remote deployment requires the actual server compatibility review and deployment approval.

Laravel 12 + React/TypeScript multi-client SEO reporting workspace for Pentagon Information Technology LLC.

Stage 3 adds authenticated multi-file report uploads, private originals, queue-driven Python parsing and local OCR, complete source evidence, processing history, retries, previews and searchable extraction review. Existing clients, project teams, reporting periods, roles, revisions and explicit client sharing remain available. Stage 4 adds source-backed classification, structured normalization, provenance, validation warnings, audited human review and an internal database-backed preview. Stage 5 now includes an internal Draft Dashboard Preview with source-backed charts, provisional labels and publishing safeguards.

Start with the [Stage 4 setup, APIs and review walkthrough](docs/STAGE-4.md) and [live five-document normalization verification](docs/STAGE-4-VERIFICATION.md). Extraction documentation: [Stage 3 setup, API, workflow, browser walkthrough and file inventory](docs/STAGE-3.md) and [verification report with real reference-file results](docs/STAGE-3-VERIFICATION.md). Earlier documentation: [Windows/XAMPP setup](docs/INSTALL-WINDOWS.md), [secure account setup](docs/AUTHENTICATION.md), [Stage 2 roles](docs/STAGE-2.md), [architecture](docs/ARCHITECTURE.md).

Final review: [Why Approved KPIs can remain Provisional, exact verification requirements and reconciliation](docs/STAGE-5-FINAL-REVIEW.md).

UI refinement: [Stage 5 UI refinement — eight tabs, interactive tables, AI gallery and summary editing](docs/STAGE-5-UI-REFINEMENT.md).

Initial implementation: [Stage 5 internal draft dashboard, verification and browser instructions](docs/STAGE-5-DRAFT-PREVIEW.md).

Previous enhancement: [Secure bulk review, verification rules, API and browser steps](docs/STAGE-4-BULK-REVIEW.md). Existing 19 approvals are preserved; no remaining records were approved automatically.

Latest repair: [SQLite lock diagnosis and live-file extraction verification](docs/STAGE-3-EXTRACTION-REPAIR.md).

Latest implementation: [Stage 6 secure publishing, PDF export, sharing, history, deployment and verification](docs/STAGE-6.md). Hann's Pharmacy remains Draft; no records were approved or published by this stage.

PDF refinement: [Two export profiles, premium PDF layout and detailed preservation/verification report](docs/STAGE-6-PDF-REFINEMENT.md). The current private Hann's Draft Preview is 27 pages; the complete audit export is separate.

## Run this existing checkout

Use a separate PowerShell terminal for each process, with only one local SQLite queue worker. Do not reset the database or replace your .env.

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan documents:doctor
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:work --queue=documents,exports --tries=1 --timeout=960 --memory=512
```

Open **http://127.0.0.1:5173/login**, use your own account, then Projects → Hann's Pharmacy → Reports → August 2026 → Report documents. The five existing uploads have already been normalized. Select **Open Report Data Review** to inspect source evidence, warnings and structured records. All records begin Pending and require human review.

New installations create an account with the secure interactive command `php artisan admin:create`. There are no default login credentials or authentication seed accounts.

Python/OCR dependencies are installed in the ignored workspace runtime. On another machine follow the exact Python virtual-environment and pip commands in [STAGE-3.md](docs/STAGE-3.md). XAMPP's bundled PHP 8.0 is incompatible; use portable PHP 8.4 or a compatible handler.

## Verification

From backend/: `& ..\.tools\php\php.exe artisan test`.
From frontend/: `npm.cmd test` and `npm.cmd run build`.
From the repository root: `& .\.tools\python\python.exe document-processor\run_tests.py` and `node docs/document-smoke.mjs`.

Tests use in-memory/disposable SQLite databases and private temporary storage. Original reference files are read only. Existing live records and the August report's ID, dates, Draft status and revision 1 were checksum-verified unchanged.

Serve only backend/public and frontend/dist. Private files, source reports, environment files and .tools must never be publicly exposed. Removed documents are access-revoked with audit retention; removal does not securely erase originals. OCR and inferred PDF tables require human review. MySQL 8 and actual browser visual checks remain unverified in this environment; see the verification report.


Stage 7 preparation: [completion and verification](docs/STAGE_7_COMPLETION_REPORT.md), [architecture audit](docs/STAGE_7_ARCHITECTURE_AUDIT.md), [deployment guide](docs/PRODUCTION_DEPLOYMENT.md), [production checklist](docs/PRODUCTION_TESTING_CHECKLIST.md). Production remains NO-GO pending target-host verification; nothing has been deployed.


Stage 8 local staging: [startup guide](docs/STAGE_8_LOCAL_STAGING.md), [test results](docs/STAGE_8_TEST_RESULTS.md), [readiness assessment](docs/STAGE_8_DEPLOYMENT_READINESS.md). Docker/Linux/MySQL 8 remain blocked on this host; no production deployment occurred.

Client report experience: [secure client sharing, matching PDFs, reference comparison, local demo and verification](docs/CLIENT_REPORT_EXPERIENCE.md). Existing Hann's data remains a private Draft.

Temporary authenticated Windows review setup: [Temporary HTTPS review](docs/TEMPORARY_HTTPS_REVIEW.md).

Automated source validation and final report approval: [workflow, rules and audit](docs/AUTOMATED_SOURCE_VALIDATION.md).
