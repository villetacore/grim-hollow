FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip pcntl sockets opcache \
    && pecl install apcu-5.1.24 && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /game
COPY VERSION VERSION
COPY packages/game-core packages/game-core
COPY apps/server apps/server
WORKDIR /game/apps/server
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
# OPcache with the tracing JIT also for the CLI: the long-lived world process runs every game
# tick through it. APCu holds the rate limiter (CACHE_STORE=apc) in the FPM pool's memory.
RUN printf '%s\n' 'opcache.enable=1' 'opcache.enable_cli=1' 'opcache.validate_timestamps=0' 'opcache.memory_consumption=192' \
    'opcache.interned_strings_buffer=32' 'opcache.max_accelerated_files=20000' 'opcache.jit=tracing' 'opcache.jit_buffer_size=64M' \
    'realpath_cache_size=4096K' 'realpath_cache_ttl=600' 'apc.enable_cli=1' 'apc.shm_size=64M' 'expose_php=Off' \
    > /usr/local/etc/php/conf.d/game.ini
# The stock pool serves only 5 requests at once; with several players they queue behind each other.
RUN printf '[www]\npm = dynamic\npm.max_children = 32\npm.start_servers = 8\npm.min_spare_servers = 6\npm.max_spare_servers = 16\npm.max_requests = 5000\n' > /usr/local/etc/php-fpm.d/zz-game.conf
USER www-data
# Configuration, routes and events are cached at start, when the container's environment is known.
CMD ["sh", "-c", "php artisan optimize && exec php-fpm -F"]
