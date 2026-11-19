# Imágenes de la aplicación (PHP 8.4):
#   dev   → desarrollo con el código montado como volumen (compose.yaml)
#   prod  → producción: código, dependencias y panel compilado dentro (compose.prod.yaml)
#   web   → nginx con los ficheros públicos, delante de prod

# --- Base común: PHP-FPM con las extensiones necesarias ---------------------
FROM php:8.4-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# libheif-examples trae heif-convert, para pasar las fotos HEIC del iPhone a JPG.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libheif-examples \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql redis pcntl intl zip gd bcmath exif opcache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html

# --- Desarrollo ----------------------------------------------------------------
FROM base AS dev

# Mismo UID que tu usuario para no tener problemas de permisos con el volumen.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

USER www-data

# --- Panel compilado (Vue + Vite) ---------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --- Producción ------------------------------------------------------------------
FROM base AS prod

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- nginx con los ficheros públicos -------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
