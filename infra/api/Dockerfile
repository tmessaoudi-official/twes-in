# syntax=docker/dockerfile:1
# twes-in API on FrankenPHP (Caddy + PHP 8.5). Two targets over one base, as the official Symfony Docker image
# lays them out: dev (compose.yaml, CI's e2e) and prod (compose.prod.yaml, a deployment). The last stage, prod, is
# what a build that names no target gets.
# Classic (non-worker) mode on purpose: worker mode keeps the kernel alive across requests and is a later, measured step.
FROM dunglas/frankenphp:1.12.7-php8.5-trixie AS base

ENV SERVER_NAME=:80

WORKDIR /app

# bcmath: the money calculator works on BcMath\Number (api/src/Fiscal/Domain/Calculation).
RUN install-php-extensions pdo_pgsql intl zip opcache bcmath
COPY infra/api/conf.d/10-app.ini "$PHP_INI_DIR/conf.d/"

COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

# Migrations run at container start, once the database answers (infra/api/docker-entrypoint.sh), as the official
# Symfony Docker image does; the healthcheck below therefore also says "migrated".
COPY --chmod=755 infra/api/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

HEALTHCHECK --interval=5s --timeout=3s --start-period=20s --retries=10 \
  CMD php -r 'exit(str_contains((string) @file_get_contents("http://127.0.0.1/api/health"), "\"status\":\"ok\"") ? 0 : 1);'

FROM base AS dev

ENV APP_ENV=dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

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

FROM base AS prod

ENV APP_ENV=prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/api/conf.d/20-app.prod.ini "$PHP_INI_DIR/conf.d/"

COPY api/composer.json api/composer.lock api/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY api/ ./
# The cache is warmed here, not at start, so the preload list opcache.preload reads exists before the server does,
# and the contract is exported as in dev, for a web image built over this one. Both boot the kernel, which refuses
# to start in prod without secrets of its own (src/Shared/Infrastructure/DevelopmentKeys.php): these two commands
# get placeholders, and nothing they write holds one, because %env()% is read when a request needs it.
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && composer dump-env prod \
 && APP_SECRET=build-only APP_MFA_KEY=build-only REALTIME_TOKEN_KEY=build-only REALTIME_API_KEY=build-only \
    sh -c 'composer run-script --no-dev post-install-cmd --no-interaction && bin/console api:openapi:export --output=var/openapi.json'
