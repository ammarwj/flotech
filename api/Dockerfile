# syntax=docker/dockerfile:1
FROM php:8.4-cli-alpine AS base

# System libs needed to build PHP extensions.
RUN apk add --no-cache \
    libpng libjpeg-turbo freetype libwebp libzip icu-libs postgresql-libs \
  && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libzip-dev icu-dev postgresql-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql pgsql gd zip bcmath intl exif pcntl opcache \
  && apk del .build-deps

# Composer (from official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first (better layer caching).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Application code.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
  && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=8000
EXPOSE 8000

# Default: serve the API. Overridden by worker (horizon) and scheduler services.
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT}"]
