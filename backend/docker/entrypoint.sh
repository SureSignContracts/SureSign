#!/bin/sh
set -e

if [ "$1" = "queue" ]; then
    exec php artisan queue:work --tries=1 --timeout=480 --sleep=3
fi

if [ "$1" = "scheduler" ]; then
    exec php artisan schedule:work
fi

php artisan migrate --force
php artisan storage:link || true

exec php artisan serve --host=0.0.0.0 --port=8000
