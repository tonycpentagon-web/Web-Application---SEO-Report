#!/bin/sh
set -eu
[ "${APP_ENV:-}" = staging ] && [ "${DB_HOST:-}" = mysql ] && [ "${DB_DATABASE:-}" = pentagon_stage8_local ] && [ "${LOCAL_PRIVATE_ROOT:-}" = /private ] || { echo 'Refusing non-staging configuration' >&2; exit 2; }
[ -n "${APP_KEY:-}" ] && [ -n "${DB_PASSWORD:-}" ] || exit 2
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs /runtime
exec "$@"
