# Imagen PHP 8.4 para app, Horizon y scheduler (desarrollo).
FROM php:8.4-fpm-bookworm

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# libheif-examples trae heif-convert, para pasar las fotos HEIC del iPhone a JPG.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libheif-examples \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql redis pcntl intl zip gd bcmath exif opcache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Mismo UID que tu usuario para no tener problemas de permisos con el volumen.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

WORKDIR /var/www/html
USER www-data
