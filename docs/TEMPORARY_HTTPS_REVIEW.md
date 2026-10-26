# Temporary HTTPS review on Windows

Status on 20 October 2026: configuration and localhost browser verification completed. Public tunnel startup was rejected by automatic approval review pending explicit user approval of Cloudflare as the traffic provider. No public hostname has been issued or tested. Do not describe the localhost results as external HTTPS verification.

## Design and scope

Cloudflare Quick Tunnel -> loopback Node reverse proxy (8820) -> private loopback PHP origin (8821). The proxy serves the production React build and forwards a limited set of Laravel routes. Vite and the existing backend port are never tunneled. Cloudflare terminates HTTPS and transports authenticated report content; application login is still required. No Cloudflare account or production/demo changes are needed. Official documentation: https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/ .

The review snapshot contains the existing Hann's Pharmacy August 2026 Draft. This is a point-in-time copy, not a live editing connection to the working database. Its original source files are copied to private storage for application/PDF consistency, but their web routes are blocked. Only report-linked derived evidence images and the client logo can be fetched, and Laravel still checks login and project permissions. Existing accounts are disabled only in this copy; a dedicated temporary SEO Executive account is assigned to the selected project. Publication, share creation, approvals, uploads, source downloads/previews/raw extraction, administration, health/debug APIs, source maps and arbitrary SPA routes are denied by the proxy. This review is for the interactive report and private client-preview Draft PDFs; other workspace actions are intentionally unavailable.

`Prepare.php` uses SQLite VACUUM INTO and copies private storage without updating working records. It clears cloned sessions/tokens/jobs/cache and revokes cloned share links. A dedicated exports-only worker uses the cloned queue and storage. No scheduler or source extraction worker is started. Nothing is published. The working .env, users, original files, report snapshots and ordinary local services remain unchanged.

## Prerequisites

Use the portable PHP at `.tools/php/php.exe`, Node on PATH, Edge at the installed default Windows path, backend vendor dependencies, and a React build compiled with `VITE_API_URL=/`. Startup checks the build and refuses cached Laravel configuration. No Docker or global service installation is used.

The portable Cloudflare binary was downloaded from the official Cloudflare GitHub release, version **2026.10.2**, and checked against the release asset SHA-256:

```
12c7c887f8aaab779aef1dfa9b60673530a5adb88ecc55dd6df8f56e0b70f199
```

Binary: `.tools/https-review/cloudflared.exe`. Release asset: https://github.com/cloudflare/cloudflared/releases/download/2026.10.2/cloudflared-windows-amd64.exe . Startup pins this checksum. Before upgrading, verify a new official release digest and deliberately update the pin. The binary and private review data are local runtime artifacts, not source release files.

## Start after provider approval

From `D:\xampp\htdocs\pentagon-insight-flow`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File local/review/Start.ps1
```

The script displays the exact newly issued HTTPS origin and direct private report URL; it also writes `.tools/https-review/url.txt` and `preview-url.txt`. Issuance alone does not prove connectivity. The gateway denies requests until its exact hostname is known. Both origin services and cloudflared metrics bind to loopback. Cloudflared runs with HTTP/2, no automatic update, and management diagnostics disabled.

Read the temporary account from `.tools/https-review/ACCESS.txt` locally and send those credentials privately to the authorized Team Lead. The account is not a production account and existing passwords are never changed. Do not put passwords in URLs, terminal transcripts, public documents or commits. The state directory ACL grants only the invoking Windows user, workspace owner and SYSTEM.

Optional additional Cloudflare email PIN protection (requires interactive email access and a separate browser verification plan):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File local/review/Start.ps1 -AllowedEmail 'lead@your-company.com'
```

Application login remains required behind the email gate. Do not use `verify.mjs` unattended with this option; it cannot complete an emailed PIN.

## Sessions, API routing and CSRF

The review PHP and worker processes receive environment overrides only: exact APP_URL/FRONTEND_URL/stateful domain, debug disabled, durable SQLite sessions and cache, encrypted sessions, `__Host-pentagon_review` cookie (Secure, HttpOnly, Path=/, no Domain, SameSite=Lax), and a 60-minute session lifetime. CORS permits exactly the review origin. React uses relative API paths, so no separate backend URL or cross-site cookie exception is needed.

The private router requires a random per-start origin secret and the exact review hostname. The gateway strips incoming forwarding/authorization headers and fixes Host and the origin secret. The router sets HTTPS only after validating that secret and hostname. Global Laravel proxy trust, authentication gates, CSRF middleware and production deployment validation are unchanged. APP_ENV=review is a dedicated isolated Windows evaluation environment; APP_DEBUG=false. No SMTP or external password email is enabled.

POST requests require the exact Origin, Laravel still validates CSRF, and payloads are limited to 16 KiB. Only private `draft` / `client_preview` exports with an explicit safe section list are accepted. The proxy allows PDF downloads only for exports requested through the current gateway session; generate a fresh Draft PDF after restarting the gateway. Internal audit and published exports are unavailable through this review origin.

## Verify before sharing

From the repository root, after the tunnel has started:

```powershell
node local/review/verify.mjs
```

This opens fresh Edge contexts without ignoring certificate errors, verifies guest rejection, real React login, session flags, charts, loaded report images, denied original/source/admin/publication routes, blocked internal-audit exports, queue completion and an actual PDF download. Evidence is private under `.tools/https-review/verification.json`, `report-review.png`, and `review-draft.pdf`. Check `transport` and `success`: localhost success is not an external result. An external fresh browser context verifies the public hostname from this machine; testing a second physical network remains a separate check if required.

For local-only engineering checks (no tunnel), use `Start.ps1 -LocalOnly`. It binds HTTP loopback and emulates the origin HTTPS server variable for cookie checks. This is not a shareable HTTPS URL.

## Stop after review

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File local/review/Stop.ps1
```

The stop script stops the public tunnel first, then the recorded private web/proxy/worker process trees. PID creation times protect unrelated processes from PID reuse. It refuses to erase the record if a process cannot be stopped. Run with Windows permissions sufficient to stop those recorded processes if access is denied. Existing application services and working data are untouched. The private snapshot/PDF evidence remains on disk; stopping never deletes business data. Every subsequent Quick Tunnel starts with a new hostname. Keep this machine awake during review.

## Actual validation

- Laravel AuthenticationTest, AdminAccountTest, ReportDeliveryTest and DocumentTest: 57 passed; 763 assertions.
- React: 108 passed across 14 files.
- Proxy route/security suite: 26 passed.
- Local fresh-browser login, eight charts, two evidence images, private queued PDF download: passed. PDF: 25 pages.
- Baseline preservation check: 15,684 pre-existing rows and 1,245 tracked files unchanged.
- Local process shutdown: tested; recorded test services stopped independently of the existing application.
- External HTTPS tunnel/login/charts/images/PDF and tunnel shutdown: pending provider approval and actual startup; not claimed as tested.
