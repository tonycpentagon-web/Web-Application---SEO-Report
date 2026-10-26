Current monthly workflow: see [Monthly Report Management](MONTHLY_REPORT_MANAGEMENT.md). Published workspace filing keeps public links active; it is distinct from the older Archived workflow status used during revisions.

# Pentagon Insight Flow - role manuals

## SEO Executive

1. Sign in with your individual employee email and password. Overview shows only projects/reports your role permits. Ask an administrator to assign missing projects.
2. Choose Create Monthly Report, select the client/project and month/year. Each project/month is independent; a duplicate period opens through history rather than replacing data.
3. Select the monthly PDF/image/DOCX/XLSX/CSV sources together. Review per-file progress; choose Generate SEO Report. Originals and extraction evidence remain private and retained.
4. Wait for extraction/classification/generation. Retry a failed upload/source or stale attempt using the displayed control. Do not describe incomplete OCR as verified. Unknown source content remains available in Advanced Review.
5. Open Preview report dashboard. A Draft may contain provisional data and generated source-backed commentary without hundreds of manual approvals. It remains internal.
6. Inspect metrics, tables, screenshots and dates. Open Advanced Review to see Extracted Report Data, source completeness, warnings and evidence. Report missing or conflicting data; never invent a value to remove a warning.
7. After generation, choose Submit for Review. The report moves to Ready for Review. Publishing and client access remain manager-controlled.
8. If another employee changed the report, reload after the conflict message, inspect the latest version and reapply your intended change. Do not keep submitting an old revision.

## SEO Manager

1. Open Pending Manager Review / a report in your assigned project, then Review, Publish and Share.
2. Use the report review checklist to see processed sources, required data, source validation/conflicts, material warnings and commentary. Advanced Review retains detailed records, source verification, warning decisions and audit history. Bulk review is explicit and subject to existing eligibility checks; uncertain records are never automatically approved.
3. Return the report to Draft when source/commentary corrections are needed. Inspect the original evidence, make authorized corrections and review current source-backed commentary. Approve commentary while Draft, then submit it for review again.
4. Preview the complete dashboard. Generate/download a complete client-style Draft PDF and inspect its content, charts, screenshots and page breaks. Record these visual checks in the checklist. Saved visual checks are bound to the current source/commentary fingerprint; changed data invalidates them. They supplement existing source approval safeguards and never approve data or change report status by themselves.
5. When existing publication checks pass, approve the report, then explicitly Publish Report. This freezes an immutable version and queues its published PDF. Sharing is a separate action; a Draft cannot be shared.
6. Choose Share Report with Client. Select the permitted active Client Viewer, PDF permission and optional custom expiry (blank = 30 days), create the link and copy it immediately. Account permission and link are saved together without replacing other recipients.
7. If a client account is missing, use the administrator's invite flow. Managers can grant access only to viewers in their permitted organization; they cannot bypass employee/account administration.
8. Manage account permissions, link revocation, complete PDF downloads, publication versions and access history from this page. Revoking one URL differs from removing account access across all report URLs.
9. Previous published snapshots remain byte-preserved. The existing correction workflow returns an archived period to Draft and requires renewed approval/new publication; during that workflow the old period can be unavailable to clients under the existing fail-closed policy. Independent earlier months are not replaced. Parallel working-copy editing while keeping the same period publicly available is not introduced here.

## SEO Admin / Super Admin

The stored admin role is displayed as SEO Admin; role keys and existing accounts are unchanged. SEO Admin manages owned/assigned client organizations; Super Admin has system-wide scope. Managers manage assigned projects; executives work assigned projects; Client Viewers see explicitly authorized published reports only.

Use Clients to create a client, contacts, website and logo. An archived client is inactive and retained, not deleted. One client can own multiple SEO Projects. Set project website, reporting frequency/start date and active status. Assign an active SEO Manager and SEO Executives from your permitted organization.

Use Team Management / Administration to create individual employee or Client Viewer accounts with the appropriate role. Send the existing secure password-setup invitation; never send a plaintext initial password. Activate/deactivate users through the authorized controls. Disabling a user revokes access through the existing server checks. Project assignment is not an automatic grant to a Client Viewer: share specific published reports explicitly.

Administration contains permitted employee/client counts, project assignment links, reports awaiting review, failed source files and recent project/report audit history. Super Admin alone can open System Health. No raw environment editor or credentials are exposed in the UI. Configure actual mail/runtime settings privately on the server and verify them through the deployment manual. Local log mail does not send invitations externally.

## Client Viewer

Open your report URL. If prompted, use the invitation to set your own password, then sign in with your email/password. Existing credentials work across later months; you do not need another account for each report. After sign-in the requested report opens automatically.

Use the report section navigation, interactive charts, searchable/sortable tables and full-size screenshot previews. My Reports and Monthly Report History list only authorized published months; use the month switch to change reports. Download PDF appears only when permitted; the server independently enforces access on each download.

Use Account Settings to manage your password and Logout when finished. An expired/revoked link or report permission can make the report unavailable. Ask the SEO administrator to renew the correct access; changing a URL cannot grant access to another client. You will not see employee management, Drafts, processing or approval controls.

## Server Administrator

Start with DEMO_SERVER_DEPLOYMENT.md and the read-only host audit. Do not deploy until the actual server's compatibility and approval are recorded. Keep private environments, source documents, exports, database and backups outside web roots. Use non-root workers, durable queues, HTTPS, SMTP, scheduled tasks and encrypted off-host backups. Never copy local .env or the .tools runtime to the server.

Prepare releases with the guarded script, verify MySQL/application/platform checks, run a real backup restore drill and confirm worker/scheduler heartbeats, email delivery and role-isolation smoke tests. Restart long-lived workers after code/template changes. Inspect specific processing failures and retry them without deleting originals or audit history. Update using new releases with tested rollback plans; never reset/truncate existing reporting data.
