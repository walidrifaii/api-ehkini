#!/bin/sh
set -e

cd /app

if [ -n "$APP_KEY" ]; then
    php artisan config:cache --no-interaction 2>/dev/null || true
    php artisan route:cache --no-interaction 2>/dev/null || true
    php artisan view:cache --no-interaction 2>/dev/null || true
fi

exec "$@"
