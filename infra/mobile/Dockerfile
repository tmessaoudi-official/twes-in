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
ARG FLUTTER_VERSION=3.44.8
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
RUN flutter build web --release --no-web-resources-cdn

# ==============================================================================================================
# The exported artifact.
# ==============================================================================================================
FROM scratch AS artifact

COPY --from=build /build/build/web/ /
