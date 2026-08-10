# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# syntax=docker/dockerfile:1.7

# ==============================================================================================================
# The Flutter WEB client — a BUILD-ONLY image.
#
# `CLAUDE.md` is explicit that the Flutter web build is a SECOND admin interface alongside `admin/`, deliberately,
# so twes-in ships two of them. This builds that bundle; FrankenPHP serves it from the same origin as the API.
#
# THERE IS NO OFFICIAL FLUTTER IMAGE, so the SDK is downloaded here at a pinned version. That makes this the
# heaviest build stage in the project — and it is a build stage only: `scratch` at the end means none of the SDK
# reaches any running container.
# ==============================================================================================================

# Pinned to the version CLAUDE.md § "Quality gate" records. Debian rather than Alpine because the Flutter SDK
# expects glibc; a musl base fails at `flutter precache` in a way that reads like a network error.
ARG FLUTTER_VERSION=3.44.9
ARG DEBIAN_TAG=bookworm-slim

FROM debian:${DEBIAN_TAG} AS build

ARG FLUTTER_VERSION

# `xz-utils` for the SDK tarball, `git` because the SDK is a git checkout and refuses to run without it, `ca-certificates`
# for the download itself. No recommends, and the apt lists are dropped in the same layer so they never persist.
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        ca-certificates \
        curl \
        git \
        unzip \
        xz-utils \
    && rm -rf /var/lib/apt/lists/*

# ==============================================================================================================
# OPTIONAL SITE CA CERTIFICATES, trusted for the BUILD ONLY — absent from this file until 2026-08-05, which meant
# this tier could not be built behind a TLS-intercepting proxy. The sibling admin tier failed the same way at
# `npm ci` with `SELF_SIGNED_CERT_IN_CHAIN` [Verified]; here the first casualty would be the `curl` of the Flutter
# SDK from `storage.googleapis.com`, and then every `pub` fetch.
#
# The certificate directory is SHARED with the API tier rather than duplicated, because a CA is a property of the
# NETWORK and not of a language toolchain. Empty in a normal checkout (its `*.crt` are gitignored), so this is a
# no-op unless somebody put a certificate there.
#
# PLACED HERE, AFTER `apt-get install ca-certificates` AND BEFORE THE FIRST HTTPS FETCH. Both halves matter. It
# cannot go earlier because `update-ca-certificates` arrives with that package — and it does not deadlock the way it
# would on Alpine, because Debian's `deb.debian.org` sources are plain HTTP, so apt itself needs no trust store.
# It must not go later because the SDK download below is the first thing that needs it.
#
# Unlike the admin tier, `update-ca-certificates` IS the right tool here: Dart and Flutter resolve TLS trust through
# the system store on Linux, so there is no equivalent of Node's separate compiled-in root list to work around.
# `SSL_CERT_FILE` is set as well because Dart honours it explicitly, and being explicit costs nothing.
# ==============================================================================================================
COPY infra/api/ca-certificates/ /usr/local/share/ca-certificates/
RUN rm -f /usr/local/share/ca-certificates/README.md \
    && update-ca-certificates
ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

ENV FLUTTER_HOME=/opt/flutter
ENV PATH="${FLUTTER_HOME}/bin:${PATH}"

# The SDK, at the pinned version. Extracted to a fixed path so the PATH above is stable across versions.
RUN curl -fsSL -o /tmp/flutter.tar.xz \
        "https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_${FLUTTER_VERSION}-stable.tar.xz" \
    && mkdir -p /opt \
    && tar -xJf /tmp/flutter.tar.xz -C /opt \
    && rm /tmp/flutter.tar.xz \
    # The SDK is a git checkout and refuses to operate when it distrusts the directory owner. This is the
    # documented remedy and is scoped to that one path rather than a global `safe.directory *`.
    && git config --global --add safe.directory "${FLUTTER_HOME}" \
    && git config --global --add safe.directory "${FLUTTER_HOME}/.pub-cache" \
    # Web artifacts only. Without this, `precache` pulls every platform's toolchain — Android SDK included — and
    # multiplies the image size for targets this stage does not build.
    && flutter precache --web --no-android --no-ios --no-linux --no-windows --no-macos \
    && flutter config --no-analytics --no-cli-animations

WORKDIR /build

# The manifests first, for the same caching reason as the Angular stage. `pubspec.lock` is copied so `pub get`
# resolves to exactly the pinned versions.
COPY mobile/pubspec.yaml mobile/pubspec.lock ./
RUN flutter pub get

COPY mobile/ ./

# **`--no-web-resources-cdn` IS A GDPR CONTROL, NOT AN OPTIMISATION.** Without it Flutter's engine fetches
# CanvasKit and the Noto fallback fonts from Google's CDN at RUNTIME, which discloses every user's IP address to a
# third party on first paint. `mobile/web/` already carries same-origin controls and `mobile/assets/fonts/` vendors
# the fonts precisely so this flag can be used; two tests in the Flutter suite read `build/web` to prove the bundle
# reaches no external origin.
#
# The ORDER matters too, per CLAUDE.md: those tests SKIP if the bundle is absent, and a skipped GDPR control
# reports success. So the build happens here, and CI runs `flutter test` after it — never before.
# CONFIGURABLE mode, same reasoning as the admin tier: optimisation differs, security does not.
#
#   release — minified, tree-shaken, no observatory. The default.
#   profile — source maps and readable stack traces, still compiled with dart2js so it behaves like the real thing.
#
# `--debug` is deliberately NOT offered: Flutter's web debug build uses a different compiler (DDC rather than
# dart2js) and so can behave differently from what ships, which makes it the wrong thing to hand a developer
# chasing a rendering bug. `profile` is the honest middle.
ARG FLUTTER_BUILD_MODE=release

# `--no-web-resources-cdn` IS IN BOTH MODES AND IS NOT AN OPTIMISATION. It stops the Flutter engine fetching its
# CanvasKit and Noto fallback fonts from Google's CDN at runtime, which is a GDPR control: without it every visitor's
# browser makes a request to `fonts.gstatic.com` / `unpkg.com` carrying their IP. Two tests assert the built bundle
# reaches no external origin. A development bundle that phones home is still phoning home, so this never varies.
# `--base-href` FOR THE SAME REASON AS THE ADMIN TIER, and here the placeholder makes it explicit: `mobile/web/
# index.html` ships `<base href="$FLUTTER_BASE_HREF">`, which `flutter build web` substitutes — defaulting to `/`
# when the flag is absent. The API serves this bundle at `/app/`, so without the flag every asset request goes to the
# origin root, misses, and the Flutter engine never starts: a 200, a correct title, and a blank page.
#
# The sibling defect was reproduced end-to-end on the Angular tier (`/admin/main-*.js` 200 versus `/main-*.js` 404).
# This tier is the same construction and the same Caddy `handle_path` prefix strip; NOT independently reproduced here,
# because a Flutter web build exhausts this development container's disk.
ARG FLUTTER_BASE_HREF=/app/
RUN flutter build web --"${FLUTTER_BUILD_MODE}" --no-web-resources-cdn --base-href "${FLUTTER_BASE_HREF}" \
    && grep -qF "<base href=\"${FLUTTER_BASE_HREF}\">" build/web/index.html \
    && echo "base href is ${FLUTTER_BASE_HREF}"

# ==============================================================================================================
# The exported artifact.
# ==============================================================================================================
FROM scratch AS artifact

COPY --from=build /build/build/web/ /
