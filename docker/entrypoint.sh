#!/bin/sh
set -e

# Render injects $PORT; Caddy binds whatever SERVER_NAME points at.
export SERVER_NAME=":${PORT:-8080}"

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

# The reference data the workspace needs before anyone can record anything —
# the catalogue, the stockroom list, the expense headings, the main branch.
# Every seeder it calls leaves an already-populated table alone, so this fills
# what is empty on the first deploy and does nothing on the rest. It creates no
# accounts: those are minted one at a time with make:employee.
if [ "${RUN_SEEDERS:-true}" = "true" ]; then
    php artisan db:seed --class=ProductionSeeder --force
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile
