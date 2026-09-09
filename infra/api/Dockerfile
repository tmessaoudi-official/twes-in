# syntax=docker/dockerfile:1
# twes-in API on FrankenPHP (Caddy + PHP 8.5). One image for development and CI; APP_ENV decides the mode.
# Classic (non-worker) mode on purpose: worker mode keeps the kernel alive across requests and is a later, measured step.
FROM dunglas/frankenphp:1.12.7-php8.5-trixie

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV} \
    SERVER_NAME=:80

WORKDIR /app

RUN install-php-extensions pdo_pgsql intl zip opcache

COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

# Dependencies first so source edits do not invalidate the vendor layer.
COPY api/composer.json api/composer.lock api/symfony.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
 && composer run-script post-install-cmd --no-interaction

HEALTHCHECK --interval=5s --timeout=3s --start-period=20s --retries=10 \
  CMD php -r 'exit(str_contains((string) @file_get_contents("http://127.0.0.1/api/health"), "\"status\":\"ok\"") ? 0 : 1);'
