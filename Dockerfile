FROM dunglas/frankenphp:1-php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    gd \
    intl \
    pdo_pgsql \
    sockets \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=dev \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    SERVER_NAME=:80

WORKDIR /app
