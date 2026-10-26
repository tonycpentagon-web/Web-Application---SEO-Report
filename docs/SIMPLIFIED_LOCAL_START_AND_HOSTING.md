# Local startup and later hosting

## Existing checkout (Windows / XAMPP)

Keep the current database and backend/.env. Do not reinstall or seed this checkout. Use the bundled compatible PHP; XAMPP PHP 8.0 cannot run this Laravel application.

From D:\xampp\htdocs\pentagon-insight-flow, use three PowerShell terminals:

```powershell
Set-Location backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan documents:doctor
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

```powershell
Set-Location frontend
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

```powershell
Set-Location backend
& ..\.tools\php\php.exe artisan queue:work --queue=documents,exports --tries=1 --timeout=960 --memory=512
```

Open http://127.0.0.1:5173/login. Keep exactly one SQLite worker running. Use Ctrl+C in each terminal to stop processes you started there. Restart long-running queue workers after application code changes. The processes started by this session run hidden; their private logs are under backend/storage/logs/local-*.log.

The separately packaged installer uses local/Start.ps1 and its recorded local-runtime settings (usually port 8787). It is not an installation replacement for this existing checkout.

## Later hosting requirements

These are this repository's deployment requirements, not a hosting compatibility certificate. No Docker installation or demo.pentaworkspace.com deployment was performed.

- HTTPS web server exposing only frontend build assets and backend/public; private originals, extracted assets and PDFs outside all public document roots.
- Compatible PHP CLI/FPM and locked Composer dependencies; PHP 8.4 is the locally verified runtime. Required PDO/database, mbstring, fileinfo, curl, XML/DOM, ZIP and OpenSSL support. Subprocess execution must be enabled.
- The repository's production checks require tested MySQL 8, strict mode, utf8mb4, InnoDB and a dedicated application account. Windows SQLite testing does not verify a MySQL host.
- Python compatible with the pinned document-processor requirements, plus a tested OCR engine for scanned documents. Run documents:doctor on the target host.
- Node matching the frontend/renderer package engines and Playwright Chromium or a compatible configured browser with fonts and OS libraries. Verify PDF layout on the host.
- Durable queue workers for documents and exports, monitored/restarted by the service manager; scheduler for maintenance. Queue retry_after must exceed processing timeouts; shutdown grace must exceed the longest job. Budget Python and browser memory separately from PHP.
- Persistent private storage, adequate disk space for originals/images/PDFs, encrypted backups, and tested restore procedures. Keep the application encryption key stable and private because saved copyable links are encrypted with it.
- Matching APP_URL, FRONTEND_URL, Sanctum/CORS/session origins and secure cookies. Public share URLs must point at the reachable HTTPS frontend. SMTP is needed for account/password setup.
- Load-test the actual host with at least ten employee sessions and representative mixed documents before choosing worker concurrency. See SERVER_REQUIREMENTS.md and PRODUCTION_TESTING_CHECKLIST.md for the existing target-host gates.

Local report URLs use 127.0.0.1 and are not externally reachable. Changing a link's text does not expose the application to clients.
