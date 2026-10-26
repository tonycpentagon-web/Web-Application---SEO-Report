# VPS deployment

Recommended after all production gates pass. No VPS deployment was performed.

Use a dedicated unprivileged pentagon service account; root installs only reviewed OS dependencies. Keep versioned releases under /srv/pentagon/releases, persistent backend storage and private originals outside every public root, environment protected by owner/group permissions, and encrypted backups off-host. Serve frontend/dist as static root and route API/Sanctum/auth POST requests through only backend/public/index.php. Review deploy/nginx/pentagon.conf.example for actual certificate paths, hostname and PHP-FPM socket. GET login/password screens must reach the SPA; POST auth reaches Laravel. Never expose source, vendor, .env or arbitrary PHP paths.

Install locked dependencies with Composer --no-dev, npm ci for frontend build and renderer runtime, and Python requirements in a dedicated venv. Install matching Playwright Chromium/OS libraries and verify it runs as the service account, without disabling its sandbox to hide host incompatibility. Configure Tesseract and fonts. Keep build tools out of public roots.

Review deploy/php/production.ini.example, supervisor and scheduler examples. Verify Nginx syntax, FPM config, HTTPS headers, upload body limits and writable paths. Restrict firewall to required HTTPS/administration; SQL should remain private. Apply OS updates, log rotation and resource alerts under host management policy.

Run the entire production checklist and an isolated recovery rehearsal. Confirm direct-TLS secure session behavior and CSRF login, upload, review, Draft export, denial across three clients, approved-only client view and expiration/revocation of synthetic test shares. Do not use Hann's unresolved report for publication tests.


Provision an empty MySQL 8 schema with a privileged operator account, not the application's runtime login:

```sql
CREATE DATABASE pentagon_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE pentagon_test_stage7 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Create distinct restricted application, migration, test and backup users using credentials generated privately outside command history. Grant only required schema privileges; migration DDL privileges need not remain on the normal runtime user. Backup account privileges must match the chosen MySQL dump options. Do not execute schema creation/import against an existing populated installation. Confirm server VERSION(), strict SQL mode, FK/index definitions and a tested backup before any migration. The opt-in test refuses a populated test schema and never drops it; use a new empty test schema for the next run.
