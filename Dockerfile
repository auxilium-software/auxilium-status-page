
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader


FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y libsqlite3-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN mkdir -p /var/lib/aux-status \
    && chown -R www-data:www-data /var/lib/aux-status /var/www/html

USER www-data
