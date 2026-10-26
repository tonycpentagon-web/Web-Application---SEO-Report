#!/bin/sh
set -eu
# Read-only heartbeat schedule only: never run development's daily PDF cleanup in the staging drill.
while true; do php artisan schedule:run --no-interaction; date -u +%s > /runtime/scheduler-heartbeat; sleep 60; done
