# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# syntax=docker/dockerfile:1.7

# ==============================================================================================================
# twes-in API — FrankenPHP.
#
# WRITTEN FROM SCRATCH. `invoiceninja/dockerfiles` is GPL-2.0, which is a STRONGER copyleft than the ELv2 the rest
# of that project carries, and copying a Dockerfile from it would make this artifact GPL-2.0 and oblige source
# disclosure for the derivative — killing the commercial branch this project is licensed for. Licensing invariant 7
# draws the line precisely: the deployment TOPOLOGY is an idea and free to reuse, the FILES are not. So the topology
# here (an app server, a database, a cache, a queue worker, a scheduler, a document renderer) is the industry's, and
# every line of every file in this directory is ours.
#
# WHY FRANKENPHP RATHER THAN php-fpm + nginx. FrankenPHP *is* Caddy with PHP embedded — one process, not two — so
# there is no FastCGI socket to secure, no second config language, and no `try_files` dance to get wrong. It is
# built by the author of API Platform and is what the API Platform distribution ships. It also brings automatic
# HTTPS, HTTP/2, HTTP/3, 103 Early Hints and Mercure for free, and it can serve the Angular and Flutter bundles from
# the same origin as the API — which is what keeps `mobile/web/`'s same-origin controls intact.
#
# Licences of what this image adds: FrankenPHP MIT, Caddy Apache-2.0, PostgreSQL client libs PostgreSQL licence
# (permissive). All on the permitted list in licensing invariant 8(a).
# ==============================================================================================================

# PINNED to an exact patch and PHP version, not a floating tag. A moving base image means the artifact you tested
# and the artifact you deploy are different builds — and for a product that renders legal documents, "it worked last
# Tuesday" is not a defence. Digest-pinning is the next step and is noted in infra/README.md as owed, because the
# digest has to be captured from a trusted pull rather than written by hand.
ARG FRANKENPHP_VERSION=1.12.6-php8.5-alpine

# ==============================================================================================================
# STAGE 1 — vendor. Composer dependencies only, so a source-only change does not re-resolve the dependency graph.
# ==============================================================================================================
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS vendor

# `git` is required and the reason is specific to this project: `composer.lock` contains one package with no `dist`
# URL, so installs run with `--prefer-source`, which CLONES. See CLAUDE.md § Gotchas, 2026-07-29 (corrected
# 2026-08-01) — this was misdiagnosed as blocked network egress for twenty certification rounds.
RUN apk add --no-cache git unzip

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app

# The manifests alone first, so this layer is cached until a DEPENDENCY changes rather than until any file does.
COPY api/composer.json api/composer.lock ./

# `--no-scripts` because no script should run before the application code is present, and `--no-dev` because the
# runtime image must not contain PHPUnit, php-cs-fixer, PHPStan or deptrac: a test runner in a production image is
# an attack surface that serves no request.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-source \
        --no-interaction \
        --no-progress \
    && composer clear-cache

# ==============================================================================================================
# STAGE 2 — runtime.
# ==============================================================================================================
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS runtime

# The extensions the application genuinely needs, and each one is here because something breaks without it:
#   bcmath   — the domain's EXACT decimal arithmetic. CLAUDE.md § Architecture: money is decimal strings over
#              bcmath, never floats, and `Domain/` has zero Composer dependencies because of it. Without this
#              extension the entire money layer raises.
#   pdo_pgsql — the only driver; tenancy is PostgreSQL row-level security.
#   intl     — locale-aware formatting for FR/AR/EN catalogues.
#   opcache  — see conf.d/opcache.ini.
#   zip      — document bundles (Factur-X containers), Wave 4.
# `install-php-extensions` ships in the FrankenPHP image and resolves the build-time headers itself, which is why
# there is no apk build-deps dance here to leak into the final layer.
RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && apk add --no-cache postgresql18-client tzdata \
    && rm -rf /var/cache/apk/*

# UTC everywhere in the container. Document dates are a DOMAIN concern resolved through `ClockInterface`, not a
# container setting, so this is only about log timestamps being comparable across replicas.
ENV TZ=UTC

WORKDIR /app

# Configuration BEFORE code, so a php.ini change does not invalidate the application layer.
COPY infra/api/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/api/Caddyfile /etc/frankenphp/Caddyfile

COPY --from=vendor /app/vendor/ ./vendor/

# The application. `.dockerignore` keeps tests, caches and the front-end sources out — the runtime image contains
# what serves a request and nothing else.
COPY api/ ./

# The autoloader is generated HERE rather than in the vendor stage, because it must see the application's own
# classes. `--classmap-authoritative` means no filesystem stat per class miss, which matters in a long-lived worker.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && rm -f /usr/bin/composer

# NON-ROOT. FrankenPHP binds :80 and :443, which normally needs privilege — `cap_net_bind_service` on the binary
# grants exactly that one capability instead of running the whole process as root. The alternative (root in the
# container) means a PHP RCE owns the container; this way it owns an unprivileged user with no shell.
RUN addgroup -g 10001 -S twes \
    && adduser -u 10001 -S -G twes -H -s /sbin/nologin twes \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    # Writable ONLY where the framework genuinely must write. Everything else is owned by root and read-only to the
    # runtime user, so a compromised process cannot rewrite its own code.
    && mkdir -p /app/var/cache /app/var/log /data /config \
    && chown -R twes:twes /app/var /data /config \
    && chmod -R 555 /app/src /app/config /app/public /app/vendor

# The Symfony cache is warmed at BUILD time, as the runtime user, so the first request does not pay for it and the
# cache directory does not need to be writable for compilation in production. APP_SECRET is a build-time dummy: it
# is never used to sign anything here and the real one arrives from the environment at run time.
USER twes
RUN APP_ENV=prod APP_SECRET=build-time-placeholder-not-a-secret \
        DATABASE_URL='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        DATABASE_URL_OWNER='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        php bin/console cache:warmup --env=prod --no-debug \
    && chmod -R 555 /app/var/cache

USER root
COPY infra/api/docker-entrypoint.sh /usr/local/bin/twes-entrypoint
RUN chmod 555 /usr/local/bin/twes-entrypoint
USER twes

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # FrankenPHP serves the Symfony front controller from here.
    SERVER_NAME=:80 \
    FRANKENPHP_CONFIG=""

EXPOSE 80 443 443/udp

# HEALTHCHECK hits LIVENESS, not readiness, and the distinction is load-bearing. Docker restarts an unhealthy
# container; readiness fails whenever the database is briefly away, so pointing this at `/health/ready` would turn
# a five-second database blip into a restart storm across every replica. Readiness belongs to the orchestrator's
# traffic routing, and `compose.yaml` wires it there.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error --max-time 2 http://127.0.0.1:80/health || exit 1

ENTRYPOINT ["twes-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
