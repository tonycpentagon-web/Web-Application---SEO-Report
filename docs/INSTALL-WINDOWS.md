# Installation and execution — Windows / XAMPP

## Requirements and the inspected machine

Use PHP **8.2+**, Composer 2, Node **22.12+** (22.14 tested), npm 11 recommended, and MySQL **8** for the target deployment. Enable PHP curl, fileinfo, mbstring, openssl, pdo_mysql, pdo_sqlite, sqlite3 and zip; DOM/XML/tokenizer/PDO are also required.

On 16 October 2026 this machine had XAMPP PHP 8.0.25 (incompatible), MariaDB 10.4.27 (not MySQL 8; stopped), Node 22.14.0 and npm 10.9.2. Composer was absent and Python was a nonfunctional Windows Store alias. A portable PHP 8.4.26 NTS x64 runtime and Composer were downloaded to ignored `.tools/`; XAMPP was not modified. The PHP archive was checked against the official SHA-256 release metadata.

**Current checkout:** dependencies, local environment, application key and SQLite migrations are already prepared. Follow the root README's run commands. Do not overwrite the current .env or regenerate an existing key merely to restart.

## Fresh checkout with an installed compatible PHP

Run PowerShell. These commands assume PHP 8.2+ and Composer are on PATH; use `& ..\.tools\php\php.exe` from backend/ instead of `php` if using this workspace's portable runtime.

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
php --version
composer --version
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Copy .env.example only when .env does not exist. All .env files, application keys, credentials, vendor/, node_modules/, runtime logs and local databases are ignored. Commit composer.lock and frontend/package-lock.json.

For the already installed portable Composer:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe ..\.tools\composer.phar install
```

If portable tools are missing, install a supported Windows PHP binary from [windows.php.net](https://windows.php.net/download/) and [Composer](https://getcomposer.org/download/). PHP may also require Microsoft's Visual C++ 2015–2022 x64 runtime. Do not point Laravel 12 at this machine's PHP 8.0 XAMPP binary.

## MySQL 8 target setup

Install and start MySQL 8 separately; do not replace or reuse the stopped XAMPP MariaDB service without planning that change. If MariaDB also runs, give MySQL its own port and update DB_PORT.

Connect using your MySQL 8 installation's mysql.exe client:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe' -u root -p
```

Run the following SQL, substituting a unique strong local password (the angle-bracket text is a placeholder):

```sql
CREATE DATABASE pentagon_insight_flow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pentagon_app'@'127.0.0.1' IDENTIFIED BY '<choose-a-local-password>';
GRANT ALL PRIVILEGES ON pentagon_insight_flow.* TO 'pentagon_app'@'127.0.0.1';
```

Set backend/.env:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pentagon_insight_flow
DB_USERNAME=pentagon_app
DB_PASSWORD=
```

Use a separate migration/deployment user and reduce the runtime user's privileges in production. No MySQL 8 service was available here, so MySQL execution remains unverified.

## SQLite local fallback

For development when MySQL 8 is unavailable, change only backend/.env:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

From backend/, create the file only if missing:

```powershell
if (-not (Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File | Out-Null }
php artisan config:clear
php artisan migrate --seed
php artisan admin:create
```

Seeders never create or modify authentication accounts. The admin:create command securely prompts for your chosen name, email, password and confirmation; password entry is hidden. The app:create-user command creates regular team accounts. See [secure account setup](AUTHENTICATION.md) for verification and safe replacement of older accounts. There is no public registration, shared credential, or automatically seeded client/report data.

For the current portable runtime these exact commands work from backend/:

```powershell
& ..\.tools\php\php.exe artisan config:clear
& ..\.tools\php\php.exe artisan migrate --seed
& ..\.tools\php\php.exe artisan admin:create
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

## Frontend

In another PowerShell terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
Copy-Item .env.example .env
npx.cmd --yes npm@11 ci
npm.cmd run dev
```

Copy the environment example only if .env is missing. npm 10.9.2 crashed resolving optional Vitest peers during installation; npm 11 installed the locked dependency tree successfully. Regular `npm.cmd run` commands work with npm 10 afterward.

Open **http://127.0.0.1:5173/login**. Vite uses strict port 5173. The API runs at **http://127.0.0.1:8000**; `GET /up` is the Laravel health route.

## Sanctum / CORS settings

```dotenv
# backend/.env
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173
SESSION_DOMAIN=null
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_COOKIE=pentagon_insight_session
```

```dotenv
# frontend/.env
VITE_API_URL=http://127.0.0.1:8000
```

Axios sends credentials and the X-XSRF-TOKEN header, obtaining /sanctum/csrf-cookie before login and reset operations. Laravel's statefulApi middleware protects API mutations. CORS allows the single configured frontend origin and credentials. Port belongs in SANCTUM_STATEFUL_DOMAINS; scheme does not.

Use the same hostname for frontend and API. Mixing localhost with 127.0.0.1 causes cookies to fail. After backend environment edits run `php artisan config:clear`; restart Vite after frontend edits. For 419 errors refresh the page and sign in again. For 401 check session lifetime, hostname and stateful domain. For CORS errors confirm the exact frontend origin including port.

Implementation follows [Laravel 12 Sanctum SPA authentication](https://laravel.com/docs/12.x/sanctum#spa-authentication).

## Password recovery and mail

In local development MAIL_MAILER=log writes reset messages/links to the ignored backend/storage/logs/laravel.log. The UI returns the same response for known and unknown addresses. This mode does not deliver email. Configure a real SMTP/provider transport, sender identity and TLS in deployment. Reset links expire after 60 minutes and are single-use. New passwords require 12+ characters including uppercase, lowercase, number and symbol. A password change or reset deletes database sessions, rotates the remember token and requires a new login.

## Queue

Database queue/failed job tables are migrated; Stage 1 dispatches no report jobs. A worker is optional:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan queue:work --tries=3 --timeout=90
```

## Verification commands

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan test
& ..\.tools\php\php.exe ..\.tools\composer.phar audit
Set-Location ..\frontend
npm.cmd test
npm.cmd run build
npm.cmd audit
Set-Location ..
node docs/smoke.mjs
```

The HTTP smoke check starts and stops its own PHP server on a free port, creates a random temporary SQLite database/account, and removes that temporary directory after completion. It never uses the configured application database. On a machine without .tools/php/php.exe, set `$env:PHP_BINARY='C:\path\to\php.exe'` first. Do not run it while Laravel configuration is cached; run `php artisan config:clear` first.

## Deployment boundary

This Stage 2 workspace is not a deployment certification. See STAGE-2.md for roles, report periods and browser walkthroughs. Use MySQL 8, HTTPS, APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, real mail delivery, a strong stable APP_KEY, backups, monitored logs and the correct production frontend/stateful domains. Protect stored reset links and limit log retention.

Only **backend/public/** is an API document root; serve **frontend/dist/** separately with SPA fallback to index.html. Never expose the repository root, reference reports, .tools, .env, vendor or storage as public content. The root .htaccess blocks serving this checkout through XAMPP's parent web root; use the documented loopback development servers. XAMPP Apache's old PHP module cannot run this application. Configure a compatible PHP handler and explicit public document root before using Apache for it.

The Vite and Artisan servers are local development servers. Reporting-period workflows and explicit published-report sharing are enabled. Stage 3 report uploads, queue jobs and Python/OCR are implemented. Follow [STAGE-3.md](STAGE-3.md) for the required separate documents worker, Python installation, upload limits and processing diagnostics. Dashboard conversion remains deferred.

## Stage 4 additions

Run the additive migrations; keep the existing environment and account. Normalization uses the existing documents queue and installed Python runtime; it does not need an external AI service. See [Stage 4 exact commands and data-review walkthrough](STAGE-4.md) and [live verification/limitations](STAGE-4-VERIFICATION.md). Restart long-running queue workers after updating code. Use only one worker with the local SQLite setup.
