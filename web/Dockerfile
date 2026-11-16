# Image SILASE-EWS Web: FrankenPHP (Caddy + PHP 8.3) di Alpine, satu image untuk web dan worker.
#   docker build -t silase-web:prod .        (konteks: folder web/)
FROM dunglas/frankenphp:1-php8.3-alpine

RUN install-php-extensions pdo_mysql pcntl \
    && apk add --no-cache unzip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
# Dependensi dipasang lebih dulu agar lapisannya tidak dibangun ulang tiap kode berubah.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-autoloader
COPY . /app
RUN composer dump-autoload --no-dev --optimize --no-interaction

# Berjalan tanpa root; port 8080 tidak butuh hak khusus.
RUN mkdir -p /app/storage/log \
    && chown -R www-data:www-data /app/storage /data/caddy /config/caddy
USER www-data

ENV SERVER_NAME=:8080
EXPOSE 8080
