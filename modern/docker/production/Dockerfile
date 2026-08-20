# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# COMET production image — self-contained, Azure-ready.
#
#   docker build -f docker/production/Dockerfile -t comet:latest .
#
# One image, two roles (CONTAINER_ROLE env):
#   app    → nginx + php-fpm on :8080 (default)
#   queue  → php artisan queue:work
#
# Unlike the dev compose stack, code is BAKED IN — no bind mounts.
# ---------------------------------------------------------------------------

# ---- Stage 1: vendor/ via composer (no dev deps) --------------------------
FROM php:8.4-cli-alpine AS vendor

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev linux-headers git unzip \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl pdo_pgsql zip pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# Source not copied yet → skip artisan-dependent scripts here.
RUN composer install --no-dev --no-interaction --prefer-dist \
      --no-autoloader --no-scripts

COPY . .
# post-autoload-dump runs `artisan package:discover`, which needs these dirs
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 2: runtime ------------------------------------------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev linux-headers \
      nginx supervisor \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl pdo_pgsql pgsql zip opcache pcntl \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

# Production PHP configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/production/php-overrides.ini $PHP_INI_DIR/conf.d/zz-comet.ini

# nginx + supervisord
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# Application code with prebuilt vendor/
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Laravel writable paths; nginx temp paths
RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
      /var/lib/nginx/tmp /var/log/nginx /run/nginx \
 && chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/log/nginx /run/nginx

COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV CONTAINER_ROLE=app \
    PORT=8080

EXPOSE 8080

# Laravel's built-in health route (bootstrap/app.php → health: '/up')
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD sh -c 'if [ "$CONTAINER_ROLE" = "app" ]; then wget -q -O /dev/null http://127.0.0.1:${PORT}/up || exit 1; else pgrep -f "queue:work" >/dev/null || exit 1; fi'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
