# syntax=docker/dockerfile:1
# twes-in API on FrankenPHP (Caddy + PHP 8.5). Two targets over one base, as the official Symfony Docker image
# lays them out: dev (compose.yaml, CI's e2e) and prod (compose.prod.yaml, a deployment). The last stage, prod, is
# what a build that names no target gets.
FROM dunglas/frankenphp:1.12.7-php8.5-trixie AS base

# Worker mode (https://frankenphp.dev/docs/worker/): the kernel boots once per worker and serves request after request;
# symfony/runtime's FrankenPhpWorkerRunner resets the services between them and starts a fresh worker every 500. In
# every mode, so development and CI's e2e run what production runs; whatever a request leaves behind would show there.
ENV SERVER_NAME=:80 \
    FRANKENPHP_CONFIG="worker ./public/index.php"

WORKDIR /app

# bcmath: the money calculator works on BcMath\Number (api/src/Fiscal/Domain/Calculation). gd and exif: a product's
# photos are cut into thumbnails on the server, turned upright first as the phone that took them says.
RUN install-php-extensions pdo_pgsql intl zip opcache bcmath gd exif
COPY infra/api/conf.d/10-app.ini "$PHP_INI_DIR/conf.d/"

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

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

# The build this image is (scripts/build-version.sh, passed by the Makefile and CI): `/api/health` says it and the web's
# footer shows it. Last, so a new version reuses every layer above.
ARG BUILD_VERSION=""
ARG BUILD_COMMIT=""
ENV BUILD_VERSION=$BUILD_VERSION \
    BUILD_COMMIT=$BUILD_COMMIT

# Every command the developer and CI run on this project, in one image (compose.yaml, service `tools`): the dev stage's
# PHP plus what the gates call. Nothing of the repo is copied in; the working tree is mounted, so a gate reads what is
# on disk. The dependencies it installs are the working tree's own (api/vendor), made by the host user.
FROM base AS tools
ENV APP_ENV=dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
# php.ini-development caps a CLI process at 128M, and the functional suite alone peaks at 216M: the first container run
# died with "Allowed memory size of 134217728 bytes exhausted" in the DOM crawler. A test runner is not a web worker.
RUN printf 'memory_limit=-1\n' > "$PHP_INI_DIR/conf.d/40-tools.ini"
# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install -y --no-install-recommends bash ca-certificates git jq logrotate make perl python3 \
 && rm -rf /var/lib/apt/lists/*
# The Docker CLI and Compose, for the gates that drive containers through the host's socket.
COPY --from=docker:29.8.2-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:29.8.2-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
# A one-off command, not a server: no migrations, no worker, no health check.
ENTRYPOINT []
CMD ["bash"]
HEALTHCHECK NONE

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

# The build this image is (scripts/build-version.sh, passed by the Makefile and CI): `/api/health` says it and the web's
# footer shows it. Last, so a new version reuses every layer above.
ARG BUILD_VERSION=""
ARG BUILD_COMMIT=""
ENV BUILD_VERSION=$BUILD_VERSION \
    BUILD_COMMIT=$BUILD_COMMIT
