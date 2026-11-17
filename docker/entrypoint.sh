#!/bin/sh
set -e

cd /app

php artisan storage:link --force >/dev/null 2>&1 || true

# Run migrations before the web server, queue worker and scheduler start.
# Retries while the database is still coming up. Set RUN_MIGRATIONS=false to skip.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    attempt=1
    until php artisan migrate --force; do
        if [ "$attempt" -ge "${MIGRATION_RETRIES:-30}" ]; then
            echo "Migrations failed after $attempt attempts, exiting." >&2
            exit 1
        fi
        echo "Database not ready, retrying migrations in 5s ($attempt)..." >&2
        attempt=$((attempt + 1))
        sleep 5
    done
fi

php artisan optimize

exec "$@"
