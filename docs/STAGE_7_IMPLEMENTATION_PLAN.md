# Stage 7 implementation plan

Prepared before application-code changes on 19 October 2026, after inspecting the backend routes/controllers/services/models/jobs/configuration/migrations, frontend routing/API/components/tests, Python extraction protocol, PDF renderer/templates and prior verification reports.

## Scope and safeguards

Continue Stages 1–6 in place. Do not deploy, push, alter existing users/credentials, reset/truncate tables, delete records/files/history, approve records/commentary or publish Hann's Pharmacy. Take a fresh read-only business-row/file checksum baseline and SQLite backup before additive migrations. Test with disposable databases and synthetic clients; compare all baseline records/files after the work. Local runtime currently uses SQLite, irrespective of the intended MySQL 8 production target.

## Implementation sequence

1. Record `STAGE_7_ARCHITECTURE_AUDIT.md` with actual subsystem status, deficiencies, severity and verification limits. Inspect dependency advisories and available database/runtime tools. Document MySQL 8 as unverified if a real server is unavailable.
2. Add production configuration validation and an explicit preflight command. Check HTTPS URLs, secure/encrypted sessions, stateful Sanctum hosts, exact CORS origins, nonpublic local storage, durable queues, MySQL strict mode, required runtime configuration, mail and timeout ordering. Keep existing local environment and credentials unchanged. Remove production localhost fallbacks from frontend logic. Supply sanitized production examples.
3. Add production-appropriate response headers, safe production exception responses/logging and secret-safe repository/package scanning. Preserve CSRF and server-side authorization. Review source-upload and subprocess/resource boundaries; fix demonstrated gaps without silently changing review decisions.
4. Add truthful administrator-only System Health API/page and CLI diagnostics for DB, writable private storage, actual Python/OCR doctor, actual offline Chromium PDF smoke rendering, queue heartbeat and backlog/stuck/failed counts. Separate tested readiness from worker liveness; never infer a live worker solely from queued-job counts. Add heartbeat storage/listeners and an authenticated, throttled probe action. Keep the public `/up` response minimal.
5. Remove demonstrated N+1 queries in dashboard verification and PDF source projection; add only justified additive indexes for report/history/verification/export lookups. Preserve exact eligibility and complete history. Measure query counts and check regression results.
6. Add an isolated three-client/all-existing-role authorization matrix, nested resource/download/export/sharing and deactivation/archival tests, configuration/header/health tests, upload/resource regressions and query-budget tests. Prepare an opt-in, guarded MySQL 8 integration harness using a separately provisioned empty test database; never point refresh tests at the active database.
7. Add safe operational tools: source-only deployment packaging with an allowlist (no uploads, environments, dumps, runtime caches or credentials), encrypted backup workflow/procedures with integrity manifests, disposable restore drill, and worker/scheduler/service configuration examples. Include Nginx and cPanel document-root/hosting requirements; no actual deployment or infrastructure changes.
8. Create all requested production, VPS, cPanel, server, environment, queue, backup/recovery, security, GitHub, user-manual, test-checklist and rollback documents. Map commands to the real application and clearly distinguish tested Windows behavior from unverified Linux/MySQL/hosting configuration.
9. Run full Laravel/React/Python tests, TypeScript and production build, dependency/secret scans, PDF render/permission/queue tests, disposable backup/restore drill and local health probes. Generate one new private Hann's Draft Preview smoke export; inspect completeness and retain the existing Draft/readiness state. Verify baseline row/file integrity.
10. Deliver a completion report with actual counts, files/migrations, severity/remediation, measured performance, artifact paths, hosting go/no-go, required services/extensions/packages, remaining operator configuration and unverified gates. Stop at Stage 7; no live deployment or GitHub push.

## Acceptance gates

- All existing business data, accounts, uploaded evidence and prior PDF/history artifacts checksum/row-verified unchanged; only intentional additive operational/export events.
- New internal operations cannot be accessed by SEO/client roles or another organization; internal diagnostics contain no paths, credentials, tokens or raw exception details in public responses.
- New multi-client fixtures remain disposable. Existing publication/readiness/privacy and immutable snapshot checks continue to pass.
- Health reports actual checks with Healthy/Warning/Unavailable/Not configured/Not verified labels. Missing heartbeat is Not verified, not a false healthy report.
- Runtime/dependency/hosting/backup limits are documented honestly. Production go/no-go remains conditional on real Linux/MySQL/HTTPS/mail/backup and hosting checks, and deployment always requires explicit approval.


## Execution record

All ten local preparation steps completed; detailed results are in STAGE_7_COMPLETION_REPORT.md. MySQL 8 test remains deliberately skipped, external production acceptance gates remain open, and no deployment/push/Stage 8 work was performed. Synthetic and separately restored backup fixtures were used; the active Hann's data passed row/checksum preservation checks.
