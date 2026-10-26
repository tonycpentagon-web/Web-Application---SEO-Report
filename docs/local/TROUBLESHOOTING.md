# Troubleshooting

Installation refuses existing configuration: extract into a new empty folder; do not remove a real .env/database to bypass the guard. A partially failed installation can be inspected/backed up and abandoned for a fresh folder.

PHP platform/extension failure: use a compatible XAMPP PHP executable and enable extensions in its own php.ini. Existing XAMPP PHP may be older than PHP 8.2 or the bundled dependency requirements. Run that PHP's vendor/composer/platform_check.php. No system PHP configuration is changed automatically.

Python imports/OCR fail: inspect local-runtime settings and install output. Default setup provisions an isolated Python 3.12 environment using the pinned Windows requirements. If pip/native DLL/model loading fails, resolve it before accepting scanned inputs. documents:doctor and an actual scanned fixture distinguish unavailable OCR from successful extraction. Do not fabricate values to fill gaps.

Port occupied or app already running: Start refuses it. Use Health and Stop for this installation. Do not kill unrelated XAMPP/Node services. Browser address must use the configured port consistently; avoid switching localhost/127.0.0.1 during login. Private window tests must first choose the intended account/public link mode.

Login CSRF/session error: ensure same-origin React production build (VITE_API_URL=/ at build), matching frontend/app URLs, writable encrypted database sessions and correct app key. Local HTTP uses non-secure loopback cookies; LAN HTTPS requires deliberate secure-cookie/origin configuration. Do not disable CSRF.

Sources stay queued: keep the single worker running. Check worker/error logs, System Health heartbeat, document warnings and paths. A new file invalidates Draft data and increments its revision; refresh before submitting. Stale attempts require explicit retry. Removal/reset/Trash blocks while extraction, generation, normalization or PDF rendering is active. Stop processes only when idle.

PDF fails: confirm Node, renderer modules and actual Edge path. Edge may be installed under Program Files rather than Program Files (x86). Inspect the failed export and retry after fixing runtime. Health checks alone do not prove fonts/page layout.

Public link unavailable: Drafts cannot be shared. Publication must still be eligible, active and untrashed; link must be unexpired/unrevoked with public mode explicitly selected. Restoring never reactivates revoked links. Account links require an active assigned Client Viewer. localhost is not accessible from another computer. Cross-client denial is expected, not a processing failure.

Trash cannot purge: check role, typed confirmation, retention age, active jobs and source audit references. Reopen Actions if dependency counts changed. Restore parent client/project/report first. A directly referenced source is retained until its owning report can be purged. The scheduler removes only files explicitly queued by an authorized permanent deletion; no automatic Trash purge runs.

Use backup recovery before any destructive troubleshooting. Never truncate reporting tables, delete originals, replace the application database, approve all records or publish real client reports to make a test appear successful.
