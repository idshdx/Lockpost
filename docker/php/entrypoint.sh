#!/bin/bash
set -e

APP_DIR="/var/www/app"

# Ensure var/ exists and is fully owned by www-data before PHP-FPM starts.
# This prevents cache write failures when CLI commands (composer, console) run as root.
mkdir -p "${APP_DIR}/var/cache" "${APP_DIR}/var/log"
chown -R www-data:www-data "${APP_DIR}/var"

# Ensure config/pgp/ is owned by www-data so PHP-FPM can read the keys.
if [ -d "${APP_DIR}/config/pgp" ]; then
    chown -R www-data:www-data "${APP_DIR}/config/pgp"
fi

# Clear compiled container cache on every start so stale DI containers
# (e.g. after code changes to service constructors) never cause silent failures.
rm -rf "${APP_DIR}/var/cache/dev/Container"* \
       "${APP_DIR}/var/cache/prod/Container"* \
       "${APP_DIR}/var/cache/test/Container"* 2>/dev/null || true

exec "$@"
