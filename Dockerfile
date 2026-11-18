FROM php:8.4-cli-alpine

# pdo_mysql / pdo_pgsql: the real-database concurrency suite (REQ-RUNTIME-003; MySQL 8.4, MariaDB 11.4, PostgreSQL 16).
RUN apk add --no-cache git unzip bash libzip-dev icu-dev nodejs npm sqlite-dev postgresql-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) zip intl pdo_sqlite pdo_mysql pdo_pgsql

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

RUN npm install -g pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV XDEBUG_MODE=coverage
