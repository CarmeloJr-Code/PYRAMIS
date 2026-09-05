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

exec frankenphp run --config /etc/frankenphp/Caddyfile
