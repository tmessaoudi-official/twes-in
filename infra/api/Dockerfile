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

# THE BUNDLE ASSETS, generated here rather than copied from the build context. `assets:install` publishes each
# bundle's public directory into `public/bundles/` — for us that is API Platform's Swagger UI css, js and fonts, which
# the HTML documentation page at `/api` loads through `asset()`. Without this the page renders with NO STYLESHEET: a
# 200, a working document, and something that looks broken.
#
# COPIED, NOT SYMLINKED (`--symlink` is absent deliberately): the `vendor/` tree these would point at is in this
# stage, and the runtime stage takes `/app/` wholesale — a relative symlink would survive that, but a copy cannot be
# broken by a later `COPY` reordering, and `public/` is `chmod 555` in the runtime image where a dangling link would
# be silent. `api/public/bundles` is in `.dockerignore`, so the host's copy can never leak in and mask this.
RUN APP_ENV=prod php bin/console assets:install public --no-interaction

# ==============================================================================================================
# STAGE 3 — prod. Derives from `base`, so the extensions are the SAME layer the dependencies resolved against.
#
# NAMED `prod`, NOT `runtime`, SINCE 2026-08-05. The old pair was `runtime` and `dev` — one named a ROLE and the other
# an ENVIRONMENT, so the two stages could not be read as a pair and `target: runtime` in compose did not visibly
# correspond to `target: dev` in the overlay. `prod`/`dev` is the same vocabulary the Makefile and the compose files
# now use everywhere.
# ==============================================================================================================
FROM base AS prod

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
#
# WHOLESALE, so a new production ini file is picked up without editing this line — which is only safe because the
# DEVELOPMENT overlay lives OUTSIDE this directory, in `infra/api/conf.d-dev/`. It sat in `conf.d/dev/` until
# 2026-08-04, and that shipped `display_errors=On` and `zend.exception_ignore_args=Off` into the production image.
# PHP happened not to read them — it scans this directory for `*.ini` and does not recurse — so the file was inert
# rather than active, but "the production image contains a file that disables production hardening, and only a
# scanning quirk stops it applying" is not a property worth relying on.
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
#
# **`-d opcache.preload=` IS LOAD-BEARING, AND WITHOUT IT THIS BUILD CANNOT COMPLETE.** `conf.d/10-opcache.ini` is
# copied above and names `var/cache/prod/Twes_KernelProdContainer.preload.php` — a file THIS COMMAND GENERATES. With
# `opcache.enable_cli=1`, preloading runs at engine startup for every `php` invocation, so the command that creates
# the preload script could not start without the preload script:
#
#   PHP Fatal error:  Failed opening required '/app/var/cache/prod/Twes_KernelProdContainer.preload.php' in Unknown
#
# [Verified: that is the verbatim build failure without this flag, and `-d opcache.preload=` clears it.] It is the
# same BOOTSTRAP DEADLOCK shape as the `apk add ca-certificates` note in the `base` stage above — a step that cannot
# run before the thing it produces exists — which is why it is worth naming rather than just fixing.
#
# Only the ONE directive is dropped, deliberately, rather than deferring the whole `conf.d` copy until after this
# step. `20-app.ini` must be in force WHILE the cache is written, because `bcmath.scale=0` and
# `serialize_precision=17` change the CONTENT of what gets serialised into it — warming under different ini values
# than the runtime then uses would bake a subtly different cache into the image.
#
# A future build-time `php` line that forgets this flag fails the BUILD, loudly and immediately, exactly as this did.
# That is why the flag is acceptable here where this project normally prefers a structural guard: the failure mode
# cannot reach production silently.
USER twes
RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-time-placeholder-not-a-secret \
        DEFAULT_LOCALE=en TRUSTED_PROXIES='' \
        MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=false' \
        LOCK_DSN=flock \
        DATABASE_URL='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        DATABASE_URL_OWNER='postgresql://build:build@127.0.0.1:5432/build?serverVersion=18&charset=utf8' \
        php -d opcache.preload= bin/console cache:warmup --env=prod --no-debug \
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
    # `SymfonyRuntime` is the default, and it is the ONLY permitted value: worker mode WOULD be selected by
    # swapping this for `Runtime\FrankenPhpSymfony\Runtime`, and `scripts/gates/compose-config.sh` refuses that
    # until the client portal (Wave 10) has its own `random_bytes(32)` token -- `UuidV7` seeds its generator state
    # once per PROCESS, so a worker serving many requests lets a recoverable seed span TENANTS (Gotchas
    # 2026-08-05). The seam exists at all only because `public/index.php` uses `vendor/autoload_runtime.php` --
    # the hand-rolled bootstrap it replaced made worker mode unreachable.
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

# ==============================================================================================================
# STAGE 4 — dev. A SEPARATE IMAGE, not the production one with flags flipped.
#
# WHY A SEPARATE TARGET AT ALL. The two images are optimised for different things and the difference is not a
# matter of degree: dev optimises the DEVELOPER'S LOOP (edit, breakpoint, inspect, re-run) and prod optimises THE
# REQUEST (throughput, and the smallest surface an attacker who reaches PHP execution can act on). A single image
# switched by environment variable cannot satisfy both, for one decisive reason: it would have to CONTAIN Xdebug.
# A debugger present in a production image is a remote-code-execution amplifier — `xdebug.mode` is settable from
# any `.ini` a compromised process can write, and Xdebug can be told to connect OUT to an attacker's host. The
# only safe way to not have that is to not ship it, which means two images.
#
# Note what this stage does NOT do, because each absence is a positive dev property rather than a degradation:
#   - no warm prod cache      — the source mount hides `/app` anyway, and dev recompiles per edit
#   - no `chmod 555`          — the framework must WRITE its compiled container on every change
#   - no opcache preload      — a preload script pins a compiled container; in dev that is the opposite of wanted
#   - no `--no-dev` install   — the test suites and `browser-kit` are the point of a dev container
# ==============================================================================================================
FROM base AS dev

# `git` and `unzip` for Composer, `postgresql18-client` for `psql` during a debugging session, `tzdata` for
# comparable log timestamps. Composer itself is HERE and deliberately NOT in the production image.
RUN apk add --no-cache git unzip postgresql18-client tzdata

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# ==============================================================================================================
# XDEBUG. The reason this stage exists, and the reason it must never be the production one.
#
# `install-php-extensions` builds it against this exact PHP, which is why it is here rather than an `apk` package.
#
# PINNED, and FETCHED WITH `curl` RATHER THAN BY `pecl`. Two independent reasons, and the second is why this is not
# just consistency with the rest of this file:
#
#   1. Everything else here is pinned to an exact version — the base image, Node, Flutter, the tool phars. An
#      unpinned `install-php-extensions xdebug` resolves whatever PECL calls current at build time, so two builds of
#      the same commit can carry different debuggers.
#   2. `pecl`'s own HTTP client cannot get through some proxies. In this project's development container it receives
#      `HTTP/1.1 426 Upgrade Required` and the build dies with `cannot download "pecl/xdebug"` — while `curl` to the
#      SAME URL returns 200. [Verified: both, in one build.] Corporate TLS-intercepting proxies are exactly the
#      environment this file already accommodates for `apk` and Composer, so accommodating it here too is consistent
#      rather than special-cased.
#
# (An earlier revision used `install-php-extensions` on the downloaded file. That paragraph described an approach
# abandoned three lines later, and is deleted rather than left standing above the text contradicting it — see the
# `pecl install` note below for what actually happens.)
# `linux-headers` EXPLICITLY, and this is the one cost of fetching the tarball ourselves. Given a package NAME,
# `install-php-extensions` looks up that extension's build requirements and adds them; given a local FILE it has no
# name to look up, so xdebug's need for `<linux/rtnetlink.h>` goes unmet and configure fails with
# `rtnetlink.h is required, install the linux-headers package`. [Verified: that exact error, on the build before this
# line existed.] `--virtual` so the whole set comes back out in the same layer and never reaches the final image.
# `pecl install` DIRECTLY, not `install-php-extensions`. The latter compiles the local tarball perfectly and then
# fails on its LAST step — `Unable to find the file of the PHP extension "/tmp/xdebug.tgz"` — because it derives the
# extension's NAME from its argument in order to write an ini, and a file path is not a name. [Verified: the build
# logged `Build process completed successfully`, `Installing '.../xdebug.so'` and `install ok:
# channel://pecl.php.net/xdebug-3.5.3`, and then exited 1 on that message.] Nothing is lost by dropping it, because
# the only thing it would have added is the `zend_extension` line — and `conf.d-dev/60-xdebug.ini` below carries
# that, along with the whole configuration, where it is reviewable.
# `$PHPIZE_DEPS` is the php-docker image family's OWN variable for "the toolchain needed to compile an extension"
# (autoconf, gcc, g++, make, libc-dev, pkgconf, re2c, file, dpkg-dev), so using it is the ecosystem's spelling rather
# than a hand-written package list that would drift from the base image. `install-php-extensions` was installing this
# set for us; taking over the download means taking over the toolchain too, and `phpize` fails without it.
#
# `linux-headers` ADDITIONALLY, because xdebug specifically needs `<linux/rtnetlink.h>` and it is not in `PHPIZE_DEPS`.
# Given a package NAME, `install-php-extensions` looks up that requirement; given a local FILE it has no name to look
# up. [Verified: `configure: error: rtnetlink.h is required` without this, and `phpize' failed` without PHPIZE_DEPS.]
#
# `--virtual` + `apk del` so the entire compiler comes back out in the SAME layer. It must not persist: a C compiler
# in a container is a large step up in what an attacker who reaches code execution can do, and this image already
# accepts more risk than the production one by carrying a debugger at all.
ARG XDEBUG_VERSION=3.5.3
RUN apk add --no-cache --virtual .xdebug-build-deps $PHPIZE_DEPS linux-headers \
    && curl -fsSL -o /tmp/xdebug.tgz "https://pecl.php.net/get/xdebug-${XDEBUG_VERSION}.tgz" \
    && pecl install /tmp/xdebug.tgz \
    && rm -rf /tmp/xdebug.tgz /tmp/pear \
    && apk del --no-network .xdebug-build-deps

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app

# The DEV php configuration: the production `conf.d` plus the dev overlay plus Xdebug, all as FLAT `.ini` files.
#
# FLAT, and that is load-bearing rather than stylistic: PHP selects scan-directory entries by the `.ini` SUFFIX and
# does NOT recurse, so a config mounted or copied as a subdirectory is silently never read. That was a real defect
# here on 2026-08-04 — the entire dev overlay was inert because compose mounted it at `conf.d/zz-dev` as a
# directory. `zz-` prefixes so these sort AFTER `10-`/`20-` and genuinely override them.
COPY infra/api/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/api/conf.d-dev/50-dev.ini /usr/local/etc/php/conf.d/zz-50-dev.ini
COPY infra/api/conf.d-dev/60-xdebug.ini /usr/local/etc/php/conf.d/zz-60-xdebug.ini
COPY infra/api/Caddyfile /etc/frankenphp/Caddyfile

# PROVE THE DEBUGGER IS ACTUALLY THERE, and prove it HERE rather than beside the compile — the `.so` being installed
# and the extension being LOADED are two different facts, and only the ini copied just above turns the first into the
# second. A dev image whose debugger is silently absent is worse than a failed build: the developer loses an
# afternoon to IDE settings for a problem that was never in the IDE.
#
# `XDEBUG_MODE=off` is passed for the check because that is the image's default and the value a developer will meet;
# it proves the extension loads in the cheap mode rather than only in `debug`.
RUN XDEBUG_MODE=off php -m | grep -qi '^xdebug$' \
    && echo "xdebug $(XDEBUG_MODE=off php -r 'echo phpversion("xdebug");') present and loading"

# The application, so the image is usable on its own; in practice `compose.override.yaml` mounts the host tree over
# it, which is what lets an IDE resolve every class it steps into.
COPY api/ ./

# ==============================================================================================================
# THE CONTAINER RUNS AS THE HOST DEVELOPER'S OWN uid/gid, and this is the single most important line in the dev
# image for day-to-day usability.
#
# The production image runs as a fixed `twes` (10001), which is right there: nothing is bind-mounted, so the uid is
# private to the container. In dev the WHOLE `api/` TREE IS BIND-MOUNTED, and a bind mount does not translate
# ownership — the container sees the host's numeric owner, and the host sees the container's. So a fixed uid
# produces one of two failures, depending on which way the mismatch falls:
#
#   - container uid ≠ host owner  →  `/app/vendor does not exist and could not be created`, and `make install`
#     cannot write at all. [Verified: exactly that, with the image fixed at 10001 against a host tree owned by
#     another uid.]
#   - container writes as root    →  `vendor/`, `var/` and anything `make:entity` generates come out root-owned in
#     the developer's own working copy, and their editor cannot save over them.
#
# Passing the host's ids at build time avoids both. `TWES_UID`/`TWES_GID` default to 1000 — the first interactive
# user on essentially every Linux distribution — and the Makefile passes the REAL `id -u`/`id -g` so it is correct
# even when it is not 1000.
#
# `USER` IS NUMERIC ON PURPOSE. Docker accepts a bare uid:gid whether or not a matching passwd entry exists, which
# is what makes this work for ANY value — including 0, when the developer's environment is itself root (this
# project's own cloud container is). A named `USER twes` could not express that, and `adduser -u 0` fails outright
# because the id is taken. The passwd entry is still created when the id is free, because a few tools print
# warnings without one; it is not required for correctness.
# ==============================================================================================================
# NO `assets:install` IN THIS STAGE, AND THE ATTEMPT TO ADD ONE FAILED THE BUILD — worth recording, because the
# reason is the whole shape of the dev image. This stage never runs `composer install`: `vendor/` comes from the
# bind-mounted host tree, which is what lets an IDE resolve it. So there is no `vendor/autoload_runtime.php` here and
# `bin/console` cannot run AT ALL at build time. [Verified: the build died on exactly that, pointing at the
# `composer install` note in `vendor/.gitignore`.]
#
# The dev tree's `public/bundles/` is therefore generated on the HOST, by `make install` — the same target that
# installs vendor, for the same reason, in the same place. `make up` depends on it through `deps`.

ARG TWES_UID=1000
ARG TWES_GID=1000
RUN if ! getent group "${TWES_GID}" >/dev/null 2>&1; then addgroup -g "${TWES_GID}" -S twes; fi \
    && if ! getent passwd "${TWES_UID}" >/dev/null 2>&1; then \
           adduser -u "${TWES_UID}" -S -G "$(getent group "${TWES_GID}" | cut -d: -f1)" -H -s /sbin/nologin twes; \
       fi \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /app/var/cache /app/var/log \
    && chown -R "${TWES_UID}:${TWES_GID}" /app/var \
    # COMPOSER_HOME must be writable by that uid too, or `composer install` dies on its cache directory rather than
    # on anything to do with the project.
    && mkdir -p /tmp/composer \
    && chown -R "${TWES_UID}:${TWES_GID}" /tmp/composer

COPY infra/api/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod 555 /usr/local/bin/docker-entrypoint

USER ${TWES_UID}:${TWES_GID}

# `APP_ENV=dev` and `APP_DEBUG=1` as the image's own default, so a container started from this image without an
# environment is a DEV container. The production image defaults the other way. Neither can be mistaken for the
# other by forgetting a variable, which is the failure this whole split exists to prevent.
ENV APP_ENV=dev \
    APP_DEBUG=1 \
    APP_RUNTIME="Symfony\\Component\\Runtime\\SymfonyRuntime" \
    SERVER_NAME=:80 \
    # OFF BY DEFAULT, and this is the setting that makes Xdebug usable rather than something to be disabled and
    # forgotten. `xdebug.mode=debug` costs 2-5x on EVERY request even with no debugger attached; `off` costs
    # nothing, and `make debug-on` flips it for the session. See conf.d-dev/60-xdebug.ini.
    XDEBUG_MODE=off \
    # The trigger secret. Without a value ANY `XDEBUG_TRIGGER` arms a debug session, which is a drive-by RCE path
    # once a developer has run `make debug-on` -- see conf.d-dev/60-xdebug.ini. Overridable per machine.
    TWES_XDEBUG_TRIGGER=twes-local-trigger \
    # Bounded by default; `make debug-on` sets 0 so a held breakpoint is not killed. See conf.d-dev/50-dev.ini.
    TWES_MAX_EXECUTION_TIME=120 \
    CADDY_GLOBAL_OPTIONS="" \
    CADDY_EXTRA_CONFIG="" \
    CADDY_SERVER_EXTRA_DIRECTIVES="" \
    FRANKENPHP_CONFIG=""

EXPOSE 80 443 443/udp

# `HEALTHCHECK NONE`, EXPLICITLY — AND THE COMMENT HERE PREVIOUSLY CLAIMED "NO HEALTHCHECK, deliberately" WHILE ONE
# WAS ACTIVE AND FAILING. Two false statements in one comment, both found by a certification reviewer:
#
#   - There WAS a healthcheck. `dunglas/frankenphp` defines its own — `curl -f http://localhost:2019/metrics` — and
#     `FROM base AS dev` inherits it. `worker` and `scheduler` run `messenger:consume`, not `frankenphp`, so nothing
#     listens on Caddy's admin port and both sat permanently `unhealthy`. [Verified: `docker inspect` reported
#     `Health=unhealthy FailingStreak=50` on both, in a stack I had just described as healthy.]
#   - The reason given was also wrong. "Docker restarting it underneath a debugger session" does not happen: plain
#     Docker does NOT restart on an unhealthy check — only Swarm acts on it. [Verified: `RestartCount=0` after a
#     failing streak of 50, with `restart: unless-stopped`.]
#
# `NONE` is still the right answer for dev, but for the honest reason: a dev container is *expected* to be broken
# mid-edit, and a red health status that means nothing trains the developer to ignore the column. Production sets a
# real one on the `runtime` stage, where it gates traffic.
HEALTHCHECK NONE
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
