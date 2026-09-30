FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip pcntl sockets dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /game
COPY VERSION VERSION
COPY packages/game-core packages/game-core
COPY apps/server apps/server
WORKDIR /game/apps/server
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000 8081
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
