FROM php:8.2-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev \
    && docker-php-ext-install pdo pdo_pgsql intl opcache

WORKDIR /var/www/app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
