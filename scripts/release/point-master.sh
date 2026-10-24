#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Points the three :master images at a commit's own, by hand (docs/RUNBOOK.md, « Point :master »; `make point-master`).
# publish.sh moves :master only forward, which keeps a late run for an older commit from moving it back; a history
# rewritten under it (the re-signing of 2026-10-24) leaves it on a commit the new master does not follow, where it stays.
# This is the deliberate move, either way: staging follows :master, shops never read it. Nothing is built or pulled
# into a running stack: the registry copies each per-commit image CI pushed onto :master. Only a commit master holds,
# whose three images all exist and say they were built from it; none moves unless all three can.
# Needs `docker login ghcr.io` with write:packages.
# Usage: point-master.sh <registry prefix, such as ghcr.io/owner> <commit>
set -euo pipefail
[[ $# -eq 2 ]] || { echo "Usage: point-master.sh <registry prefix> <commit>" >&2; exit 2; }
prefix=${1%/} commit=$2
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "point-master: refused — $*" >&2; exit 1; }

revision=$(git -C "$root" rev-parse -q --verify "$commit^{commit}") || refuse "$commit names no commit of this clone (git fetch first)"
git -C "$root" merge-base --is-ancestor "$revision" refs/remotes/origin/master \
  || refuse "origin/master does not hold $revision: only what master published is pointed at (git fetch first)"

parts=(api web database)
built_from() { docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$1"; }
for part in "${parts[@]}"; do
  ref="$prefix/twes-in-$part:sha-$revision"
  docker pull -q "$ref" >/dev/null || refuse "the registry has no $ref: was this commit published (scripts/release/publish.sh)?"
  built=$(built_from "$ref")
  [[ "$built" == "$revision" ]] || refuse "$ref was built from ${built:-a commit it does not name}, not $revision"
done

before=unknown
docker pull -q "$prefix/twes-in-api:master" >/dev/null 2>&1 && before=$(built_from "$prefix/twes-in-api:master")
for part in "${parts[@]}"; do
  docker buildx imagetools create -t "$prefix/twes-in-$part:master" "$prefix/twes-in-$part:sha-$revision" >/dev/null \
    || refuse "the registry did not take :master for $part (logged in with write:packages?); parts before it moved"
done
echo "point-master: :master moved from ${before:-a commit it does not name} to $revision (api, web, database)"
