# Production deployment

**Current decision: NO-GO for actual deployment.** This stage prepares artifacts only. No domain, VPS, cPanel, live mail, production database, publication or public sharing has been activated.

Read STAGE_7_ARCHITECTURE_AUDIT.md, SERVER_REQUIREMENTS.md and PRODUCTION_TESTING_CHECKLIST.md first. VPS is the recommended topology, conditional on all host checks. Ordinary shared cPanel hosting is NO-GO unless Python/Chromium, CLI timeouts, processes, private storage and all checklist gates are proven.

1. Review the source allowlist/security report. Build frontend and install locked PHP/renderer/Python dependencies in an isolated staging release. Source package deliberately excludes vendor, node_modules, dist, .env, private storage and local tools; it is not a ready-to-serve production image.
2. Provision dedicated MySQL 8, service account, private persistent storage, TLS, mail and encrypted backups. Verify actual host binaries/extensions and Chromium libraries. Restrict database/backup access.
3. Populate private production environment from sanitized examples; preserve established APP_KEY. Run static production preflight before migrations/cache builds.
4. Run the separately guarded MySQL integration test on an explicitly named empty pentagon_test_* schema. Never use a live schema. SQLite-to-MySQL data transfer requires a separately reviewed staging conversion preserving every ULID, FK, JSON value, checksum and history row; no transfer has been performed or certified by this stage.
5. Take and verify a complete backup. Rehearse additive migrations in staging against restored data, then follow the reviewed change window. ops/prepare-release.sh shows dependency/preflight/migration/cache/probe commands; it is not an automatic promotion tool.
6. Configure web root, services and scheduler per selected hosting guide. Pass role/browser/upload/export/SMTP/backup/load checks on that exact release.
7. Only with explicit later authorization, atomically promote the reviewed release, gracefully replace workers, verify /up and protected health, then execute the smoke test. Keep the previous release and compatible backups. See ROLLBACK_PROCEDURE.md.

Do not expose the project directory, run migrate:fresh/db:wipe, reset data, approve content automatically or publish Drafts. The new operational migration is additive; its down method refuses destructive rollback. Caches must be built from target configuration, not copied from development.
