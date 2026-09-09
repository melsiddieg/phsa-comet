# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# COMET production image — Debian (bookworm) variant.
#
# Identical to the Alpine image in behaviour; the only reason this exists is
# package-mirror reachability. Networks that block dl-cdn.alpinelinux.org
# often allow deb.debian.org, so this build can succeed where the Alpine one
# cannot. Check first:
#
#   docker run --rm debian:bookworm-slim \
#     sh -c 'apt-get update >/dev/null && echo MIRROR-OK'
#
#   docker build -f docker/production/Dockerfile -t comet:latest .
#
# One image, three roles (CONTAINER_ROLE env):
#   app      → nginx + php-fpm on :8080 (default)
#   queue    → php artisan queue:work
#   migrate  → run migrations once, exit 0
#
# Unlike the dev compose stack, code is BAKED IN — no bind mounts.
# ---------------------------------------------------------------------------

# ---- Stage 1: vendor/ via composer (no dev deps) --------------------------
FROM php:8.4-cli AS vendor

# Optional corporate CA, for networks doing TLS inspection. Empty by default.
# On Debian this is the standard flow: drop certs in, run update-ca-certificates.
COPY docker/production/ca-certs/ /usr/local/share/ca-certificates/
RUN set -eux; \
    if ls /usr/local/share/ca-certificates/*.crt >/dev/null 2>&1; then \
      update-ca-certificates; \
      echo ">> Added corporate CA(s) to the system trust store"; \
    fi

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpq-dev git unzip; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip pcntl; \
    rm -rf /var/lib/apt/lists/*

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
FROM php:8.4-fpm

COPY docker/production/ca-certs/ /usr/local/share/ca-certificates/
RUN set -eux; \
    if ls /usr/local/share/ca-certificates/*.crt >/dev/null 2>&1; then \
      update-ca-certificates; \
      echo ">> Added corporate CA(s) to the system trust store"; \
    fi

# Build the PHP extensions, then drop the build-only packages again. This is
# the upstream docker-php pattern: mark everything auto, re-mark what we want
# to keep (including the runtime libs the built .so files actually link
# against), then autoremove the rest.
RUN set -eux; \
    apt-get update; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpq-dev $PHPIZE_DEPS; \
    apt-get install -y --no-install-recommends nginx supervisor; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pgsql zip opcache pcntl; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    pecl clear-cache; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark nginx supervisor > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
      | sort -u \
      | xargs -r dpkg-query --search 2>/dev/null \
      | cut -d: -f1 \
      | sort -u \
      | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

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
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache \
             /var/lib/nginx/tmp /var/log/nginx /run/nginx \
 && chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/log/nginx /run/nginx

COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV CONTAINER_ROLE=app \
    PORT=8080

EXPOSE 8080

# Laravel's built-in health route (bootstrap/app.php → health: '/up')
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD sh -c 'if [ "$CONTAINER_ROLE" = "app" ]; then curl -fsS http://127.0.0.1:${PORT}/up >/dev/null || exit 1; else pgrep -f "queue:work" >/dev/null || exit 1; fi'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
