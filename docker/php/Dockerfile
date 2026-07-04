FROM php:8.2-fpm-alpine
ENV TAR_OPTIONS="--no-same-owner --no-same-permissions --no-overwrite-dir"
# 1) Install runtime & build-time deps (icu-dev includes icu-libs, icu-data-full & pkgconfig)
RUN apk add --no-cache icu-dev

# 2) Configure & install extensions
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl pdo_mysql mysqli

# 3) Hardening & non-root
WORKDIR /var/www/html
COPY public/. ./
RUN chown -R www-data:www-data /var/www/html
USER www-data


EXPOSE 9000
CMD ["php-fpm"]

