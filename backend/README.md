# Pentagon Insight Flow API

Laravel 12 / PHP 8.2+ / Sanctum cookie sessions.

See [Stage 2 roles/API](../docs/STAGE-2.md), [account setup](../docs/AUTHENTICATION.md), [Windows installation](../docs/INSTALL-WINDOWS.md) and [architecture/API](../docs/ARCHITECTURE.md). The React application lives in ../frontend.

Run migrations with `php artisan migrate`. Then use `php artisan admin:create` in an interactive terminal to create your own Super Admin. Passwords are prompted securely and hashed. The regular team command is `php artisan app:create-user`. Neither command overwrites existing accounts.

Seeders never create authentication accounts. No shared/default credentials are supplied. After your new Super Admin has successfully signed in, `php artisan admin:disable-user` can safely disable one older account while preserving its projects and records. Read the account setup guide before using it.

`php artisan test` uses in-memory SQLite. Run Artisan commands from this directory. The ignored local .env currently selects SQLite because this machine does not have a running MySQL 8 service.
