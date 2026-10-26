# Stage 7 security audit

Scope: local source, configuration, authorization tests and local runtime, 19 October 2026. Not a penetration test or hosting certification. Preserve all existing credentials, clients, original uploads, decisions and history.

Implemented: fail-closed production configuration validation; safe exception messages/log context; nosniff, framing/referrer/permissions/noindex/CSP headers and private API no-store; secure production cookies and exact CORS/Sanctum requirements; local filesystem public serving disabled; private export paths; filename NTFS/trailing-name hardening; Super Admin-only global health/probes; durable queue timing checks and heartbeat visibility; stronger source ignore/allowlist secret scan. Existing CSRF, session rotation, strong passwords, rate limits, scoped resources, content/signature/archive upload checks, source-bound reviews, atomic export claims and share-token hashing remain in place.

Three-client tests cover project/report/document/source/review/delivery resource families and foreign upload/download/retry/export/history denial. Role regressions cover multiple periods/projects, permitted Admin memberships, manager/executive/viewer differences, inactive users and archived clients. Other backend tests cover publication blockers, token expiration/revocation and worker retries. No approval or credential was changed on live data.

Dependency checks: Composer advisories empty; frontend npm audit zero vulnerabilities; renderer production npm audit zero vulnerabilities. Runtime Python package versions were queried against official PyPI version advisory metadata: 32 checked, zero unverified, zero listed findings. pip-audit could not run because embedded Python lacks venv; its result is NOT claimed. These feeds do not prove absence of vulnerabilities, OS/browser safety or future advisories. No runtime dependencies were upgraded.

Source package scan checks forbidden files and obvious secret patterns without printing matched secrets. It cannot establish history cleanliness: this checkout has no .git. No init/stage/commit/push performed. Review GITHUB_WORKFLOW.md before any future repository operation.

Remaining release blockers: actual MySQL 8 migration/integration and data conversion rehearsal; Linux Chromium/OCR/worker timeout behavior; HTTPS/cookie/CSRF and host headers; real controlled SMTP; encrypted offsite backup/restore; production capacity and monitoring. App static guards cannot verify those external facts. Existing unresolved Hann's warnings remain business publication blockers.

Primary references: [Laravel deployment](https://laravel.com/framework/docs/12.x/deployment), [OWASP upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html), [OWASP SSRF guidance](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html), [Playwright browsers](https://playwright.dev/docs/browsers), [MySQL dump](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html). The current host must be independently checked against these requirements.

## Severity and remediation status

| Severity | Finding | Status |
| --- | --- | --- |
| High | Unsafe production defaults / exposed errors / loopback frontend fallback | Remediated in code and tested; target configuration still required. |
| High | Private client material entering release or Git | Ignore rules and source allowlist scanner implemented; full Git history Not verified because checkout lacks .git. |
| High | Host isolation, TLS, actual MySQL 8 and encrypted offsite recovery unknown | Open release blockers; no deployment attempted. |
| Medium | Worker liveness and stale work not observable | Heartbeats and protected diagnostic UI implemented/tested; supervisor and host alert installation pending. |
| Medium | NTFS filename streams / ambiguous trailing names | Rejected by upload validator; regression tests pass. |
| Medium | Dependency risk | No advisories listed by the checked feeds; OS/browser/full supply-chain review remains a host responsibility. |
| Low | Reporting N+1 reads increase request pressure | Batched verification/document/asset queries and additive indexes implemented; host capacity remains unmeasured. |

No claim of zero security risk or complete endpoint penetration testing is made. Parameter-bound ORM queries, escaped React output, private source routes and offline renderer network blocking were inspected; malicious upload/share/authorization regressions are included in the backend suite. Manual adversarial testing on the final hosting topology remains required.
