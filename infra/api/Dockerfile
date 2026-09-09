# syntax=docker/dockerfile:1
# twes-in API on FrankenPHP (Caddy + PHP 8.5). One image for development and CI; APP_ENV decides the mode.
# Classic (non-worker) mode on purpose: worker mode keeps the kernel alive across requests and is a later, measured step.
FROM dunglas/frankenphp:1.12.7-php8.5-trixie

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV} \
    SERVER_NAME=:80

WORKDIR /app

RUN install-php-extensions pdo_pgsql intl zip opcache
COPY infra/api/php.ini "$PHP_INI_DIR/conf.d/twes.ini"

COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

# Dependencies first so source edits do not invalidate the vendor layer.
COPY api/composer.json api/composer.lock api/symfony.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
 && composer run-script post-install-cmd --no-interaction \
 && bin/console api:openapi:export --output=var/openapi.json
# var/openapi.json is the contract the web image generates its TypeScript types from (compose.yaml: the web
# build takes this image as an additional context). Exported here, at build time, so a fresh clone needs
# nothing outside git to build the web image; it is the same command the CI api job runs for the web job.

# Migrations run at container start, once the database answers (infra/api/docker-entrypoint.sh), as the official
# Symfony Docker image does; the healthcheck below therefore also says "migrated".
COPY --chmod=755 infra/api/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

HEALTHCHECK --interval=5s --timeout=3s --start-period=20s --retries=10 \
  CMD php -r 'exit(str_contains((string) @file_get_contents("http://127.0.0.1/api/health"), "\"status\":\"ok\"") ? 0 : 1);'
