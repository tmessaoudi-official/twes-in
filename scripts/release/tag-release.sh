#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Makes the release tag of a commit, HEAD unless one is named (docs/RUNBOOK.md, « Promote »; `make release-tag`): an
# annotated vYYYY.MM.DD.N whose message names the three images publish.sh pushed for that commit, by the digests the
# registry holds now, resolved once. Any commit master holds can be tagged from any checkout: nothing is checked out. deploy.sh --release
# deploys exactly those, so production runs what staging ran, and a tag pushed is the record of what a version was.
# Tags only a commit master holds, or a patch on a release's own branch, whose three images say they were built from it. Never pushes: the tag goes up on the
# developer's word, with the command it prints. The tag is signed: a shop updates only to a tag its pinned key verifies
# (scripts/onprem/twes), so a forged tag on a stolen account names images no shop runs. The key is TWES_RELEASE_KEY,
# else git's user.signingkey. The tag is verified once made, so what it prints is what a shop will check.
# What a release rests on is checked before anything is read or signed: the update rehearsal and onprem-check proved
# this very tree (their markers under var/claude/gates/proofs, keyed on the tree, so a commit re-signed keeps them and
# any change loses them), every commit since the last release tag carries a good signature, and no tag is made in the
# year-end freeze (TWES_RELEASE_FREEZE, YYYY.MM.DD-YYYY.MM.DD) unless it is a hotfix. Each exception says why, in the
# tag's own message: --unrehearsed <why>, --hotfix <why>.
# Usage: tag-release.sh [--unrehearsed <why>] [--hotfix <why>] <registry prefix, such as ghcr.io/owner> <tag> [<commit>]
set -euo pipefail
usage() { echo "Usage: tag-release.sh [--unrehearsed <why>] [--hotfix <why>] <registry prefix> <tag vYYYY.MM.DD.N> [<commit>]" >&2; exit 2; }
unrehearsed='' hotfix=''
while [[ "${1:-}" == --* ]]; do
  case "$1" in
    --unrehearsed) [[ -n "${2:-}" ]] || usage; unrehearsed=$2; shift 2 ;;
    --hotfix) [[ -n "${2:-}" ]] || usage; hotfix=$2; shift 2 ;;
    *) usage ;;
  esac
done
[[ $# -eq 2 || $# -eq 3 ]] || usage
prefix=${1%/} tag=$2 commit=${3:-HEAD}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "tag-release: refused — $*" >&2; exit 1; }
freeze=${TWES_RELEASE_FREEZE:-2026.12.20-2027.01.10}

[[ "$tag" =~ ^v[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$ ]] || refuse "$tag is not a release's name, vYYYY.MM.DD.N"
! git -C "$root" rev-parse -q --verify "refs/tags/$tag" >/dev/null || refuse "$tag exists; a release tag is never moved"
key=${TWES_RELEASE_KEY:-$(git -C "$root" config --get user.signingkey || true)}
[[ -n "$key" ]] || refuse "no signing key: set git's user.signingkey, or TWES_RELEASE_KEY, to the key shops have pinned"
# A release is a tag signed with the key the shops pin (infra/onprem/release-key.asc); a tag signed with any other is no
# release a shop takes: a rehearsal's (update-rehearsal.sh, onprem-check.sh, each with a key of its own run). What a
# release rests on (the signatures, the proofs, the freeze) is asked of the first alone.
pinned_file=$root/infra/onprem/release-key.asc
[[ -s "$pinned_file" ]] || refuse "no infra/onprem/release-key.asc: nothing tells a release from a rehearsal"
pinned=$(home=$(mktemp -d) && GNUPGHOME=$home gpg --batch --quiet --import "$pinned_file" 2>/dev/null \
  && GNUPGHOME=$home gpg --batch --with-colons --list-keys | awk -F: '$1 == "fpr" { print $10 }'; GNUPGHOME=$home gpgconf --kill all 2>/dev/null; rm -rf "$home")
# A key this keyring does not hold lists nothing and fails: no fingerprint, so no release; git's signing refuses it below.
signing=$(gpg --batch --with-colons --list-secret-keys "$key" 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }') || signing=
is_release=no
[[ -n "$signing" ]] && grep -qxF -e "$signing" <<<"$pinned" && is_release=yes
revision=$(git -C "$root" rev-parse -q --verify "$commit^{commit}") || refuse "$commit names no commit of this clone (git fetch first)"
# Master's lane, or the maintenance lane: a patch for the shops while master carries work not ready to ship, on
# release/<YYYY.MM.DD> grown from that day's release tag, the patch tag dated after it (docs/RUNBOOK.md « Patch a release »).
lane=master base=''
if ! git -C "$root" merge-base --is-ancestor "$revision" refs/remotes/origin/master; then
  lane=''
  while IFS= read -r branch; do
    day=${branch#refs/remotes/origin/release/}
    [[ "$day" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}$ ]] || continue
    git -C "$root" merge-base --is-ancestor "$revision" "$branch" || continue
    base=$(git -C "$root" tag --list "v$day.*" --merged "$revision" --sort=-v:refname | head -n 1)
    [[ -n "$base" ]] || continue
    lane="release/$day"; break
  done < <(git -C "$root" for-each-ref --format='%(refname)' refs/remotes/origin/release/)
  [[ -n "$lane" ]] || refuse "origin/master does not hold $revision, nor does a release/<YYYY.MM.DD> branch grown from that day's release tag: only what master or a release's own branch published is released (git fetch, then check out such a commit)"
  [[ "${tag:1:10}" > "${base:1:10}" ]] || refuse "$tag patches $base on $lane: a patch tag is dated after $base, so every shop's newest tag is the patch"
  # Shops take the newest tag by name (scripts/onprem/twes): a release made since the base that the patch does not hold
  # would be left for the patch, older code on its newer schema. A patch is for the shops still on its base alone.
  newer=''
  while IFS= read -r other; do
    [[ "$other" == "$base" ]] && break
    git -C "$root" merge-base --is-ancestor "refs/tags/$other" "$revision" || { newer=$other; break; }
  done < <(git -C "$root" tag --list 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' --sort=-v:refname)
  [[ -z "$newer" ]] || refuse "$newer was released after $base and $lane does not hold it: shops on $newer would take the patch and go back to older code; patch master instead, and tag it from there"
  echo "tag-release: maintenance lane $lane from $base" >&2
fi

if [[ "$is_release" == yes ]]; then
# The freeze: by the tag's own date and by today's, so a tag named outside it is not made inside it either.
[[ "$freeze" =~ ^([0-9]{4}\.[0-9]{2}\.[0-9]{2})-([0-9]{4}\.[0-9]{2}\.[0-9]{2})$ ]] || refuse "TWES_RELEASE_FREEZE=$freeze is not YYYY.MM.DD-YYYY.MM.DD"
from=${BASH_REMATCH[1]} to=${BASH_REMATCH[2]}
today=${TWES_TODAY:-$(date +%Y.%m.%d)}
for day in "${tag:1:10}" "$today"; do
  if [[ ! "$day" < "$from" && ! "$day" > "$to" && -z "$hotfix" ]]; then
    refuse "$day falls in the release freeze, $from to $to: only a hotfix is tagged then, with --hotfix <why>"
  fi
done

# Signed: a commit on master without the developer's good signature is a way round the signing the shops rely on.
last=$(git -C "$root" describe --tags --abbrev=0 --match 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' "$revision" 2>/dev/null || true)
unsigned=$(git -C "$root" log --format='%h %G?' "${last:+$last..}$revision" | awk '$2 != "G" { print $1 }' | tr '\n' ' ')
[[ -z "$unsigned" ]] || refuse "unsigned or unverified commits since ${last:-the first commit}: $unsigned(git log --format='%h %G? %s' to read them)"

# Proved: the rehearsal on the shop's own files and onprem-check, each on this very tree.
tree=$(git -C "$root" rev-parse "$revision^{tree}")
missing=()
[[ -f "$root/var/claude/gates/proofs/rehearsal-$tree.ok" ]] || missing+=("the update rehearsal (make update-rehearsal FILES=… TO=$revision)")
[[ -f "$root/var/claude/gates/proofs/onprem-$tree.ok" ]] || missing+=("onprem-check (make onprem-check on a clean checkout of $revision)")
if ((${#missing[@]})) && [[ -z "$unrehearsed" ]]; then
  refuse "tree ${tree:0:12} of $revision was not proved by $(IFS=';'; echo "${missing[*]}" | sed 's/;/ nor by /'): run them, or say why with --unrehearsed <why>"
fi
else
  echo "tag-release: ${signing:-$key} is not the release key the shops pin: a rehearsal's tag, no shop takes it, so the signatures, the proofs and the freeze are not asked" >&2
fi

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
  # The answer is its last line: a warning docker prints on stderr comes before it.
  read -r digest built <<<"$(tail -n 1 <<<"$read_out")"
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || refuse "the registry gave no digest for $ref"
  [[ "${built:-}" == "$revision" ]] || refuse "$ref was built from ${built:-a commit it does not name}, not $revision"
  lines+=("TWES_${part^^}_IMAGE=$prefix/twes-in-$part@$digest")
done

[[ "$is_release" == yes ]] || lines+=("REHEARSAL=signed with ${signing:-$key}, not the pinned release key")
[[ -z "$unrehearsed" ]] || lines+=("UNREHEARSED=$unrehearsed")
[[ -z "$hotfix" ]] || lines+=("HOTFIX=$hotfix")
git -C "$root" tag -s -u "$key" "$tag" "$revision" -F - <<EOF || refuse "git could not sign $tag with $key: is that key in this keyring, and its agent answering?"
twes-in $tag

$(printf '%s\n' "${lines[@]}")
EOF
git -C "$root" verify-tag "$tag" >/dev/null 2>&1 || { git -C "$root" tag -d "$tag" >/dev/null; refuse "$tag did not verify once signed, and was removed"; }
echo "tag-release: $tag made and verified at $revision${base:+ on $lane from $base}, naming:"
printf '  %s\n' "${lines[@]:1}"
# Staging follows master's :master image alone (publish.sh --no-master leaves it for a lane): a patch rests on the
# shops' proofs read above instead, and on CI's run of its branch.
if [[ -n "$base" ]]; then
  echo "Push it, once CI is green on $lane (staging follows master, never a patch): git push origin $tag"
else
  echo "Push it, once staging has run this commit: git push origin $tag"
fi
