#!/bin/sh
# COMET production entrypoint.
#
# Roles (CONTAINER_ROLE):
#   app        nginx + php-fpm via supervisord (default)
#   queue      php artisan queue:work
#   migrate    run migrations once, then exit 0
#
# Optional flags:
#   RUN_MIGRATIONS=1   run `php artisan migrate --force` before starting
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and set it as a secret." >&2
    exit 1
fi

# Cache config/routes/views against the runtime environment. No .env is baked
# into the image — values come from real env vars (Compose env_file, Azure
# Container App settings, K8s ConfigMap, ...).
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$RUN_MIGRATIONS" = "1" ] && [ "$CONTAINER_ROLE" != "migrate" ]; then
    echo "Running database migrations..."
    php artisan migrate --force
fi

case "$CONTAINER_ROLE" in
    migrate)
        # One-shot: migrate and exit 0 so Compose/K8s can gate other services
        # on `service_completed_successfully`.
        echo "Running migrations and exiting..."
        php artisan migrate --force
        exit 0
        ;;
    queue)
        echo "Starting queue worker..."
        exec php artisan queue:work \
            --tries="${QUEUE_TRIES:-3}" \
            --timeout="${QUEUE_TIMEOUT:-3600}" \
            --max-jobs="${QUEUE_MAX_JOBS:-1000}" \
            --max-time="${QUEUE_MAX_TIME:-3600}"
        ;;
    app|*)
        echo "Starting nginx + php-fpm on :${PORT:-8080}..."
        exec supervisord -c /etc/supervisord.conf
        ;;
esac
