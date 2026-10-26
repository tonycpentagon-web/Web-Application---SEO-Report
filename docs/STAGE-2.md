# Stage 2 — multi-client workspace

## Implemented

- Clients: create/edit/detail/search/status filtering/archive/restore, contact fields, notes, brand color and private logo upload.
- Projects: existing IDs preserved; client relationship, frequency/start date, search/client/status filters, sorting, pagination, archival/restoration and team assignments.
- Project detail routes: Overview, Reports, Files, Team and Settings, with unauthorized controls hidden.
- Reports: monthly and custom date ranges, duplicate prevention, draft editing, status transitions, optimistic revision checks, explicit viewer sharing and preserved history.
- Team: authorized listing/search/role filters, account creation/editing, role assignment, activation/deactivation, password setup/reset links and assigned project lists.
- Security: shared server-side access service, nested project/report scoping, role/organization restrictions, scoped serialization, authenticated logos, unchanged Sanctum/CSRF login/logout and empty login defaults.

No sample client, team member, report or SEO metric was seeded into the application database. Existing records were migrated in place.

## Roles and permissions

| Role | Access |
| --- | --- |
| Super Admin | All clients/projects/reports/users; assign organizational Admins; manage roles and sharing |
| Admin | Own/explicitly assigned clients and their projects; manage lower-role users they provisioned; assign their permitted staff |
| SEO Manager | Assigned manager projects; project settings/team; create/edit reports, review/approve/publish/share |
| SEO Executive | Assigned/owned projects; create/edit Draft reports and submit for review; cannot manage client/project settings or teams |
| Client Viewer | Only explicitly shared Published report periods; no internal contacts, notes, teams, history or editing |

A user holding a project ownership relationship can still read it; this does not elevate an Executive to project administrator. Existing Super Admin flags remain authoritative. The role fields are not mass-assignable through generic model fields or the login API.

For organizational Admin access, a Super Admin creates an Admin in Team Management, opens a Client, and selects that Admin under **Assigned organizational administrators**. Admin user management is scoped through managed_by; there is no global employee directory for ordinary users.

## Database migration and preservation

The new migration is:

`2026_10_16_000003_create_stage_two_workspace`

It adds users.role/managed_by, clients, project client/frequency/start-date fields, client memberships, project memberships, reporting periods, explicit report shares and activity logs. Relationships have indexes and foreign keys; duplicate membership and project/date-range records are constrained in the database.

Applied local migration status:

| Migration | Status |
| --- | --- |
| 0001_01_01_000000_create_users_table | Ran |
| 0001_01_01_000001_create_cache_table | Ran |
| 0001_01_01_000002_create_jobs_table | Ran |
| 2026_10_16_000001_create_projects_table | Ran |
| 2026_10_16_000002_add_account_security_fields_to_users | Ran |
| 2026_10_16_000003_create_stage_two_workspace | Ran — batch 4 |
| 2026_10_16_050931_create_personal_access_tokens_table | Ran |

The audit confirmed:

- One existing active Super Admin remains; every original user column, including the password hash, is unchanged.
- The existing Hann's Pharmacy project ID is **01m51mtexy2cda3etrd7ge03t0**.
- Every original project column and its owner are unchanged.
- Exactly one canonical **Hann's Pharmacy** client now exists.
- No reporting periods were created in the live database during implementation.
- An ignored local SQLite backup is retained under .tools/stage2-before-*.sqlite. Do not commit or publish database backups.

The original stored project name is “Hann's Pharmacy” and the legacy client_name is “Hann's Pharmacy SEO”. Those original values were retained; the client relation now provides the canonical display name.

Do not run migrate:fresh, db:wipe, rollback or reseed sample business data against the live application. Stage 2's down migration intentionally refuses to drop business records.

## Startup

Keep the existing .env and application key. Dependencies are already installed in this checkout.

Backend PowerShell terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Frontend PowerShell terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev
```

Use your existing Super Admin login. If a development server already owns the port, reuse that server instead of launching a second copy. Fresh installation dependencies and MySQL configuration remain in INSTALL-WINDOWS.md.

## Exact browser URLs

- Login: http://127.0.0.1:5173/login
- Overview: http://127.0.0.1:5173/
- Clients: http://127.0.0.1:5173/clients
- New client: http://127.0.0.1:5173/clients/new
- Projects: http://127.0.0.1:5173/projects
- Existing Hann's project: http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0
- Its reports: http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/reports
- Its team: http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/team
- Its settings: http://127.0.0.1:5173/projects/01m51mtexy2cda3etrd7ge03t0/settings
- Team Management: http://127.0.0.1:5173/team
- New employee: http://127.0.0.1:5173/team/new
- Account Settings: http://127.0.0.1:5173/settings

## Create the August 2026 report

1. Sign in with the existing Super Admin.
2. Open the Hann's project Reports URL above.
3. Click **New reporting period**.
4. Enter **August 2026 SEO Report** as the title.
5. Set Reporting month to **August 2026** (2026-08).
6. Keep Period type as **Full calendar month**.
7. Click **Create reporting period**.

The API assigns 1–31 August 2026 and Draft status. A second report for the same exact project/date range is rejected. For a legitimate partial range, choose Custom date range and enter different dates. No SEO metrics appear without uploaded/extracted data.

## Assign an SEO team member

1. Open Team Management → New team member.
2. Enter the employee's name/email and choose SEO Manager or SEO Executive.
3. Save. A private password setup link uses the configured mail transport.
4. Open the Hann's project → Team.
5. Choose the Manager from **Assigned SEO Manager**, or select Executive checkboxes.
6. Click **Save project team**.

Only active, permitted users with the correct role appear. Removing a membership removes project access, except for an independent owner/Admin/Super Admin access grant.

Client Viewers are created through Team Management with the Client Viewer role. Open one report's details, select the viewer under **Explicit client report sharing**, save, and publish the report through the approved status sequence. Sharing does not grant access to any other reporting period.

Local MAIL_MAILER=log writes development setup/reset messages to ignored backend/storage/logs/laravel.log; it does not send external email. Configure a real mail provider for employee invitations. Passwords and reset tokens are never returned to the UI.

## Reporting status workflow

| Current status | Permitted next states for managers/Admins |
| --- | --- |
| Draft | Needs Review, Archived |
| Needs Review | Draft, Approved, Archived |
| Approved | Needs Review, Published, Archived |
| Published | Archived |
| Archived | Draft |
| Processing | Needs Review, Failed — reserved for future processing |
| Failed | Draft, Archived — reserved for future processing |

Executives may submit Draft → Needs Review only. Dates/title can be edited only in Draft. Archived clients/projects block report mutations until restored. Report versions protect against stale edits. Every create/edit/status/sharing change is retained in report-linked activity.

## REST API

All routes below use the /api prefix, Sanctum, active-account enforcement and API throttling. Existing /login, /logout, /password, /forgot-password and /reset-password remain unchanged in purpose. GET /sanctum/csrf-cookie starts the browser CSRF handshake.

| Methods | Path | Purpose |
| --- | --- | --- |
| GET, POST | /clients | Scoped paginated list / create |
| GET, PUT | /clients/{client} | Detail / edit or archive/restore via status |
| GET, POST | /clients/{client}/logo | Private image response / validated multipart logo upload |
| PUT | /clients/{client}/admins | Super Admin assigns user_ids |
| GET, POST | /projects | List / create; client_id canonical, legacy client_name accepted for administrator compatibility |
| GET, PUT | /projects/{project} | Details / edit or archive/restore |
| PUT | /projects/{project}/team | manager_id and member_ids synchronization |
| GET | /projects/{project}/activity | Paginated internal activity |
| GET | /projects/{project}/dashboard | Retained empty dashboard contract |
| GET, POST | /projects/{project}/reports | List / create independent period |
| GET, PUT | /projects/{project}/reports/{report} | Detail/history / edit Draft with version |
| PATCH | /projects/{project}/reports/{report}/status | Validated status and version |
| PUT | /projects/{project}/reports/{report}/shares | Explicit Client Viewer user_ids |
| GET, POST | /team | Scoped employee list / provision and send setup link |
| GET | /team/options | Paginated permitted active assignment choices |
| GET, PUT | /team/{user} | Employee detail / authorized profile, role and active-state changes |
| POST | /team/{user}/password-reset | Send private password setup/reset link |
| GET | /dashboard, /user | Scoped overview / authenticated identity |

Lists use 12 records/page (activity 20, assignment options 100). Filters: clients search/status/page; projects search/client_id/status/sort/direction/page; reports status/page; team search/role/page. Valid sort fields are name, created_at and updated_at.

Errors: 401 unauthenticated/inactive, 403 readable resource but disallowed action, 404 inaccessible ID, 409 workflow/version/constraint conflicts, 422 input validation and 429 throttling. JSON objects use data/message; lists use Laravel paginator metadata.

## Automated verification

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan test
& ..\.tools\php\php.exe artisan migrate:status
Set-Location ..\frontend
npm.cmd test
npm.cmd run build
Set-Location ..
node docs/smoke.mjs
```

Tests run against isolated SQLite databases. The HTTP check starts/stops its own temporary server/database and does not modify the live Hann's project. Final test totals and validation limits are in VERIFICATION.md.

## Manual browser test checklist

These are instructions for the owner; browser visual testing was not performed during implementation.

- Log in/out and confirm empty credential fields without browser autofill.
- Open the existing client/project; confirm the ID, website and description remain intact.
- Create another client/project; search, filter, sort and paginate.
- Upload a valid PNG logo; reject SVG/nonimage or oversized files.
- Archive/restore clients/projects and confirm records remain present.
- Create August 2026 and a custom reporting range; reject an exact duplicate.
- Submit/review/approve/publish/archive a report; inspect preserved history.
- Create and assign Manager/Executive accounts; verify role-specific controls and API denial for foreign project IDs.
- Share one Published report with a Client Viewer; confirm other periods and internal details remain inaccessible.
- Check narrow/mobile viewports, sidebar, keyboard focus, confirmation dialogs and table scrolling.

## Known limits and deferred work

- MySQL 8 migration/runtime integration remains unverified; the available XAMPP MariaDB service is not a MySQL 8 substitute. SQLite migrations and preservation checks passed.
- No actual browser automation or visual/device QA was performed. Component tests do not establish pixel parity or browser password-manager behavior.
- Mail uses a local log transport until a real provider is configured. Setup/reset API responses contain no tokens.
- Processing/Failed states are reserved; no artificial processing jobs are dispatched.
- Report file uploads, OCR/extraction, metrics widgets and automated publication are not implemented. The Files placeholder is deliberate. No Stage 3/4 work began.
- This is one Pentagon workspace with explicit client Admin assignments and a user provisioning hierarchy, not a full multi-organization billing/account system.
- No public hosting/deployment was performed.

## Created and modified files

Created:

- backend/database/migrations/2026_10_16_000003_create_stage_two_workspace.php
- backend/app/Models/Client.php, ReportingPeriod.php, ProjectActivityLog.php
- backend/app/Services/LegacyClientBackfill.php, WorkspaceAccess.php, WorkspaceView.php, ReportWorkflow.php
- backend/app/Http/Controllers/ClientController.php, ReportingPeriodController.php, TeamController.php
- backend/app/Http/Requests/SaveClientRequest.php, SaveReportingPeriodRequest.php
- backend/tests/Feature/WorkspaceTest.php
- frontend/src/components/workspace.tsx, frontend/src/lib/workspace.ts
- frontend/src/pages/clients.tsx, project-details.tsx, reports.tsx, team.tsx
- docs/STAGE-2.md

Modified:

- backend/app/Models/Project.php, User.php
- backend/app/Console/Commands/CreateAdmin.php
- backend/app/Http/Controllers/ProjectController.php
- backend/app/Http/Requests/SaveProjectRequest.php
- backend/app/Providers/AppServiceProvider.php, backend/bootstrap/app.php, backend/routes/api.php
- backend/tests/Feature/AdminAccountTest.php, ProjectTest.php, backend/tests/provision-smoke.php
- frontend/src/App.tsx, types.ts, lib/api.ts, components/layout.tsx
- frontend/src/pages/overview.tsx, projects.tsx, project-form.tsx, project-dashboard.tsx
- frontend/src/styles.css, test/app.test.tsx, test/setup.ts
- README.md, backend/README.md, document-processor/README.md
- docs/ARCHITECTURE.md, AUTHENTICATION.md, INSTALL-WINDOWS.md, VERIFICATION.md, smoke.mjs

Ignored operational artifacts: .tools/ contains the pre-migration database backup, baseline/preservation snapshots and audit scripts. No original reference-files/ or design-reference/ content was modified.
