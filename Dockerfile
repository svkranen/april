FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions \
    intl \
    pdo_pgsql \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=dev \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    SERVER_NAME=:80

WORKDIR /app
