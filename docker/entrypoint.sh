#!/bin/sh
set -e

cd /app

mkdir -p storage/app/public storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache

# public/storage -> storage/app/public (required for /storage/* URLs)
if [ ! -e public/storage ]; then
    php artisan storage:link --force --no-interaction 2>/dev/null \
        || ln -sfn ../storage/app/public public/storage
fi

if [ -n "$APP_KEY" ]; then
    php artisan config:cache --no-interaction 2>/dev/null || true
    php artisan route:cache --no-interaction 2>/dev/null || true
    php artisan view:cache --no-interaction 2>/dev/null || true
fi

exec "$@"
