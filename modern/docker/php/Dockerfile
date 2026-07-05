FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev linux-headers \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl pdo_pgsql pgsql pdo_mysql zip opcache pcntl \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

WORKDIR /var/www/html

# Dev flow bind-mounts the app; prod images COPY it in instead.
EXPOSE 9000
CMD ["php-fpm"]
