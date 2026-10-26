# Account setup and authentication verification

## Investigation

The reported prefilled account was not found in this checkout's React source, built frontend bundle, application configuration, development fixtures or configured SQLite database. Login's Input wrapper did not supply values either. At inspection, the database contained zero users and zero projects, and both legacy seed environment settings were empty. No service was listening on the expected local frontend/API ports during inspection.

A browser password manager, extension, a cached page, or a different hosted/running build could explain the observation. Browser-saved credentials and the page the user was viewing were not accessible, so the exact external source cannot be established here. No password was read or printed during investigation.

The current form explicitly initializes both login inputs to empty strings. It reads no URL, environment, fixture or browser-storage credentials. Native username/current-password autocomplete remains enabled so a browser may intentionally fill credentials the user saved. The design, text, spacing and CSS are unchanged.

To distinguish browser autofill from this application, open the local login URL in a fresh browser profile with extensions disabled, or a private window whose password manager does not autofill. If the normal profile still supplies unwanted values, remove the saved login for that exact site/port in the browser/password manager. Ensure you are opening this local frontend rather than a different deployed application.

## Create your own Super Admin

Use an interactive PowerShell terminal, not an email, chat, command argument, environment variable or shell script containing your password.

For this workspace:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan migrate
& ..\.tools\php\php.exe artisan admin:create
```

With compatible PHP on PATH, the equivalent is:

```powershell
php artisan migrate
php artisan admin:create
```

Enter your chosen name, email, password and confirmation when prompted. Password entry is hidden and has no visible-input fallback. Use a terminal supporting hidden input; the command fails rather than echoing passwords if hidden input is unavailable.

The password must have at least 12 characters, uppercase, lowercase, a number and a symbol. It is stored through Laravel Hash and the User hashed cast. The command has no password option, accepts no environment credentials, and rejects noninteractive use. Only account IDs/role status enter provisioning logs. No default account is created.

A duplicate email is rejected without modifying the existing account or its projects. Existing users are not automatically promoted. Choose an unused email for your replacement Super Admin. The existing app:create-user command remains available for regular team accounts and never grants Super Admin privileges.

The migration adds is_super_admin=false, is_active=true and nullable last_login_at. Existing users retain access and ownership; no existing account is silently promoted, disabled or deleted. Stage 2 now gives Super Admins explicit system-wide access and introduces authenticated role-controlled team management. Other roles remain scoped to client/project assignments; see STAGE-2.md.

## Start and verify your login

Backend terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Frontend terminal:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\frontend
npm.cmd run dev
```

1. Open http://127.0.0.1:5173/login.
2. Enter the email and password you chose in the command.
3. Confirm the authenticated workspace opens.
4. Sign out, then sign in again to verify both flows.

Only a successful backend login records last_login_at. The safe-disable command requires this recorded login for the replacement Super Admin. No personal account was created during implementation because your chosen credentials must remain yours.

Use 127.0.0.1 for both services. If running a separate environment, use the command against that environment's configured database; the local database is not the hosted application's database.

## Safely disable an older test account

This is optional and is not run automatically. The reported account did not exist in the configured local database, so no account was disabled here.

After logging in successfully with your new Super Admin:

```powershell
Set-Location D:\xampp\htdocs\pentagon-insight-flow\backend
& ..\.tools\php\php.exe artisan admin:disable-user
```

The command prompts for:

1. The email of the exact account to disable.
2. Your different, verified replacement Super Admin email.
3. Your replacement Super Admin password (hidden).
4. Confirmation to disable the selected account; default is No.

It requires an active Super Admin with a recorded successful login and matching password. It refuses self-disable, invalid replacements and missing target accounts. It does not automatically select a default email.

On confirmation it marks only the selected account inactive, rotates its remember token and revokes its sessions, reset tokens and personal access tokens. It retains the user record, password hash, project ownership, projects and unrelated data. Its projects are not automatically transferred to the replacement account. Do not delete the old user as a replacement for this command: project foreign keys use cascade deletion.

Active-account middleware also blocks stale sessions on every protected request. Disabled users cannot log in or use password recovery to reactivate themselves.

## Seeders, secrets and authentication

DatabaseSeeder no longer creates any accounts, including from legacy environment settings. Those settings have been removed from the local environment example and current local .env. The test UserFactory now hashes a random secret rather than using a shared factory password. Fixed credential strings exist only in isolated test assertions, never as login defaults or production seed accounts.

Sanctum's stateful API middleware, CSRF-cookie handshake, credentialed CORS, session regeneration and logout invalidation are retained. Email matching for login/recovery is normalized to lowercase. Secrets remain hidden in user JSON, command output and custom logs. Password reset links still use the configured mail transport; local log-mail mode must not be used for production delivery.

## Validation

Run backend tests from backend/:

```powershell
& ..\.tools\php\php.exe artisan test
```

Run frontend tests and build from frontend/:

```powershell
npm.cmd test
npm.cmd run build
```

Run the real HTTP session check from the repository root:

```powershell
node docs/smoke.mjs
```

Regression coverage includes empty login fields, no URL/storage credential loading, native autofill attributes, credentials cleared on reopening, hidden interactive account provisioning, hashing, duplicate protection, validation, successful login verification, replacement verification, cancellation/self-disable guards, disabled login/recovery/protected-route rejection, targeted revocation and project preservation.

The HTTP smoke test uses a disposable random account/database and verifies Super Admin login, CSRF, cookies, identity, protected project APIs, logout and denial of an already signed-in account after it is disabled. No real user/project records are reset by tests.

MySQL 8 runtime and browser password-manager behavior remain unverified in this environment. Stage 2 is implemented; see STAGE-2.md for its permissions and verification.
