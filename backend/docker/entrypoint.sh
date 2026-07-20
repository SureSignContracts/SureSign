#!/bin/sh
set -e

if [ "$1" = "queue" ]; then
    exec php artisan queue:work --tries=1 --timeout=480 --sleep=3
fi

if [ "$1" = "scheduler" ]; then
    exec php artisan schedule:work
fi

# Explicit, one-off release step — run this deliberately before/after a
# deploy (e.g. `docker compose -f docker-compose.prod.yml run --rm backend
# migrate`, or `docker exec suresign_backend php artisan migrate --force`
# against the already-running container), never automatically on every
# container start. Kept separate from `db:seed`: seeding is a deliberate,
# occasional operator action (initial setup, deliberate permission/role
# changes), not a routine part of every release.
if [ "$1" = "migrate" ]; then
    exec php artisan migrate --force
fi

# Local development only (see docker-compose.dev.yml's `command: ["dev"]`
# override) — restores the old always-migrate-and-seed convenience for a
# single-container local stack, where there's no rolling-deployment or
# concurrent-replica concern. Never used in production.
if [ "$1" = "dev" ]; then
    php artisan migrate --force
    php artisan db:seed --force
    php artisan storage:link || true
    exec php artisan serve --host=0.0.0.0 --port=8000
fi

# storage:link is idempotent (a no-op once the symlink exists) and cheap, so
# it's safe to keep on every boot. migrate/db:seed are deliberately NOT run
# here anymore: running them on every container start meant every restart
# reseeded the database and, more importantly, meant a future multi-replica
# rolling deployment could have two backend containers running `migrate
# --force` against the same schema at the same time. Migrations are now a
# separate, controlled step — see the `migrate` branch above — run once per
# release regardless of how many backend replicas exist.
php artisan storage:link || true

exec php artisan serve --host=0.0.0.0 --port=8000
