# Environment configuration

Sanitized templates live in deploy/env. Copy privately into a fresh release and replace placeholders; never copy development credentials to production or publish .env. Do not change this existing workspace's key, passwords, users or data. Generate an APP_KEY only for a genuinely new installation; an established environment must retain its key, including during rollback/recovery.

APP_ENV=production and APP_DEBUG=false activate fail-closed ProductionConfiguration checks. HTTPS APP_URL and FRONTEND_URL must be explicit real origins. The default production frontend API is same-origin; leave VITE_API_URL blank for the supplied VPS topology. Browser VITE_* values are public: never put credentials in them.

Use a dedicated non-root MySQL account and private password, strict utf8mb4/InnoDB, database or Redis cache/session/queue, encrypted secure HttpOnly cookies, and exactly the approved frontend origin for credentialed CORS. Sanctum stateful domains must include the actual frontend host (and port where needed). Cookie domain must match the chosen topology. Never use wildcard origins with cookies. Cross-subdomain cPanel hosting requires explicit CORS, cookie/Sanctum validation and CSRF browser tests.

Set DOCUMENT_PYTHON, DOCUMENT_PROCESSOR_SCRIPT, DOCUMENT_TESSERACT, PDF_NODE and PDF_RENDERER_SCRIPT to actual executable/script paths. See backend/config/documents.php and pdf.php for all limits. Configure PDF timeout below 240 seconds; database/Redis retry_after must exceed the document timeout by more than 60 seconds (template 1200). Keep private filesystem root outside backend/public and frontend/dist; local signed-file serving stays disabled.

Configure actual SMTP/SES/Postmark/Resend credentials and sender identity; test password setup/reset delivery in a controlled test mailbox without exposing tokens to logs. Static preflight cannot verify SMTP delivery, TLS, DNS or host permissions. Behind a reverse proxy, configure only known trusted proxy addresses; the example assumes TLS terminates directly at Nginx. Do not trust arbitrary forwarded headers.

Run production:check --production with target configuration and system:health --probe. Never print environment contents or config:show secrets into tickets. Source packages exclude cached config; rebuild caches per release after configuration is correct. Keep encrypted environment recovery material outside Git and outside the public root.
