#!/usr/bin/env bash
# Preparation only. No service changes, promotion, public deployment or credential generation.
set -euo pipefail
umask 077
[[ "$(uname -s)" = Linux ]] || { echo 'Use only on the audited Linux target.' >&2; exit 2; }
[[ "${PENTAGON_DEMO_COMPATIBILITY_APPROVED:-}" = yes && "${PENTAGON_DEMO_PREPARATION_APPROVED:-}" = yes && "${PENTAGON_BACKUP_VERIFIED:-}" = yes ]] || { echo 'Compatibility review, preparation approval and verified backup are required.' >&2; exit 2; }
: "${PENTAGON_RELEASE:?absolute unpromoted release path required}"
: "${PENTAGON_ENV_FILE:?target private environment file required}"
: "${PENTAGON_PYTHON:?target virtualenv python required}"
[[ "$PENTAGON_RELEASE" = /* && -f "$PENTAGON_RELEASE/backend/artisan" && "$PENTAGON_ENV_FILE" = /* && -f "$PENTAGON_ENV_FILE" && -x "$PENTAGON_PYTHON" ]] || exit 2
release="$(realpath "$PENTAGON_RELEASE")"
: "${PENTAGON_RELEASES_ROOT:=/srv/pentagon/releases}"
[[ "$(dirname "$release")" = "$(realpath "$PENTAGON_RELEASES_ROOT")" ]] || { echo 'Release must be a direct child of the reviewed releases directory.' >&2; exit 2; }
[[ "$release" != */current && ! -L "$PENTAGON_RELEASE" && "$(stat -c %a "$PENTAGON_ENV_FILE")" = 600 ]] || { echo 'Use a new release and private 0600 environment file.' >&2; exit 2; }
if [[ -e "$release/backend/.env" || -L "$release/backend/.env" ]]; then
    [[ "$(realpath "$release/backend/.env")" = "$(realpath "$PENTAGON_ENV_FILE")" ]] || { echo 'Existing environment differs; refusing to overwrite.' >&2; exit 2; }
else
    ln -s "$PENTAGON_ENV_FILE" "$release/backend/.env"
fi
cd "$release/backend"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan production:check --production
cd "$release/frontend"
npm ci
VITE_API_URL=/ npm run build
cd "$release/backend/pdf-renderer"
npm ci --omit=dev
# Install Chromium + OS libraries separately under the reviewed service account/browser path.
"$PENTAGON_PYTHON" -m pip install -r "$release/document-processor/requirements.txt"
cd "$release/backend"
php artisan documents:doctor
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan system:health --probe
printf '%s\n' 'Release prepared only. Complete target-host smoke/SMTP/restore/load checks; promotion and worker replacement require separate approval.'
