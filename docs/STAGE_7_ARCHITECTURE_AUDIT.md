# Stage 7 architecture audit

Initial architecture inspection and final local verification, 19 October 2026. The plan was recorded before application-code changes.

| Area | Existing implementation | Audit finding / action |
| --- | --- | --- |
| Backend | Laravel 12 REST; Sanctum stateful browser sessions; domain services; private filesystem; ULID project/report resources | Preserve architecture. Add production preflight and safe operational endpoints. |
| Frontend | React 19/TypeScript/Vite 7; routes, API/auth hooks, paginated review tables, lazy report dashboard | API fallback is hardcoded to loopback. Use same-origin production default; add lazy administrator health page. |
| Database | SQLite locally, configured MySQL driver with utf8mb4/strict mode; additive history migrations and composite report/project FKs | MySQL runtime not yet verified. Add focused indexes and isolated test guard/harness. |
| Auth/roles | Existing Super Admin/Admin/SEO Manager/SEO Executive/Client Viewer; session rotation; active-account middleware; strong password setup/reset | Preserve roles/credentials. Add three-client denial matrix and production cookie/CORS/CSRF configuration tests. |
| Isolation | Project/report scoping in WorkspaceAccess and DocumentAccess; private originals; scoped export/publication/share IDs | Exercise every resource family and role; no front-end-only authorization. |
| Uploads | Size/batch/MIME/signature/archive/XML/macro/image checks; random private filenames; duplicate checksum index; upload progress | Preserve evidence. Add filename/resource edge-case regressions and production upload-size consistency checks. |
| Python | Local JSON stdin subprocess, per-run output, bounded pages/cells/assets/JSON, OCR flags and source checksums | No imported URL fetching found. Keep OS subprocess isolation/egress policy in deployment prerequisites; verify dependencies. |
| Queues | Dedicated document/export queues, atomic claims, timeouts, explicit retries, retained failed attempts | No independent worker liveness signal; add per-queue heartbeat and read-only stuck/backlog visibility. |
| Normalization/review | Fingerprint-bound source evidence and separate explicit human verification; correction and warning history | Per-record verification lookups and permission queries create N+1 overhead. Batch reads without changing decisions. |
| PDF | Offline Chromium, disabled JS/service workers/network; private frozen export payloads; two profiles; 27-page Draft Preview | Repeated document/asset queries during snapshot capture. Batch source catalogues; keep source completeness. |
| Publishing/sharing | Transactional readiness, immutable publication hash/version, hashed expiring/revocable tokens, private drafts, client download permissions | Preserve all safeguards; do not publish Hann's. Retest public-token and client-account denial. |
| History | Extraction/normalization attempts, corrections, verifications, summaries, snapshots, exports and access events retained | No destructive migration/cleanup outside documented Draft artifact policy. Protect all existing history in baseline. |
| Environment/errors | Local development defaults; production environment example absent; Laravel default error handling | Add fail-closed production validator, sanitized examples, safe error logging/responses and headers. |
| Operations | Local workers and doctor; no deploy package, service configs, backup/restore workflow or health UI | Prepare reviewed artifacts and executable local-safe checks. No deployment. |
| Version control | No `.git` directory observed in checkout; current ignore rules omit explicit private storage/dump/archive protections | Strengthen ignore/allowlist scanning; do not initialize, stage, commit or push automatically. Historical Git secret audit cannot be claimed without a repository. |

The initial production assessment is **NO-GO pending runtime/security/operational verification**. A successful local test suite alone does not prove Linux, MySQL 8, TLS, SMTP, queue supervision or offsite recovery readiness.


## Final local disposition

The listed production configuration, error/header, upload-name, diagnostic, query-batching, additive-index, test and operational-document changes are implemented. See STAGE_7_COMPLETION_REPORT.md for file inventory and evidence. Local backend/React/Python tests, real Draft PDF, health probes and preservation/restore checks passed. No architecture replacement, account/key modification, record approval or deployment occurred.

Remaining high-severity release gates are actual MySQL 8, data conversion/migration rehearsal, Linux process/browser/OCR verification, public HTTPS/session/CSRF checks, controlled SMTP delivery, production load/supervisor behavior, encrypted offsite recovery and repository history review. The only available SQL client identifies as MariaDB 10.4; compatibility is not inferred from it. cPanel stays NO-GO without concrete provider support. A supported VPS is the recommended conditional target, not an approved deployment.
