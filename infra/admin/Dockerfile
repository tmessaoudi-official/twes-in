# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# syntax=docker/dockerfile:1.7

# ==============================================================================================================
# The Angular admin — a BUILD-ONLY image.
#
# It produces a static bundle and nothing else: there is no server in the final stage, because FrankenPHP serves
# the bundle from the same origin as the API (see infra/api/Caddyfile). That is not a shortcut — a separate web
# server for static files would put the admin on a different origin, forcing CORS and undoing the same-origin
# posture the Flutter build already assumes.
#
# The output is exported to a named stage so `compose.yaml` can copy it into a volume the API container reads. A
# `scratch` final stage keeps the artifact addressable without shipping Node anywhere near production.
# ==============================================================================================================

# PINNED to the versions CLAUDE.md § "Quality gate" records, because Angular 22 refuses to install on the
# container's default Node 22.22.2 -- one patch below its `^22.22.3` floor. A floating `node:lts` would reintroduce
# that failure silently on some future rebuild.
ARG NODE_VERSION=26.5.0

FROM node:${NODE_VERSION}-alpine AS build

# ==============================================================================================================
# OPTIONAL SITE CA CERTIFICATES, trusted for the BUILD ONLY — and ABSENT FROM THIS FILE UNTIL 2026-08-05, which
# meant this tier could not be built behind a TLS-intercepting proxy at all. The API tier has had the mechanism for
# some time; the two front-end tiers did not, so `make build-front` failed on any corporate network with:
#
#   npm error errno SELF_SIGNED_CERT_IN_CHAIN
#   npm error request to https://registry.npmjs.org/... failed, reason: self-signed certificate in certificate chain
#
# [Verified: that exact failure at `npm ci`, in this project's own development container.]
#
# The certificate directory is SHARED with the API tier rather than duplicated per tier, because a CA is a property
# of the NETWORK and not of a language toolchain. It is empty in a normal checkout (its `*.crt` are gitignored —
# trusting a CA is a site-specific decision this repository must not make for a reader), so this is a no-op unless
# somebody put a certificate there.
#
# NO `update-ca-certificates`, AND NO `apk`, WHICH IS THE INTERESTING PART. Two reasons, each verified:
#
#   1. NODE DOES NOT USE THE SYSTEM TRUST STORE. It ships its own compiled-in root list, so populating
#      `/etc/ssl/certs` would change nothing for `npm` — the build would still fail with SELF_SIGNED_CERT_IN_CHAIN
#      having apparently installed the certificate correctly. `NODE_EXTRA_CA_CERTS` is the supported way to ADD to
#      Node's list without replacing it, so the public roots keep working.
#   2. THE TOOL IS NOT THERE AND CANNOT EASILY BE INSTALLED. `node:alpine` ships the certificate BUNDLE but not the
#      `ca-certificates` package that provides `update-ca-certificates` [Verified: exit 127], and `apk add` of it
#      fails with `unable to select packages` [Verified] — while needing the network we are trying to fix. So the
#      file is assembled with `cat`, which needs nothing.
#
# Concatenating the system bundle FIRST guarantees the file is non-empty even when no site certificate exists, which
# matters because `NODE_EXTRA_CA_CERTS` pointing at a missing file makes Node warn on every invocation. The public
# roots appearing twice (once compiled in, once here) is harmless.
# ==============================================================================================================
COPY infra/api/ca-certificates/ /tmp/twes-ca/
RUN cat /etc/ssl/certs/ca-certificates.crt /tmp/twes-ca/*.crt > /usr/local/share/twes-ca.crt 2>/dev/null \
    || cp /etc/ssl/certs/ca-certificates.crt /usr/local/share/twes-ca.crt
ENV NODE_EXTRA_CA_CERTS=/usr/local/share/twes-ca.crt

WORKDIR /build

# Manifests first: this layer is cached until a DEPENDENCY changes rather than until any source file does. Angular
# installs are the slowest part of this build by a wide margin.
COPY admin/package.json admin/package-lock.json ./

# `npm ci` and never `npm install`: it installs exactly what the lock file pins and FAILS if the lock and the
# manifest disagree, which is the property a reproducible build needs. `--ignore-scripts` blocks arbitrary
# postinstall execution from the dependency tree -- a real supply-chain vector, and licensing invariant 8(a) already
# makes us account for every dependency, so running their scripts is a step further than we have accounted for.
RUN npm ci --ignore-scripts --no-audit --no-fund

COPY admin/ ./

# The production build. `--configuration production` is what enables budgets, hashing and optimisation; without it
# a "working" build can ship a development bundle that is an order of magnitude larger and leaks source maps.
# CONFIGURABLE, so one Dockerfile serves both `make build-front` and `make build-front-dev`. Unlike the API tier
# there is no SECURITY delta between a dev and a prod bundle — only optimisation and source maps — so a build
# argument is correct here where the API needed a whole separate target for Xdebug's sake.
#
#   production  — optimised, minified, hashed filenames, no source maps. The default, so a plain build is shippable.
#   development — unminified with source maps, so a stack trace in the browser names OUR file and line instead of
#                 `main-4f2a91.js:1:88231`, and the debugger can step through TypeScript rather than output.
ARG NG_CONFIGURATION=production
RUN npx ng build --configuration "${NG_CONFIGURATION}"

# ==============================================================================================================
# The exported artifact. `scratch` because nothing needs to RUN here -- this stage exists so the bundle has a
# name that compose and CI can copy from.
# ==============================================================================================================
FROM scratch AS artifact

# Angular 22 emits a `browser/` subdirectory under the configured output path. Copying its CONTENTS rather than the
# directory means the served root is the bundle root, so `/admin/index.html` resolves without a redundant path
# segment.
COPY --from=build /build/dist/admin/browser/ /
