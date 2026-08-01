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
# STAGE 1 — base. The PHP platform, shared by the vendor and runtime stages.
#
# ONE stage installs the extensions and BOTH later stages derive from it, which is not merely a build-time saving.
# `composer.json` declares `ext-bcmath`, `ext-intl` and `ext-pdo_pgsql` as platform requirements, so a vendor stage
# without them cannot resolve at all — and the tempting fix, `--ignore-platform-reqs`, is the wrong one: it would
# let Composer pick versions against a platform the runtime does not actually have, so the mismatch would surface
# at run time instead of at build time.
#
# Each extension is here because something breaks without it:
#   bcmath    — the domain's EXACT decimal arithmetic. Money is decimal strings over bcmath, never floats, which is
#               why `Domain/` has zero Composer dependencies. Without this the entire money layer raises.
#   pdo_pgsql — the only driver; tenancy is PostgreSQL row-level security.
#   intl      — locale-aware formatting for the FR/AR/EN catalogues.
#   opcache   — see conf.d/10-opcache.ini.
#   zip       — document bundles (Factur-X containers), Wave 4.
# `install-php-extensions` ships in the FrankenPHP image and resolves the build headers itself, which is why there
# is no apk build-deps dance here to leak into a later layer.
# ==============================================================================================================
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS base

# ==============================================================================================================
# OPTIONAL SITE CA CERTIFICATES, trusted for the BUILD ONLY.
#
# Many corporate networks — and this project's own development container — sit behind a TLS-INTERCEPTING proxy,
# which presents its own certificate for every HTTPS connection. Without its CA, every network step of this build
# fails verification against hosts that work perfectly from the host machine.
#
# **THIS BLOCK IS IN `base`, NOT IN `vendor`, AND THAT WAS A BUG FOR ONE COMMIT.** It sat in the vendor stage on
# the reasoning that Composer is the thing that needs the network — but `install-php-extensions` below runs `apk`
# over HTTPS too, so on a proxied network the build died HERE, in the stage before the one carrying the remedy.
# [Verified: with no trusted CA, `install-php-extensions` exits 2 on
# `WARNING: ... APKINDEX.tar.gz: TLS: server certificate not trusted`.] A remedy that the failing step never
# reaches is not a remedy.
#
# `infra/api/ca-certificates/` is empty in a normal checkout (its `*.crt` are gitignored, because a CA is a
# site-specific trust decision this repository must not make for a reader), so this is a no-op unless someone put
# a certificate there.
#
# ONE CERTIFICATE PER FILE. Alpine's `update-ca-certificates` concatenates a multi-cert bundle into the trust
# store but refuses to hash-link it — `does not contain exactly one certificate or CRL: skipping` — so anything
# resolving trust through the certificate DIRECTORY rather than the bundle will not see it. Split a bundle first.
# ==============================================================================================================
# FLAT, NOT INTO A SUBDIRECTORY. This copied into `.../ca-certificates/twes-build/` for one commit, which
# namespaced our certificates nicely and meant NONE of them was ever trusted: Alpine's `update-ca-certificates`
# does not recurse, so it treated the directory as a file and skipped it --
# `Warning! Cannot copy to bundle: /usr/local/share/ca-certificates/twes-build`, followed by
# `ca-cert-twes-build.pem does not contain exactly one certificate or CRL: skipping`. The whole corporate-proxy
# story this block documents was inert. Note the shape: it FAILED LOUDLY on stdout for anyone reading a build log
# closely, and still shipped, because the visible error two steps later named `install-php-extensions` instead.
#
# `/usr/local/share/ca-certificates/` is the LOCAL ADMIN directory; the distribution's own CAs live in
# `/usr/share/ca-certificates/`. So this adds to the trust store without touching the base set, and the runtime
# stage can clear this directory wholesale without stripping the distribution's certificates.
COPY infra/api/ca-certificates/ /usr/local/share/ca-certificates/
# NO `apk add ca-certificates` HERE, and its absence is the fix rather than an omission. `apk` fetches its index
# over HTTPS, so installing the trust store is exactly the operation that cannot run before the trust store is
# installed -- a bootstrap deadlock whose visible error names `install-php-extensions` rather than the real cause.
# The package already ships in the pinned base image [Verified: `update-ca-certificates` on
# dunglas/frankenphp:1.12.6-php8.5-alpine with no `apk` at all took the trust store from 119 certificates to 271],
# which is one more reason the base image is pinned to an exact patch rather than a floating tag.
RUN rm -f /usr/local/share/ca-certificates/README.md \
    && update-ca-certificates

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        zip

# ==============================================================================================================
# STAGE 2 — vendor. Composer dependencies only, so a source-only change does not re-resolve the dependency graph.
# ==============================================================================================================
FROM base AS vendor

# `git` and `unzip` for Composer. `git` is not optional in every environment: where the GitHub API is
# authorization-scoped (this project's own container is one), Composer must CLONE rather than fetch zipballs, and
# that is what `COMPOSER_INSTALL_FLAGS` below exists for.
RUN apk add --no-cache git unzip

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# COMPOSER_HOME must be explicit. `composer config -g` writes to the global config and refuses without a home
# directory, and the base image sets none for the build user -- the failure is a bare `exit 2` with no message
# naming the cause. ALLOW_SUPERUSER because the build stage runs as root and Composer otherwise stops to warn.
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app

# The manifests alone first, so this layer is cached until a DEPENDENCY changes rather than until any file does.
COPY api/composer.json api/composer.lock ./

# `--no-scripts` because no script should run before the application code is present, and `--no-dev` because the
# runtime image must not contain PHPUnit, php-cs-fixer, PHPStan or deptrac: a test runner in a production image is
# an attack surface that serves no request.
# CONFIGURABLE, and not hardcoded to `--prefer-source`. A normal build should fetch dist zipballs, which is much
# faster; `--prefer-source` is only needed where the GitHub API is unreachable or authorization-scoped, which is
# true of this project's development container and not of a normal CI runner. Hardcoding it would make every build
# everywhere pay for a clone.
#
#   docker build --build-arg COMPOSER_INSTALL_FLAGS=--prefer-source ...
#
# `use-github-api false` is set only in that mode, because it is the setting that makes Composer clone instead of
# calling `api.github.com` — see CLAUDE.md § Gotchas, 2026-07-29 (corrected 2026-08-01), where this was misdiagnosed
# as blocked network egress for twenty certification rounds.
ARG COMPOSER_INSTALL_FLAGS=""

RUN if [ "$COMPOSER_INSTALL_FLAGS" = "--prefer-source" ]; then \
        composer config -g use-github-api false \
        && composer config -g github-protocols https; \
    fi \
    && composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        $COMPOSER_INSTALL_FLAGS \
    && composer clear-cache

# THE APPLICATION, AND THE AUTOLOADER, both in this stage — because Composer only exists in this stage.
#
# This was in the RUNTIME stage until it was found broken on a real machine: `runtime` derives from `base`, and
# `base` has no Composer [Verified: `docker run --rm dunglas/frankenphp:1.12.6-php8.5-alpine which composer` finds
# nothing], so the build died with `/bin/sh: composer: not found` and exit 127. The old line even ended in
# `rm -f /usr/bin/composer`, which is the tell: it was written as though this were the vendor stage.
#
# Doing it here is better than copying the Composer binary forward, not merely equivalent. The runtime image now
# never contains Composer at ALL — not in a deleted-but-still-layered form, which is what `rm` in a later layer
# actually produces — and that matches the argument this file already makes for `--no-dev`: build tooling in a
# production image is attack surface that serves no request.
COPY api/ ./

# `--optimize` builds the classmap. **`--classmap-authoritative` is DELIBERATELY ABSENT**, and that is a
# correctness decision rather than a performance oversight. Authoritative makes the autoloader return false for
# anything not in the classmap, skipping the PSR-4 filesystem lookup entirely — and `compose.override.yaml` MOUNTS
# `api/src` over this image, so every class added after the build would be invisible. [Verified on the host: with
# `--classmap-authoritative`, `class_exists()` on a file created after the dump returns false; with `--optimize`
# alone it returns true.] That failure reads as a broken autoloader rather than a stale classmap, which is the
# worst kind. The cost is one filesystem stat per class MISS, and with a full classmap misses are rare.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && composer clear-cache

# ==============================================================================================================
# STAGE 3 — runtime. Derives from `base`, so the extensions are the SAME layer the dependencies resolved against.
# ==============================================================================================================
FROM base AS runtime

# `postgresql-client` for `pg_isready` and for operational access during an incident; `tzdata` so timestamps in
# logs are comparable. The apk lists are dropped in the same layer so they never persist into the image.
RUN apk add --no-cache postgresql18-client tzdata \
    && rm -rf /var/cache/apk/* \
    # THE BUILD-TIME CA IS REVOKED HERE. `runtime` derives from `base`, so it inherits whatever CA the build
    # needed -- and a container that trusts an interception CA at RUN time would accept a man-in-the-middle on
    # every outbound call this product makes, which in Wave 9 means PAYMENT GATEWAYS. Removing the directory and
    # regenerating the store leaves the running container with the distribution's trust set and nothing else.
    # The file still exists in a lower layer; what matters is that no process here can read it as trusted, and
    # `/etc/ssl/certs/ca-certificates.crt` is what PHP, curl and OpenSSL actually consult.
    && rm -rf /usr/local/share/ca-certificates/* \
    && update-ca-certificates

# UTC everywhere in the container. Document dates are a DOMAIN concern resolved through `ClockInterface`, not a
# container setting, so this is only about log timestamps being comparable across replicas.
ENV TZ=UTC

WORKDIR /app

# Configuration BEFORE code, so a php.ini change does not invalidate the application layer.
COPY infra/api/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/api/Caddyfile /etc/frankenphp/Caddyfile

# The finished application tree — dependencies, source and generated autoloader — in ONE copy from the stage that
# has Composer. `.dockerignore` keeps tests, caches and the front-end sources out of the context, so this is what
# serves a request and nothing else.
COPY --from=vendor /app/ ./

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
RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-time-placeholder-not-a-secret \
        DEFAULT_LOCALE=en TRUSTED_PROXIES='' \
        MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=false' \
        LOCK_DSN=flock \
        DATABASE_URL='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        DATABASE_URL_OWNER='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        php bin/console cache:warmup --env=prod --no-debug \
    && chmod -R 555 /app/var/cache

USER root
# NAMED `docker-entrypoint`, which is the FrankenPHP image's and the API Platform distribution's own name
# for this file. It was `twes-entrypoint` until 2026-08-02 — a project-specific name for a conventional
# role, which is exactly the kind of small deviation that makes a familiar stack unfamiliar.
COPY infra/api/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod 555 /usr/local/bin/docker-entrypoint
USER twes

# Defaults matching FrankenPHP's and the API Platform distribution's own variable names, so an operator who knows
# either image can configure this one without reading the Dockerfile. Every one is overridable from `infra/.env`.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # `SymfonyRuntime` is the default; worker mode is selected by swapping this for
    # `Runtime\FrankenPhpSymfony\Runtime`. That seam exists ONLY because `public/index.php` uses
    # `vendor/autoload_runtime.php` -- the hand-rolled bootstrap it replaced made worker mode unreachable.
    APP_RUNTIME="Symfony\\Component\\Runtime\\SymfonyRuntime" \
    # FrankenPHP serves the Symfony front controller from here.
    SERVER_NAME=:80 \
    # The Caddyfile's extension seams, empty so they splice in nothing until an operator sets one.
    CADDY_GLOBAL_OPTIONS="" \
    CADDY_EXTRA_CONFIG="" \
    CADDY_SERVER_EXTRA_DIRECTIVES="" \
    FRANKENPHP_CONFIG=""

EXPOSE 80 443 443/udp

# HEALTHCHECK hits LIVENESS, not readiness, and the distinction is load-bearing. Docker restarts an unhealthy
# container; readiness fails whenever the database is briefly away, so pointing this at `/health/ready` would turn
# a five-second database blip into a restart storm across every replica. Readiness belongs to the orchestrator's
# traffic routing, and `compose.yaml` wires it there.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error --max-time 2 http://127.0.0.1:80/health || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
