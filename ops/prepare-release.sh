#!/usr/bin/env bash
# No deployment is performed by Stage 7. Syntax/preparation only; operator approval is required.
set -euo pipefail
: "${PENTAGON_RELEASE:?absolute new release directory required}"
[[ "$PENTAGON_RELEASE" = /* && -f "$PENTAGON_RELEASE/backend/artisan" ]] || exit 2
cd "$PENTAGON_RELEASE/backend"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan production:check --production
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan system:health --probe
# This validates/prepares the release only. Promotion, worker restart, TLS and approval are separate.
