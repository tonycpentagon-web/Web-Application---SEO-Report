# Start and sharing boundaries

From the installed folder in normal PowerShell:

```powershell
.\local\Start.ps1 -OpenBrowser
.\local\Health.ps1
```

The starter checks its recorded location and port, then launches hidden, recorded wrappers for the loopback PHP web server, ONE documents/exports/default queue worker and scheduler. The browser serves the prebuilt React app and same-origin API. Logs are private under local-runtime. HTTP success and wrapper PIDs do not prove OCR/PDF/queue health: check Super Admin System Health heartbeats, upload a scanned fixture, generate and export a report.

Option A ? independent copies: every installation has its OWN database, accounts, sources and reports. There is no synchronization system. Copying a public link does not transfer a report to another employee's independent copy.

Option B ? one internal LAN host: all employees use one host's database/private uploads through its internal HTTPS address. This requires a deliberately configured XAMPP Apache virtual host/reverse proxy with the frontend document root and Laravel backend/public routing, private storage outside served paths, a stable LAN address/local DNS, trusted internal TLS, matching APP_URL/FRONTEND_URL and Sanctum/session origin settings, an explicitly approved LAN-only firewall rule, secure individual employee accounts, a permanently available host, persistent queue/scheduler, monitored disk and private backups. Test multiple authenticated employees, upload limits, worker failures, PDF and path-denial from a second machine. Use MariaDB/MySQL only after compatibility testing if that LAN workload needs it. Do not point Apache at the repository root.

The provided starter binds ONLY 127.0.0.1 and is not a LAN deployment script. Do not change it to 0.0.0.0 or expose PHP/Vite development servers publicly. No firewall/network changes have been performed. A configured LAN host is a separately reviewed setup; it has not been tested on a second computer here.

Public-link mode means anyone holding an explicitly enabled, expiring link can view THAT eligible publication without logging in. Account sharing remains the default. A localhost link works only on the same computer; a private LAN link requires network access to that host. External client links ultimately require accessible, explicitly approved secure hosting or another approved access method. This local evaluation performs no remote deployment or public tunnelling.
