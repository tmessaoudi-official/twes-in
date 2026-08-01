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
RUN npx ng build --configuration production

# ==============================================================================================================
# The exported artifact. `scratch` because nothing needs to RUN here -- this stage exists so the bundle has a
# name that compose and CI can copy from.
# ==============================================================================================================
FROM scratch AS artifact

# Angular 22 emits a `browser/` subdirectory under the configured output path. Copying its CONTENTS rather than the
# directory means the served root is the bundle root, so `/admin/index.html` resolves without a redundant path
# segment.
COPY --from=build /build/dist/admin/browser/ /
