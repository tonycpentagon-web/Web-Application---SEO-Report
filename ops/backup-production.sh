#!/usr/bin/env bash
# PREPARED TEMPLATE ONLY. Requires reviewed production paths and an encrypted restic repository.
set -euo pipefail
umask 077
: "${PENTAGON_RELEASE:?absolute release path required}"
: "${RESTIC_REPOSITORY:?encrypted restic repository required}"
: "${RESTIC_PASSWORD_FILE:?private password-file path required}"
: "${MYSQL_BACKUP_CNF:?private mysql option-file required}"
: "${PENTAGON_DB_NAME:?database name required}"
: "${PENTAGON_PRIVATE_ROOT:?absolute private storage root required}"
[[ "$PENTAGON_DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || exit 2
[[ "$PENTAGON_PRIVATE_ROOT" = /* && "$PENTAGON_RELEASE" = /* ]] || exit 2
command -v restic >/dev/null
command -v mysqldump >/dev/null
command -v supervisorctl >/dev/null
# Consistent database + files require stopping all writers, including queues, during this snapshot.
php "$PENTAGON_RELEASE/backend/artisan" down --retry=60
resume() {
    backup_result=$?
    trap - EXIT
    # Always attempt both recovery steps, even if one fails; signal every failure to monitoring.
    set +e
    supervisorctl start 'pentagon-documents:*' 'pentagon-exports:*'
    worker_result=$?
    php "$PENTAGON_RELEASE/backend/artisan" up
    app_result=$?
    if (( backup_result != 0 || worker_result != 0 || app_result != 0 )); then
        echo 'Backup or availability recovery failed; operator inspection required.' >&2
        exit 1
    fi
}
trap resume EXIT
# Supervisor stopwaitsecs must allow current jobs to finish. Verify both are STOPPED before proceeding.
supervisorctl stop 'pentagon-documents:*' 'pentagon-exports:*'
# Both snapshots share this explicit set marker; neither alone is a complete recovery set.
set_id="pentagon-$(date -u +%Y%m%dT%H%M%SZ)"
mysqldump --defaults-extra-file="$MYSQL_BACKUP_CNF" --single-transaction --routines --triggers --events --hex-blob --no-tablespaces "$PENTAGON_DB_NAME" | restic backup --stdin --stdin-filename database.sql --tag "$set_id" --tag database
restic backup "$PENTAGON_PRIVATE_ROOT" "$PENTAGON_RELEASE/backend/.env" --tag "$set_id" --tag private-files
restic check
# Retention/removal is a separate reviewed operator action; this script never forgets snapshots.
