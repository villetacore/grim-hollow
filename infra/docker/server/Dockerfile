FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip pcntl sockets dom xml xmlwriter opcache \
    && pecl install apcu-5.1.24 && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /game
COPY VERSION VERSION
COPY packages/game-core packages/game-core
COPY apps/server apps/server
WORKDIR /game/apps/server
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
# The dev server forks its workers after start-up, so they share OPcache and APCu memory.
RUN printf '%s\n' 'opcache.enable=1' 'opcache.enable_cli=1' 'opcache.jit=tracing' 'opcache.jit_buffer_size=64M' \
    'apc.enable_cli=1' 'apc.shm_size=32M' > /usr/local/etc/php/conf.d/game.ini
USER www-data
EXPOSE 8000 8081
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
