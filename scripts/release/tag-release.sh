#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Makes the release tag of HEAD (docs/RUNBOOK.md, « Promote »): an annotated vYYYY.MM.DD.N whose message names the three
# images publish.sh pushed for this commit, by the digests the registry holds now, resolved once. deploy.sh --release
# deploys exactly those, so production runs what staging ran, and a tag pushed is the record of what a version was.
# Tags only a commit master holds, whose three images say they were built from it. Never pushes: the tag goes up on the
# developer's word, with the command it prints.
# Usage: tag-release.sh <registry prefix, such as ghcr.io/owner> <tag>
set -euo pipefail
[[ $# -eq 2 ]] || { echo "Usage: tag-release.sh <registry prefix> <tag vYYYY.MM.DD.N>" >&2; exit 2; }
prefix=${1%/} tag=$2
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "tag-release: refused — $*" >&2; exit 1; }

[[ "$tag" =~ ^v[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$ ]] || refuse "$tag is not a release's name, vYYYY.MM.DD.N"
! git -C "$root" rev-parse -q --verify "refs/tags/$tag" >/dev/null || refuse "$tag exists; a release tag is never moved"
revision=$(git -C "$root" rev-parse HEAD)
git -C "$root" merge-base --is-ancestor "$revision" refs/remotes/origin/master \
  || refuse "origin/master does not hold $revision: only what master published is released (git fetch, then check out a master commit)"

lines=("TWES_REVISION=$revision")
for part in api web database; do
  ref="$prefix/twes-in-$part:sha-$revision"
  docker pull -q "$ref" >/dev/null || refuse "the registry has no $ref: was this commit published (scripts/release/publish.sh)?"
  built=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$ref")
  [[ "$built" == "$revision" ]] || refuse "$ref was built from ${built:-a commit it does not name}, not $revision"
  digest=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" | grep -m1 "^$prefix/twes-in-$part@sha256:") \
    || refuse "the registry gave no digest for $ref"
  lines+=("TWES_${part^^}_IMAGE=$digest")
done

git -C "$root" tag -a "$tag" -F - <<EOF
twes-in $tag

$(printf '%s\n' "${lines[@]}")
EOF
echo "tag-release: $tag made at $revision, naming:"
printf '  %s\n' "${lines[@]:1}"
echo "Push it, once staging has run this commit: git push origin $tag"
