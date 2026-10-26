# Final dashboard verification - 19 October 2026

The existing Laravel/React application is running locally. No Docker, WSL, new server software or deployment was used. The existing configured SQLite database remains in place; this task did not migrate it to MySQL.

Open in Chrome, using an existing authorized login:

http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/preview

Admin dashboard:

http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports/01m51pnrwse3samzav60f4p6cf/dashboard

The report details and admin dashboard both link to Client-style Draft Preview. Admin & review returns to the existing review dashboard; its existing Export & delivery link opens export management, approval, publication readiness and sharing controls.

## Windows startup

The services were left running on the original ports. If stopped, use three PowerShell terminals. Do not start a second process on an occupied port.

Backend:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev -- --host=127.0.0.1 --port=5173
```

Documents and PDF worker:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:work database --queue=documents,exports --sleep=2 --tries=1 --timeout=960 --memory=512
```

Use 127.0.0.1 consistently for session authentication. Keep the worker terminal running for queued exports. After editing PDF templates, gracefully restart the long-running worker (`artisan queue:restart`) and launch it again so it loads the new compiled templates.

## Verified data

Read from the real authenticated dashboard API and compared with the rendered report/PDF. None of these values is hardcoded in the preview.

| Checkpoint | Actual value |
|---|---:|
| GA4 users | 4,515 |
| GA4 new users | 4,383 |
| GA4 views | 6,257 |
| GSC clicks | 2,950 |
| GSC impressions | 432,736 |
| Reported CTR | 0.68% |
| Reported key events | 97 |
| Keyword records | 70 |
| SEO activities | 95 |
| Original AI screenshots | 9 |

Report remains Draft revision 1. Of 218 business observations, 213 remain provisional. Twenty unresolved blocking warnings and missing commentary still prevent publication. No record verification, approval, commentary, warning resolution, client assignment or report status was altered.

## Checks and results

- Laravel full suite: **123 passed, 1 skipped, 1,416 assertions**. The skipped check requires an explicitly configured MySQL 8 test database; this local task keeps the existing SQLite setup.
- After the PDF changes, delivery and authentication suites rerun: **28 passed, 399 assertions**. Includes export permissions, publication/share blocking, real-renderer failure/retry and preservation of frozen audit data.
- React: **76 passed in 8 files**, including six new private-preview cases. Checks missing/pending/stale/revision-mismatched commentary, current approved commentary, actual metric rendering, admin-control separation and Draft-only export request.
- TypeScript and production Vite build: passed. The same-origin build uses VITE_API_URL=/; local Vite development keeps its existing port-8000 API configuration.
- Real Edge/Playwright browser against existing 8000/5173 services: clients list, Hann's project, eight admin tabs, nine preview sections, chart tooltip, keyword search/sort, 50+20 keyword pagination, all nine original screenshots and open/close image dialog passed.
- Checked 1440, 768 and 390 pixel viewports: document/body widths match the viewport; no whole-page overflow. A visual inspection found and fixed inherited mobile table rules; tables scroll inside their own container.
- Login form and guest redirect checked in browser. Authentication/password/session behavior passed Laravel tests. Authenticated browser checks used a temporary local session for the existing Super Admin; no existing password was read, reset or replaced, and no existing account was created or edited. A human password-entry test with the user's undisclosed password was not performed.
- Same-origin private published-client pages now keep an origin referrer for Sanctum; token-shared pages continue using no-referrer.
- React's existing polling tests emit act-environment notices but pass. No browser JavaScript errors were recorded.

## PDF export

Open the preview, choose **Export Draft PDF**, wait for the private-ready success message, then **Download ready PDF**. The existing delivery API and database queue handle capture, rendering and protected download. Errors remain visible and retries are available in admin Export & delivery. Exporting never approves or publishes the report.

Final improved export:

- ID: 01m59rdq97sg2vtpsznz4rvmkv
- Profile/type: client_preview / draft
- Pages: 27
- Bytes: 759647
- SHA-256: a8415279e0a8004d586c31478e71cfc716dadef1a029842f5d4f0f46d50764a0
- Private file: backend/storage/app/private/exports/01m59rdq97sg2vtpsznz4rvmkv/report.pdf

Real browser workflow received HTTP 202, observed completion, and downloaded Pentagon-report.pdf successfully. All nine sections, 70 keywords, 95 activity URLs and nine images were verified in the PDF. Draft watermark occurs on every page. Source date 2026-08-30, missing-commentary explanation and unverified-visibility wording remain. Repeated headers and all page text bounding boxes were checked: no detected clipping/overflow or unnecessarily sparse pages. Representative cover, summary, keywords, activities, evidence and events pages were rendered to images and inspected.

The first new export was generated by the old long-running worker with its cached template. It was preserved; after gracefully restarting the worker, the final export above was generated through the new preview. Existing and intermediate exports were not overwritten.

## Preservation and limitations

A baseline and SQLite backup were captured before application changes. Final field-by-field and SHA-256 comparison: **7,823 original business/history rows and 821 original files preserved, zero differences**. Existing users, clients, project/report, original documents, extraction data, decisions, audit history and old exports remain intact. New exports and their delivery events are additive. Operational queue/cache/session/heartbeat entries are excluded from business-row comparison.

Draft access continues to use existing authentication and report authorization. No new public endpoint, approval bypass or sharing route was introduced. There are no publication/share records for Hann's Draft. Existing failed jobs were not cleared or bulk-retried. No PDF data was fabricated, and original source duplicates were retained.

Current source review and missing commentary are genuine remaining publication blockers, not UI failures. They must be resolved through normal authorized human review. See FINAL_DASHBOARD_DESIGN_CHECKLIST.md for remaining intentional visual differences.

Private verification artifacts and screenshots are under .tools/final-dashboard/, including browser-results.json, interaction-results.json, inspection.json, export-workflow.json and preview screenshots. Temporary test sessions are revoked after verification. These artifacts are local and are not application public routes.
