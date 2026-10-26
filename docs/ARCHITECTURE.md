# Architecture — Stage 4

## Runtime and boundaries

Laravel 12 / PHP 8.2+ expose authenticated JSON APIs. React 19, TypeScript, Vite, Tailwind and reusable shadcn/ui primitives render the workspace. Sanctum stateful cookie authentication, CSRF protection, rate limits and active-account middleware remain in place. No browser tokens or seeded credentials are used.

The local environment uses SQLite and portable PHP 8.4. Migrations use portable Laravel schema types and relationships targeting MySQL 8; MySQL 8 execution has not been verified here.

Stage 3 adds private report-document uploads and a separate queue-driven Python extraction process. Source evidence, raw JSON and private assets are stored per document and extraction attempt. See STAGE-3.md for the schema, API and access policy. Client branding images remain a separate private upload flow.

## Database

| Table | Purpose and constraints |
| --- | --- |
| users | Existing identity/password/session relationships; role, is_super_admin, is_active, managed_by and last_login_at |
| clients | ULID; creator FK; normalized-name identity hash unique per creator; contacts, country, branding, status, notes and timestamps |
| projects | Existing IDs and owner FK retained; client FK, reporting_frequency and start_date added |
| client_memberships | Unique client/user pair identifying organizational Admin assignments |
| project_memberships | Unique project/user pair; manager or member assignment |
| reporting_periods | ULID; project FK; title/month/kind/dates/status; creator/updater FKs; optimistic version; unique project/start/end range |
| report_shares | Unique reporting-period/user pair; explicit viewer and sharing actor |
| project_activity_logs | Append-only application events; project/report/actor relationships; before/after properties and creation time |

Client/project/report archival preserves records. Business entities have restrictive foreign keys for new relationships. Existing project IDs and ownership relationships were not rebuilt. There are no public hard-delete business endpoints.

Stage 2 migration is additive and forward-only: its down method refuses destructive rollback. Apply corrective migrations or restore a verified backup through an explicit operational process rather than dropping client/report data.

## Legacy data migration

Legacy projects with no client_id are grouped by creator and normalized client name. A trailing SEO label is removed when establishing a canonical client name. An existing matching client is reused. Original project names, client_name values, IDs, owners, descriptions and timestamps are not rewritten. Backfill is idempotent.

Existing is_super_admin flags map to super_admin roles. Other accounts default to seo_executive; no account is removed, deactivated or silently granted administrative access. The existing local Super Admin remained active. A local SQLite snapshot and original-record audit were taken before migration.

## Authorization

WorkspaceAccess is the shared server-side authorization/scoping service, equivalent to resource policies. Controllers first scope queries to the authenticated user, then check action permissions. Unauthorized IDs return 404; disallowed actions on readable records return 403. React receives permission flags and hides actions, but those flags are not trusted as authorization inputs.

Super Admins have explicit system access in Stage 2, superseding Stage 1's creator-only scope. Admins manage clients they created or to which a Super Admin explicitly assigned them. Admins manage lower-role users they provisioned; promoted Admin/Super Admin accounts are excluded from that scope.

SEO Managers manage projects they own or where they hold the manager assignment. SEO Executives can read assigned/owned projects and work on draft reports; ownership does not grant project-settings administration. Client Viewers gain no access from project membership or URL knowledge: they require an explicit report share and Published status.

Viewer serialization removes internal client contact/notes fields, project descriptions, team lists, activity, report actor IDs and history. Counts and latest reports are derived from visible reports/projects, avoiding leaks from unshared periods.

Member choices are restricted to active users in the actor's permitted user hierarchy. Team memberships require the appropriate application role. Project/report IDs are nested and checked together to prevent cross-project IDOR. Client administrator assignment is Super Admin only.

## Report workflow

New reports are Draft. Monthly periods derive their exact calendar boundaries; custom ranges retain explicit dates. An identical project/date range is forbidden even when archived. Overlapping custom ranges are allowed.

Draft-only metadata editing preserves previous snapshots in activity history. Updates and transitions require the current version and lock the record; stale writes return 409. The database unique constraint also protects against concurrent duplicate ranges.

Managers/Admins may submit, approve, publish, archive and return to draft according to the transition map. Executives may edit Drafts and submit to Needs Review. Report Processing/Failed remain reserved metadata states. Stage 3 document extraction uses separate per-document/per-attempt states; it never changes report status or revision automatically.

Published reports remain empty of metrics until future file/extraction stages. Shares are explicit per period; a shared Draft or archived report grants no viewer access.

## Security and operations

Sessions regenerate at login and invalidate at logout. Passwords use Laravel hashing. Employee provisioning generates an unrevealed random secret and sends a password setup link; no endpoint returns a password or reset token. Role/email/active-state changes revoke affected database sessions and reset tokens. Self-demotion/deactivation through the Team API is blocked, and at least one active Super Admin must remain.

Logo uploads accept JPEG/PNG/WebP only, up to 2 MB and 2048 × 2048 pixels. SVG is rejected. Random storage names, private disk paths, authorized retrieval, nosniff and private/no-store response headers protect branding assets. No storage:link command is required.

All API routes are authenticated/rate-limited. Logo uploads have an additional per-account limit. Input schemas whitelist editable fields, and multi-record changes use transactions. Unexpected errors use Laravel logging; custom identity events never log submitted passwords. Unique-constraint races return generic 409 JSON responses.

Local mail logging is for development only. Use a production mail provider, HTTPS, secure cookies, APP_DEBUG=false, backups and monitored logs before deployment.

## Stage 4 normalization boundary

A queued Laravel job snapshots current authorized extraction attempts and invokes the separate deterministic Python normalizer over stdin. Source documents, raw extraction and assets remain immutable. Stable report-scoped record keys, evidence references, run fingerprints and atomic upserts prevent duplication and cross-project contamination. A typed normalized record registry backs eight read views; corrections are sparse overrides with reviewer/reason/before/after audit history. Source changes reopen approvals while retaining overrides. Internal APIs enforce project membership, Draft/active state and management permissions. Preview consumers must use effective values, current fingerprints and explicit approved-only filtering; conflicts are exposed rather than summed. See [Stage 4 schema, routes, authorization and Stage 5 consumption contract](STAGE-4.md). No Stage 5 dashboard charts have been implemented.
