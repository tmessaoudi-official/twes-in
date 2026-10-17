# SPDX-License-Identifier: AGPL-3.0-or-later
# The database of the backup overlay (compose.backup.yaml, docs/START.md § 13): the very server compose.yaml runs, plus
# what archives it off-site, the schedule that copies it and what tells the monitor each step went through. pgBackRest
# (MIT), restic (BSD-2-Clause) and curl (curl, MIT-style) come signed from the apt sources the base image already trusts;
# supercronic (MIT) is checked against the sha256 of its release asset, whose sha1 its release notes publish. Each pin
# and its copies: docs/UPDATE.md.

# One asset per architecture, each against its own checksum, and only the one this image runs on is fetched: the stage
# the final one copies from is named after the target, so the other is never built.
ARG TARGETARCH
FROM scratch AS supercronic-amd64
ADD --checksum=sha256:d2d7c97fbfefc763d5bbcbbb3f8d61f9dd3410ce5d05a737c414994b80b8cdd0 \
    https://github.com/aptible/supercronic/releases/download/v0.2.50/supercronic-linux-amd64 /supercronic
FROM scratch AS supercronic-arm64
ADD --checksum=sha256:a1c0af9872e8de89a1ce5c1b596e26c42917d5a2cf914d98faee2e28156899e2 \
    https://github.com/aptible/supercronic/releases/download/v0.2.50/supercronic-linux-arm64 /supercronic
FROM supercronic-${TARGETARCH} AS supercronic

FROM postgres:18.6-trixie

ARG PGBACKREST_VERSION=2.59.3-1.pgdg13+1
ARG RESTIC_VERSION=0.18.0-1+b4
ARG CURL_VERSION=8.14.1-2+deb13u7

RUN apt-get update \
 && apt-get install -y --no-install-recommends "pgbackrest=${PGBACKREST_VERSION}" "restic=${RESTIC_VERSION}" "curl=${CURL_VERSION}" ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && install -d -o postgres -g postgres -m 0750 /var/spool/pgbackrest /var/lib/twes-backup /var/lib/twes-backup/dump

COPY --from=supercronic --chmod=0755 /supercronic /usr/local/bin/supercronic

COPY pgbackrest.conf /etc/pgbackrest/pgbackrest.conf
COPY backup/twes-backup /usr/local/bin/twes-backup
COPY backup/crontab /etc/twes-backup/crontab
COPY backup/crontab.due /etc/twes-backup/crontab.due
