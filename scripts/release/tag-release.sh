#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Makes the release tag of a commit, HEAD unless one is named (docs/RUNBOOK.md, « Promote »; `make release-tag`): an
# annotated vYYYY.MM.DD.N whose message names the three images publish.sh pushed for that commit, by the digests the
# registry holds now, resolved once. Any commit master holds can be tagged from any checkout: nothing is checked out. deploy.sh --release
# deploys exactly those, so production runs what staging ran, and a tag pushed is the record of what a version was.
# Tags only a commit master holds, whose three images say they were built from it. Never pushes: the tag goes up on the
# developer's word, with the command it prints. The tag is signed: a shop updates only to a tag its pinned key verifies
# (scripts/onprem/twes), so a forged tag on a stolen account names images no shop runs. The key is TWES_RELEASE_KEY,
# else git's user.signingkey. The tag is verified once made, so what it prints is what a shop will check.
# Usage: tag-release.sh <registry prefix, such as ghcr.io/owner> <tag> [<commit, HEAD by default>]
set -euo pipefail
[[ $# -eq 2 || $# -eq 3 ]] || { echo "Usage: tag-release.sh <registry prefix> <tag vYYYY.MM.DD.N> [<commit>]" >&2; exit 2; }
prefix=${1%/} tag=$2 commit=${3:-HEAD}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "tag-release: refused — $*" >&2; exit 1; }

[[ "$tag" =~ ^v[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$ ]] || refuse "$tag is not a release's name, vYYYY.MM.DD.N"
! git -C "$root" rev-parse -q --verify "refs/tags/$tag" >/dev/null || refuse "$tag exists; a release tag is never moved"
key=${TWES_RELEASE_KEY:-$(git -C "$root" config --get user.signingkey || true)}
[[ -n "$key" ]] || refuse "no signing key: set git's user.signingkey, or TWES_RELEASE_KEY, to the key shops have pinned"
revision=$(git -C "$root" rev-parse -q --verify "$commit^{commit}") || refuse "$commit names no commit of this clone (git fetch first)"
git -C "$root" merge-base --is-ancestor "$revision" refs/remotes/origin/master \
  || refuse "origin/master does not hold $revision: only what master published is released (git fetch, then check out a master commit)"

# Each image's label and digest are read from the registry itself, its manifest and its configuration, never by pulling
# the image: three pulls took minutes for two lines of text. The digest is the one a pull would record, the index's.
# `.Image` is one configuration while publish.sh builds for one platform; built for several, it becomes a map by platform
# and this template fails, which refuses the tag rather than reading the wrong one.
docker buildx version >/dev/null 2>&1 || refuse "docker buildx is needed to read the images from the registry: install Docker's buildx plugin"
lines=("TWES_REVISION=$revision")
parts=(api web database)
for i in "${!parts[@]}"; do
  part=${parts[$i]}
  ref="$prefix/twes-in-$part:sha-$revision"
  next=${parts[$((i + 1))]:-}
  echo "tag-release: [$((i + 1))/${#parts[@]}] twes-in-$part:sha-${revision:0:12} read from the registry (usually a few seconds)${next:+, next: twes-in-$next}" >&2
  read_out=$(docker buildx imagetools inspect "$ref" \
    --format '{{.Manifest.Digest}} {{index .Image.Config.Labels "org.opencontainers.image.revision"}}' 2>&1) \
    || refuse "the registry has no $ref (docker: $(tr '\n' ' ' <<<"$read_out" | sed 's/ *$//')): was this commit published (scripts/release/publish.sh)?"
  read -r digest built <<<"$read_out"
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || refuse "the registry gave no digest for $ref"
  [[ "${built:-}" == "$revision" ]] || refuse "$ref was built from ${built:-a commit it does not name}, not $revision"
  lines+=("TWES_${part^^}_IMAGE=$prefix/twes-in-$part@$digest")
done

git -C "$root" tag -s -u "$key" "$tag" "$revision" -F - <<EOF || refuse "git could not sign $tag with $key: is that key in this keyring, and its agent answering?"
twes-in $tag

$(printf '%s\n' "${lines[@]}")
EOF
git -C "$root" verify-tag "$tag" >/dev/null 2>&1 || { git -C "$root" tag -d "$tag" >/dev/null; refuse "$tag did not verify once signed, and was removed"; }
echo "tag-release: $tag made and verified at $revision, naming:"
printf '  %s\n' "${lines[@]:1}"
echo "Push it, once staging has run this commit: git push origin $tag"
