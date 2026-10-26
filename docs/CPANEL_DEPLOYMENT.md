# cPanel deployment

**Default: NO-GO on ordinary shared hosting.** A PHP-only Laravel plan is insufficient. Require SSH/Composer, maintained Node, Python venv and required binaries/libraries, Chromium subprocess support, OCR, proc_open, sufficient memory/disk, 900-second job execution, flock or persistent worker supervision, private persistent directories, MySQL 8, HTTPS and cron. Obtain concrete provider evidence for every capability. MariaDB is not the requested MySQL 8 verification.

Preferred same-origin topology requires provider routing to Laravel for API/Sanctum/auth POST and SPA GET login/password routes. If the provider cannot support that routing, use separately configured frontend/backend hosts only after exact credentialed CORS, Sanctum domains, cookie domain, HTTPS and CSRF browser validation. Never solve this by moving the whole Laravel project into public_html or exposing private storage. The PHP document root must be backend/public; frontend root contains only built static assets. Private environment and storage stay outside public_html.

Use deploy/cpanel/cron.example only if long processes are supported; max-time=50 does not interrupt a job. Standard short cron kills can corrupt operational availability despite atomic retries. Persistent supervised workers are preferred. Scheduler and queue invocations must not overlap duplicate consumers. Review PHP limits and Chromium sandbox with provider; do not disable isolation to make unsupported hosting appear ready.

No provider, TLS, routing, mail, cron, Chromium or live MySQL checks were available in this workspace. If any requirement cannot be proven, select a suitable VPS. No cPanel installation or migration was attempted.
