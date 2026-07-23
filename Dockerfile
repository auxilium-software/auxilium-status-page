
# step 1: vendor
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader


# step 2: php runtime
FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y libsqlite3-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN mkdir -p /var/lib/aux-status \
    && chown -R www-data:www-data \
        /var/lib/aux-status \
        /var/www/html

USER www-data

CMD ["php-fpm"]
