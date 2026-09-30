FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip pcntl sockets opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /game
COPY VERSION VERSION
COPY packages/game-core packages/game-core
COPY apps/server apps/server
WORKDIR /game/apps/server
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
RUN printf 'opcache.enable=1\nopcache.validate_timestamps=0\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/game.ini
USER www-data
CMD ["php-fpm", "-F"]
