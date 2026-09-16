# syntax=docker/dockerfile:1
FROM php:8.4-cli-alpine AS base

# System libs needed to build PHP extensions, plus certbot: the scheduler
# container issues custom-domain certificates itself (see DomainService), so the
# exit code is known in the same process that holds the DB connection.
RUN apk add --no-cache \
    libpng libjpeg-turbo freetype libwebp libzip icu-libs postgresql-libs certbot \
    imagemagick imagemagick-libs \
  && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libzip-dev icu-dev postgresql-dev \
    imagemagick-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql pgsql gd zip bcmath intl exif pcntl opcache \
  # phpredis (PECL): Horizon is unreliable on predis (blocking pops), so ship
  # the C extension and set REDIS_CLIENT=phpredis in production.
  && pecl install redis \
  && docker-php-ext-enable redis \
  # imagick, for one thing GD cannot do: encode .ico. Intervention ships an
  # IcoEncoder for the Imagick driver only, and the platform favicon has to be
  # a real icon file. Everything else still goes through GD.
  && pecl install imagick \
  && docker-php-ext-enable imagick \
  && apk del .build-deps

# Upload limits. The php:8.4 image ships no php.ini at all, so PHP falls back to
# its compiled defaults — upload_max_filesize=2M, post_max_size=8M. Every upload
# endpoint caps at 5 MB (UploadLimits::MAX_KB), so a 2–5 MB scan was thrown away
# by PHP *before* any validator ran and came back as "The file failed to upload",
# naming no size, while the UI promised 5 MB.
#
# Ordered so the app is always the one that refuses: our cap (5 MB) < nginx
# (client_max_body_size 25m) < upload_max_filesize < post_max_size. PHP dropping
# a body produces a message no one can act on — it cannot name the cap it just
# enforced — so these sit above nginx's cap rather than near ours. Anything
# nginx lets through reaches the validator, which says the number.
# UploadLimits::guard() stays as the net for a deploy that ignores this file.
#
# Uploads stream to temp files, so the headroom costs no memory; post_max_size
# covers the whole multipart body (file + fields) and so stays above the file's
# own limit.
RUN { \
      echo 'upload_max_filesize=25M'; \
      echo 'post_max_size=26M'; \
      echo 'memory_limit=256M'; \
    } > "$PHP_INI_DIR/conf.d/uploads.ini"

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
